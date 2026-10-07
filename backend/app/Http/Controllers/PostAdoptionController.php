<?php

namespace App\Http\Controllers;

use App\Models\AdoptionApplication;
use App\Models\AdoptionFollowUp;
use App\Models\AdoptionReturn;
use App\Models\AdoptionUpdate;
use App\Services\PostAdoption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Staff side of post-adoption care: the check-ins to make, the updates adopters send (and which
 * of them to publish as Happy Tails), and return requests to decide.
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
