<?php

namespace App\Http\Controllers;

use App\Models\AdoptionApplication;
use App\Models\Animal;
use App\Models\CategoryOption;
use App\Models\MedicalRecord;
use App\Models\VolunteerApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * The admin-managed lists (see CategoryOption): anyone can read them, since the forms need them;
 * only admins add, rename, hide/restore or delete. Nothing a record uses is ever deleted — it can
 * only be hidden, which takes it off the dropdowns while the records that have it keep it.
 */
class CategoryOptionController extends Controller
{
    /** What the usage count is counting, per list. */
    private const USED_BY = [
        'dog_breed' => 'animal',
        'cat_breed' => 'animal',
        'behavioral_issue' => 'animal',
        'medical_record_type' => 'medical record',
        'valid_id_type' => 'application',
    ];

    /**
     * Public. The requested lists (?types=dog_breed,cat_breed; all of them by default). Hidden
     * options are included but flagged: dropdowns leave them out, while label lookups for older
     * records still need them.
     */
    public function index(Request $request)
    {
        $requested = array_filter(explode(',', (string) $request->query('types', '')));
        $types = $requested
            ? array_values(array_intersect(array_keys(CategoryOption::TYPES), $requested))
            : array_keys(CategoryOption::TYPES);

        $lists = array_fill_keys($types, []);
        CategoryOption::whereIn('type', $types)->orderBy('sort_order')->orderBy('id')->get()
            ->each(function (CategoryOption $o) use (&$lists) {
                $lists[$o->type][] = ['value' => $o->value, 'label' => $o->label, 'hidden' => ! $o->is_active];
            });

        return response()->json(['categories' => $lists]);
    }

    /** Admin. One list, with how many records use each option. */
    public function adminIndex(string $type)
    {
        $usage = $this->usage($type);

        return response()->json([
            'type' => $type,
            'name' => CategoryOption::TYPES[$type]['name'],
            'keyed' => CategoryOption::TYPES[$type]['keyed'],
            'used_by' => self::USED_BY[$type],
            'options' => CategoryOption::ofType($type)->get()->map(fn ($o) => $this->toItem($o, $usage))->values(),
        ]);
    }

    public function store(Request $request, string $type)
    {
        $label = $this->validatedLabel($request, $type);
        if ($label instanceof JsonResponse) {
            return $label;
        }

        $option = CategoryOption::create([
            'type' => $type,
            'value' => CategoryOption::TYPES[$type]['keyed'] ? $this->newKey($type, $label) : $label,
            'label' => $label,
            'sort_order' => (int) CategoryOption::where('type', $type)->max('sort_order') + 1,
            'is_active' => true,
        ]);

        return response()->json(['option' => $this->toItem($option, [])], 201);
    }

    /**
     * Rename (`label`) and/or hide or restore (`is_active`). Renaming a breed or behavioral issue
     * also rewrites the animals that have it, in the same transaction, so the list and the
     * records never disagree; keyed lists only change the label, which records look up.
     */
    public function update(Request $request, string $type, CategoryOption $option)
    {
        abort_unless($option->type === $type, 404);

        $validator = Validator::make($request->all(), [
            'label' => ['sometimes', 'required', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), $validator->errors()->toArray());
        }
        $data = $validator->validated();

        if (array_key_exists('is_active', $data) && ! $data['is_active'] && $option->is_active && $this->isLastVisible($option)) {
            return $this->fail('Keep at least one option visible, or there is nothing left to choose.');
        }

        $recordsUpdated = 0;
        if (array_key_exists('label', $data)) {
            $label = $this->validatedLabel($request, $type, $option);
            if ($label instanceof JsonResponse) {
                return $label;
            }

            if ($label !== $option->label) {
                $keyed = CategoryOption::TYPES[$type]['keyed'];
                $recordsUpdated = DB::transaction(function () use ($option, $label, $type, $keyed) {
                    $count = $keyed ? 0 : $this->renameInRecords($type, $option->value, $label);
                    $option->update($keyed ? ['label' => $label] : ['label' => $label, 'value' => $label]);

                    return $count;
                });
            }
        }

        if (array_key_exists('is_active', $data)) {
            $option->update(['is_active' => $data['is_active']]);
        }

        return response()->json([
            'option' => $this->toItem($option->fresh(), $this->usage($type)),
            'records_updated' => $recordsUpdated,
        ]);
    }

    /** Only an option no record uses (a typo, a mistake) can be deleted outright. */
    public function destroy(string $type, CategoryOption $option)
    {
        abort_unless($option->type === $type, 404);

        $uses = $this->usage($type)[mb_strtolower($option->value)] ?? 0;
        if ($uses > 0) {
            $noun = Str::plural(self::USED_BY[$type], $uses);

            return $this->fail("\"{$option->label}\" is used by {$uses} {$noun}, so it can't be deleted. Hide it instead — those records keep it.");
        }
        if ($option->is_active && $this->isLastVisible($option)) {
            return $this->fail('Keep at least one option visible, or there is nothing left to choose.');
        }

        $option->delete();

        return response()->json(['message' => "\"{$option->label}\" was deleted."]);
    }

