<?php

namespace App\Http\Controllers;

use App\Models\AdoptionApplication;
use App\Models\AdoptionFollowUp;
use App\Models\AdoptionReturn;
use App\Models\AdoptionStatusChange;
use App\Models\AdoptionUpdate;
use App\Rules\NotInFuture;
use App\Services\PostAdoption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Staff side of post-adoption care: every adopted animal and where it is now (its post-adoption
 * status and timeline), the check-ins to make, the updates adopters send (and which of them to
 * publish as Happy Tails), and return requests to decide.
 */
class PostAdoptionController extends Controller
{
    /** Counts for the page's tabs and the sidebar badge. */
    public static function summary(): array
    {
        return [
            'check_ins_due' => AdoptionFollowUp::where('status', 'pending')->whereDate('due_date', '<=', now())->count(),
            'unread_updates' => AdoptionUpdate::whereNull('read_at')->count(),
            'stories_pending' => AdoptionUpdate::where('share_publicly', true)->where('story_status', 'pending')->count(),
            'returns_pending' => AdoptionReturn::where('status', 'pending')->count(),
        ];
    }

    public function summaryIndex()
    {
        return response()->json(self::summary());
    }

    /**
     * Every adoption that was completed (including returned ones), newest first.
     * ?status= one of PostAdoption::STATUSES, ?q= animal name, adopter or reference no.
     * `counts` has how many adoptions are in each status, for the filter chips.
     */
    public function adoptions(Request $request)
    {
        $query = AdoptionApplication::query()
            ->whereIn('status', ['completed', 'returned'])
            ->with(['animal.mainPhoto', 'statusChanges.changer', 'followUps', 'returns'])
            ->orderByDesc('completed_at')->orderByDesc('id');

        if (array_key_exists($status = (string) $request->query('status'), PostAdoption::STATUSES)) {
            $query->where('post_adoption_status', $status);
        }
        if ($q = trim((string) $request->query('q'))) {
            // LOWER() on both sides: LIKE is case-sensitive on Postgres (prod).
            $needle = '%'.mb_strtolower($q).'%';
            $query->where(fn ($w) => $w->whereRaw('LOWER(full_name) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(reference_no) LIKE ?', [$needle])
                ->orWhereHas('animal', fn ($a) => $a->whereRaw('LOWER(name) LIKE ?', [$needle])));
        }

        $page = $query->paginate(20)->withQueryString();
        $page->getCollection()->transform(fn (AdoptionApplication $a) => $this->toAdoptionItem($a));

        $counts = AdoptionApplication::whereIn('status', ['completed', 'returned'])
            ->selectRaw('post_adoption_status, COUNT(*) AS total')->groupBy('post_adoption_status')
            ->pluck('total', 'post_adoption_status');

        return response()->json([
            ...$page->toArray(),
            'counts' => collect(PostAdoption::STATUSES)->map(fn ($label, $key) => (int) ($counts[$key] ?? 0)),
        ]);
    }

