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
 * "My Adopted Pets": an adopter's completed adoptions, the updates they send about them, and
 * their requests to return an animal. Every action is on the signed-in adopter's own adoption.
 */
class MyAdoptionController extends Controller
{
    public function index(Request $request)
    {
        $adoptions = AdoptionApplication::where('user_id', $request->user()->id)
            ->whereIn('status', ['completed', 'returned'])
            ->with(['animal.mainPhoto', 'followUps', 'updates', 'returns'])
            ->orderByDesc('completed_at')->orderByDesc('id')
            ->get();

        return response()->json(['adoptions' => $adoptions->map(fn ($a) => $this->toItem($a))->values()]);
    }

    public function storeUpdate(Request $request, AdoptionApplication $application, PostAdoption $postAdoption)
    {
        $this->authorizeOwner($request, $application);
        if ($application->status !== 'completed') {
            return response()->json(['message' => 'Updates can only be sent for a completed adoption.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'wellbeing' => ['required', Rule::in(AdoptionUpdate::WELLBEING)],
            'message' => ['required', 'string', 'max:2000'],
            'photos' => ['nullable', 'array', 'max:'.AdoptionUpdate::MAX_PHOTOS],
            'photos.*' => ['image', 'max:5120'],
            'share_publicly' => ['sometimes', 'boolean'],
        ], [
            'wellbeing.required' => 'Tell us how your pet is doing.',
            'message.required' => 'Write a few words about how things are going.',
            'photos.max' => 'You can add up to '.AdoptionUpdate::MAX_PHOTOS.' photos to one update.',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $paths = array_map(fn ($file) => $file->store('adoption-updates'), $request->file('photos', []));
        $shared = $request->boolean('share_publicly');

        $update = AdoptionUpdate::create([
            'adoption_application_id' => $application->id,
            'animal_id' => $application->animal_id,
            'user_id' => $request->user()->id,
            'wellbeing' => $request->input('wellbeing'),
            'message' => $request->input('message'),
            'photo_paths' => $paths ?: null,
            'share_publicly' => $shared,
            'story_status' => $shared ? 'pending' : null,
        ]);

        $postAdoption->received($update);

        return response()->json(['update' => $this->toUpdateItem($update->fresh())], 201);
    }

    /** The adopter withdraws a story they offered — it comes off the public page straight away. */
    public function stopSharing(Request $request, AdoptionApplication $application, AdoptionUpdate $update)
    {
        $this->authorizeOwner($request, $application);
        abort_unless((int) $update->adoption_application_id === (int) $application->id, 404);

        $update->forceFill(['share_publicly' => false])->save();

        return response()->json(['update' => $this->toUpdateItem($update)]);
    }

    public function requestReturn(Request $request, AdoptionApplication $application, PostAdoption $postAdoption)
    {
        $this->authorizeOwner($request, $application);
        if ($application->status !== 'completed') {
            return response()->json(['message' => 'Only a completed adoption can be returned.'], 422);
        }
        if ($application->returns()->where('status', 'pending')->exists()) {
            return response()->json(['message' => 'You already have a return request waiting for the shelter to review.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'max:2000'],
        ], ['reason.required' => 'Tell us why you need to return your pet, so we can help.']);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $return = AdoptionReturn::create([
            'adoption_application_id' => $application->id,
            'animal_id' => $application->animal_id,
            'user_id' => $request->user()->id,
            'reason' => $request->input('reason'),
            'status' => 'pending',
        ]);

        $postAdoption->alertStaff($return);

        return response()->json(['return' => $this->toReturnItem($return->fresh())], 201);
    }

    /** Someone else's adoption is reported as not found, rather than confirming it exists. */
    private function authorizeOwner(Request $request, AdoptionApplication $application): void
    {
        abort_unless((int) $application->user_id === (int) $request->user()->id, 404);
    }

    private function toItem(AdoptionApplication $a): array
    {
        $next = $a->followUps->firstWhere('status', 'pending');

        return [
            'id' => $a->id,
            'reference_no' => $a->reference_no,
            'status' => $a->status,
            'completed_at' => $a->completed_at,
            'animal' => $a->animal ? [
                'id' => $a->animal->id,
                'name' => $a->animal->name,
                'species' => $a->animal->species,
                'breed' => $a->animal->breed,
                'photo' => optional($a->animal->mainPhoto)->photo_url ? Storage::url($a->animal->mainPhoto->photo_url) : null,
            ] : null,
            'next_check_in' => $next ? ['label' => $next->label, 'due_date' => $next->due_date->toDateString()] : null,
            'check_ins' => $a->followUps->map(fn (AdoptionFollowUp $f) => [
                'label' => $f->label,
                'due_date' => $f->due_date->toDateString(),
                'status' => $f->status,
            ])->values(),
            'updates' => $a->updates->map(fn ($u) => $this->toUpdateItem($u))->values(),
            'return_request' => ($r = $a->returns->first()) ? $this->toReturnItem($r) : null,
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
            'created_at' => $u->created_at,
        ];
    }

    private function toReturnItem(AdoptionReturn $r): array
    {
        return [
            'id' => $r->id,
            'status' => $r->status,
            'reason' => $r->reason,
            'staff_notes' => $r->staff_notes,
            'created_at' => $r->created_at,
            'resolved_at' => $r->resolved_at,
        ];
    }
}