    /** The submitted label, tidied, or a 422 when it's empty or already on the list. */
    private function validatedLabel(Request $request, string $type, ?CategoryOption $except = null): string|JsonResponse
    {
        $validator = Validator::make($request->all(), ['label' => ['required', 'string', 'max:100']], [
            'label.required' => 'Type a name.',
            'label.max' => 'Keep the name to 100 characters or fewer.',
        ]);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), $validator->errors()->toArray());
        }

        $label = trim((string) preg_replace('/\s+/u', ' ', $validator->validated()['label']));
        if ($label === '') {
            return $this->fail('Type a name.', ['label' => ['Type a name.']]);
        }

        $taken = CategoryOption::where('type', $type)
            ->whereRaw('LOWER(label) = ?', [mb_strtolower($label)])
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))
            ->exists();
        if ($taken) {
            $message = "\"{$label}\" is already on the list.";

            return $this->fail($message, ['label' => [$message]]);
        }

        return $label;
    }

    private function isLastVisible(CategoryOption $option): bool
    {
        return CategoryOption::where('type', $option->type)->where('is_active', true)->count() <= 1;
    }

    /** A new option's stored key, from its label: "Dental cleaning" → dental_cleaning, unique in its list. */
    private function newKey(string $type, string $label): string
    {
        $max = CategoryOption::TYPES[$type]['key_max'];
        $base = Str::limit(Str::slug($label, '_'), $max, '') ?: 'option';
        $key = $base;

        for ($n = 2; CategoryOption::where('type', $type)->where('value', $key)->exists(); $n++) {
            $suffix = "_{$n}";
            $key = Str::limit($base, $max - strlen($suffix), '').$suffix;
        }

        return $key;
    }

    /**
     * How many records use each option, keyed by the lower-cased value (breeds and issues are
     * typed text, so "aspin" on an older record still counts as Aspin).
     *
     * @return array<string, int>
     */
    private function usage(string $type): array
    {
        $grouped = fn ($query, string $column) => $query->whereNotNull($column)
            ->selectRaw("LOWER({$column}) as k, COUNT(*) as c")
            ->groupByRaw("LOWER({$column})")
            ->pluck('c', 'k')
            ->map(fn ($c) => (int) $c)
            ->all();

        return match ($type) {
            'dog_breed', 'cat_breed' => $grouped(
                Animal::query()->whereRaw('LOWER(species) = ?', [CategoryOption::TYPES[$type]['species']]), 'breed',
            ),
            'behavioral_issue' => $this->behavioralUsage(),
            'medical_record_type' => $grouped(MedicalRecord::query(), 'type'),
            'valid_id_type' => $this->sumCounts(
                $grouped(AdoptionApplication::query(), 'valid_id_type'),
                $grouped(VolunteerApplication::query(), 'valid_id_type'),
            ),
        };
    }

    /** Behavioral issues live in each animal's JSON list, so they're tallied here rather than in SQL. */
    private function behavioralUsage(): array
    {
        $counts = [];
        Animal::query()->whereNotNull('behavioral_assessment')->select(['id', 'behavioral_assessment'])
            ->chunkById(500, function ($animals) use (&$counts) {
                foreach ($animals as $animal) {
                    $issues = array_filter((array) $animal->behavioral_assessment, 'is_string');
                    foreach (array_unique(array_map('mb_strtolower', $issues)) as $issue) {
                        $counts[$issue] = ($counts[$issue] ?? 0) + 1;
                    }
                }
            });

        return $counts;
    }

    private function sumCounts(array ...$maps): array
    {
        $total = [];
        foreach ($maps as $map) {
            foreach ($map as $key => $count) {
                $total[$key] = ($total[$key] ?? 0) + $count;
            }
        }

        return $total;
    }

    /** Put the new name on every animal that has the old one. Returns how many animals changed. */
    private function renameInRecords(string $type, string $old, string $new): int
    {
        $needle = mb_strtolower($old);

        if ($type === 'behavioral_issue') {
            $updated = 0;
            Animal::query()->whereNotNull('behavioral_assessment')->chunkById(200, function ($animals) use ($needle, $new, &$updated) {
                foreach ($animals as $animal) {
                    $issues = (array) $animal->behavioral_assessment;
                    $renamed = array_map(fn ($issue) => is_string($issue) && mb_strtolower($issue) === $needle ? $new : $issue, $issues);
                    if ($renamed !== $issues) {
                        $animal->behavioral_assessment = array_values(array_unique($renamed));
                        $animal->save();
                        $updated++;
                    }
                }
            });

            return $updated;
        }

        // Breeds: only animals of that list's species — "Mixed breed" is on both lists.
        return Animal::query()
            ->whereRaw('LOWER(species) = ?', [CategoryOption::TYPES[$type]['species']])
            ->whereRaw('LOWER(breed) = ?', [$needle])
            ->update(['breed' => $new]);
    }

    private function toItem(CategoryOption $option, array $usage): array
    {
        return [
            'id' => $option->id,
            'value' => $option->value,
            'label' => $option->label,
            'hidden' => ! $option->is_active,
            'usage' => $usage[mb_strtolower($option->value)] ?? 0,
        ];
    }

    private function fail(string $message, array $errors = []): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => $errors ?: ['label' => [$message]]], 422);
    }
}