    public function updateStatus(Request $request, AdoptionApplication $application, PostAdoption $postAdoption)
    {
        if ($application->status === 'returned') {
            return response()->json(['message' => 'This adoption ended with a return — the animal is back in shelter care. A new adoption starts a new record.'], 422);
        }
        if ($application->status !== 'completed') {
            return response()->json(['message' => 'Only a completed adoption has a post-adoption status.'], 422);
        }

        $current = $application->post_adoption_status;
        $validator = Validator::make($request->all(), [
            'status' => ['required', Rule::in(array_keys(PostAdoption::STATUSES)), Rule::notIn([$current])],
            'happened_on' => array_filter(['nullable', 'date', new NotInFuture,
                $application->completed_at ? 'after_or_equal:'.$application->completed_at->toDateString() : null]),
            'animal_status' => ['required_if:status,returned', 'nullable', Rule::in(AdoptionReturn::ANIMAL_STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'status.required' => 'Choose the new status.',
            'status.not_in' => 'That is already this adoption’s status.',
            'happened_on.after_or_equal' => 'The date can’t be before the adoption was completed.',
            'animal_status.required_if' => 'Choose where the animal goes when it comes back.',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $to = $request->input('status');
        if ($current === 'deceased' && $to === 'returned') {
            return response()->json(['message' => 'This animal is recorded as deceased. If that was a mistake, set it back to In home first.'], 422);
        }

        $postAdoption->changeStatus($application, $to, $validator->safe()->only(['happened_on', 'animal_status', 'notes']), $request->user());

        return response()->json(['adoption' => $this->toAdoptionItem($application->fresh(['animal.mainPhoto', 'statusChanges.changer', 'followUps', 'returns']))]);
    }

    /** ?view=due (default: due today or overdue) | upcoming | done */
    public function checkIns(Request $request)
    {
        $view = $request->query('view', 'due');
        $query = AdoptionFollowUp::query()->with(['application', 'animal.mainPhoto', 'recorder']);

        match ($view) {
            'upcoming' => $query->where('status', 'pending')->whereDate('due_date', '>', now())->orderBy('due_date'),
            'done' => $query->where('status', 'done')->orderByDesc('completed_at'),
            default => $query->where('status', 'pending')->whereDate('due_date', '<=', now())->orderBy('due_date'),
        };

        $page = $query->paginate(20)->withQueryString();
        $page->getCollection()->transform(fn (AdoptionFollowUp $f) => $this->toCheckInItem($f));

        return response()->json($page);
    }

    public function recordCheckIn(Request $request, AdoptionFollowUp $followUp, PostAdoption $postAdoption)
    {
        if ($followUp->status !== 'pending') {
            return response()->json(['message' => 'This check-in has already been recorded or was called off.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'contact_method' => ['required', Rule::in(AdoptionFollowUp::CONTACT_METHODS)],
            'wellbeing' => ['required', Rule::in(AdoptionFollowUp::WELLBEING)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'contact_method.required' => 'Choose how you reached the adopter.',
            'wellbeing.required' => 'Choose how the animal is doing.',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $postAdoption->record($followUp, $validator->validated(), $request->user());

        return response()->json(['check_in' => $this->toCheckInItem($followUp->fresh(['application', 'animal.mainPhoto', 'recorder']))]);
    }

    /** ?filter=all (default) | unread | stories (offered as Happy Tails, awaiting review) */
    public function updates(Request $request)
    {
        $query = AdoptionUpdate::query()->with(['application', 'animal.mainPhoto', 'user'])->orderByDesc('id');

        match ($request->query('filter')) {
            'unread' => $query->whereNull('read_at'),
            'stories' => $query->where('share_publicly', true)->where('story_status', 'pending'),
            default => null,
        };

        $page = $query->paginate(20)->withQueryString();
        $page->getCollection()->transform(fn (AdoptionUpdate $u) => $this->toUpdateItem($u));

        return response()->json($page);
    }

    public function markUpdateRead(AdoptionUpdate $update)
    {
        if (! $update->read_at) {
            $update->forceFill(['read_at' => now()])->save();
        }

        return response()->json(['update' => $this->toUpdateItem($update->fresh(['application', 'animal.mainPhoto', 'user']))]);
    }

    public function reviewStory(Request $request, AdoptionUpdate $update, PostAdoption $postAdoption)
    {
        if (! $update->share_publicly) {
            return response()->json(['message' => 'The adopter has not offered this update as a public story.'], 422);
        }

        $validator = Validator::make($request->all(), ['decision' => ['required', Rule::in(['approved', 'declined'])]]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $postAdoption->reviewStory($update, $request->input('decision'), $request->user());

        return response()->json(['update' => $this->toUpdateItem($update->fresh(['application', 'animal.mainPhoto', 'user']))]);
    }

    /** Pending requests first, then decided ones, newest first. */
    public function returns()
    {
        $page = AdoptionReturn::query()->with(['application', 'animal.mainPhoto', 'user'])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->paginate(20);
        $page->getCollection()->transform(fn (AdoptionReturn $r) => $this->toReturnItem($r));

        return response()->json($page);
    }

    public function resolveReturn(Request $request, AdoptionReturn $return, PostAdoption $postAdoption)
    {
        if ($return->status !== 'pending') {
            return response()->json(['message' => 'This return request has already been decided.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'decision' => ['required', Rule::in(['accepted', 'declined'])],
            'animal_status' => ['required_if:decision,accepted', 'nullable', Rule::in(AdoptionReturn::ANIMAL_STATUSES)],
            'staff_notes' => ['nullable', 'string', 'max:2000'],
        ], ['animal_status.required_if' => 'Choose where the animal goes when it comes back.']);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $postAdoption->resolveReturn(
            $return,
            $request->input('decision'),
            $request->input('animal_status'),
            $request->input('staff_notes'),
            $request->user(),
        );

        return response()->json(['return' => $this->toReturnItem($return->fresh(['application', 'animal.mainPhoto', 'user']))]);
    }

    private function adoption(?AdoptionApplication $a): ?array
    {
        return $a ? [
            'id' => $a->id,
            'reference_no' => $a->reference_no,
            'status' => $a->status,
            'adopter' => $a->full_name,
            'contact_number' => $a->contact_number,
            'completed_at' => $a->completed_at,
        ] : null;
    }

    private function animal($animal): ?array
    {
        return $animal ? [
            'id' => $animal->id,
            'name' => $animal->name,
            'species' => $animal->species,
            'photo' => optional($animal->mainPhoto)->photo_url ? Storage::url($animal->mainPhoto->photo_url) : null,
        ] : null;
    }

    private function toAdoptionItem(AdoptionApplication $a): array
    {
        $next = $a->followUps->firstWhere('status', 'pending');

        return [
            ...$this->adoption($a),
            'post_adoption_status' => $a->post_adoption_status,
            'post_adoption_status_label' => PostAdoption::STATUSES[$a->post_adoption_status] ?? null,
            'next_check_in' => $next ? ['label' => $next->label, 'due_date' => $next->due_date->toDateString()] : null,
            'pending_return' => $a->returns->contains('status', 'pending'),
            'animal' => $this->animal($a->animal),
            'history' => $a->statusChanges->map(fn (AdoptionStatusChange $c) => [
                'id' => $c->id,
                'from' => $c->from_status,
                'to' => $c->to_status,
                'to_label' => PostAdoption::STATUSES[$c->to_status] ?? $c->to_status,
                'happened_on' => $c->happened_on?->toDateString(),
                'animal_status' => $c->animal_status,
                'notes' => $c->notes,
                'by' => $c->changer?->full_name,
                'recorded_at' => $c->created_at,
            ])->values(),
        ];
    }

    private function toCheckInItem(AdoptionFollowUp $f): array
    {
        return [
            'id' => $f->id,
            'label' => $f->label,
            'due_date' => $f->due_date->toDateString(),
            'overdue' => $f->status === 'pending' && $f->due_date->lt(now()->startOfDay()),
            'status' => $f->status,
            'contact_method' => $f->contact_method,
            'wellbeing' => $f->wellbeing,
            'notes' => $f->notes,
            'recorded_by' => $f->recorder?->full_name,
            'completed_at' => $f->completed_at,
            'adoption' => $this->adoption($f->application),
            'animal' => $this->animal($f->animal),
        ];
    }

    private function toUpdateItem(AdoptionUpdate $u): array
    {
        return [
            'id' => $u->id,
            'wellbeing' => $u->wellbeing,
            'message' => $u->message,
            'photos' => $u->photoUrls(),
            'share_publicly' => $u->share_publicly,
            'story_status' => $u->share_publicly ? $u->story_status : null,
            'answered_check_in' => (bool) $u->follow_up_id,
            'read' => (bool) $u->read_at,
            'created_at' => $u->created_at,
            'adopter' => $u->user?->full_name,
            'adoption' => $this->adoption($u->application),
            'animal' => $this->animal($u->animal),
        ];
    }

    private function toReturnItem(AdoptionReturn $r): array
    {
        return [
            'id' => $r->id,
            'status' => $r->status,
            'reason' => $r->reason,
            'animal_status' => $r->animal_status,
            'staff_notes' => $r->staff_notes,
            'created_at' => $r->created_at,
            'resolved_at' => $r->resolved_at,
            'adopter' => $r->user?->full_name,
            'adopter_email' => $r->user?->email,
            'adoption' => $this->adoption($r->application),
            'animal' => $this->animal($r->animal),
        ];
    }
}
