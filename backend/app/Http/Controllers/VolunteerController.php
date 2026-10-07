<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\User;
use App\Models\Volunteer;
use App\Models\VolunteerTask;
use App\Notifications\VolunteerTaskNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class VolunteerController extends Controller
{
    /**
     * A task's life: a volunteer proposes one ('requested') or an admin hands one out
     * ('assigned'), it gets picked up ('ongoing'), the volunteer sends proof of the finished
     * work ('submitted'), and an admin signs it off against that proof ('completed').
     */
    private const TASK_STATUSES = ['requested', 'assigned', 'ongoing', 'submitted', 'completed'];

    /**
     * The current user's own volunteer record + tasks, or { volunteer: null } if they
     * aren't a volunteer yet. Powers the self-service /volunteer page.
     */
    public function me(Request $request)
    {
        $volunteer = Volunteer::with(['tasks.verifier', 'tasks.team'])->where('user_id', $request->user()->id)->first();

        if (! $volunteer) {
            return response()->json(['volunteer' => null]);
        }

        return response()->json(['volunteer' => [...$this->toItem($volunteer), 'teams' => $this->myTeams($volunteer)]]);
    }

    /**
     * A volunteer proposes a task they'd like to do (e.g. "Walk the dog"). It lands as
     * 'requested' and waits for an admin to confirm it (→ assigned) via updateTask().
     */
    public function requestTask(Request $request)
    {
        $volunteer = Volunteer::where('user_id', $request->user()->id)->first();

        if (! $volunteer) {
            return response()->json(['message' => 'Only approved volunteers can request tasks.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'task_name' => ['required', 'string', 'max:150'],
            // The day they plan to do it. Optional, and never in the past — it's a proposal.
            'assigned_date' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $task = $volunteer->tasks()->create([
            'task_name' => $data['task_name'],
            'assigned_date' => $data['assigned_date'] ?? null,
            'status' => 'requested',
        ]);

        return response()->json(['task' => $this->toTaskItem($task)], 201);
    }

    /**
     * A volunteer sends proof that they finished a task: a photo of the work, optionally with a
     * note. The task moves to 'submitted' and waits for an admin to sign it off — the volunteer
     * cannot complete their own task, which is the point of asking for proof at all.
     *
     * Re-submitting is allowed while the task is still under review, so a volunteer who sent a
     * blurry photo can replace it; the old file is deleted rather than left orphaned.
     */
    public function submitTaskProof(Request $request, VolunteerTask $task)
    {
        $volunteer = Volunteer::where('user_id', $request->user()->id)->first();

        if (! $volunteer || $task->volunteer_id !== $volunteer->id) {
            return response()->json(['message' => 'This is not one of your tasks.'], 403);
        }

        if ($task->status === 'completed') {
            return response()->json(['message' => 'This task is already completed.'], 409);
        }
        if ($task->status === 'requested') {
            return response()->json(['message' => 'This task has not been approved yet.'], 409);
        }

        $validator = Validator::make($request->all(), [
            'proof_image' => ['required', 'image', 'max:5120'],
            'proof_note' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $previous = $task->proof_path;
        $proofPath = null;

        try {
            $proofPath = $request->file('proof_image')->store('task-proofs');

            $task->update([
                'proof_path' => $proofPath,
                'proof_note' => $validator->validated()['proof_note'] ?? null,
                'proof_submitted_at' => now(),
                'status' => 'submitted',
            ]);
        } catch (\Throwable $e) {
            if ($proofPath) {
                Storage::delete($proofPath);
            }

            Log::error('Failed to submit volunteer task proof', [
                'task_id' => $task->id,
                'exception' => $e,
            ]);

            return response()->json(['message' => 'Failed to send your proof. Please try again.'], 500);
        }

        // Only once the row is safely updated — deleting first would lose the old photo if the
        // write failed, leaving a task claiming proof it no longer has.
        if ($previous && $previous !== $proofPath) {
            Storage::delete($previous);
        }

        return response()->json(['task' => $this->toTaskItem($task->fresh())]);
    }

    public function adminIndex(Request $request)
    {
        $query = Volunteer::query()->with(['user', 'tasks.verifier', 'tasks.team']);

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        } else {
            $query->where('type', 'volunteer');
        }

        // per_page lets a picker (e.g. "add attendance for…") load everyone at once; capped.
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        $volunteers = $query->orderByDesc('id')->paginate($perPage)->withQueryString();

        $volunteers->getCollection()->transform(fn (Volunteer $v) => $this->toItem($v));

        return response()->json($volunteers);
    }

    public function adminStore(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => ['required', 'integer', 'exists:users,id', 'unique:volunteers,user_id'],
            'type' => ['nullable', 'in:volunteer,staff'],
            'availability' => ['nullable', 'string', 'max:150'],
            'performance_notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $data['type'] = $data['type'] ?? 'volunteer';

        // Granting staff-level access promotes the account's role to `staff`, so it's an
        // admin-only action — a staff member must not be able to mint more staff (privilege
        // -escalation boundary). Adding volunteers stays open to staff (route role:staff).
        if ($data['type'] === 'staff' && ! $request->user()->hasRoleAtLeast('admin')) {
            return response()->json(['message' => 'Only an admin can add staff members.'], 403);
        }

        $volunteer = Volunteer::create($data);

        // role isn't mass-assignable (privilege-escalation guard), so forceFill it —
        // same approach as UserController::adminUpdate.
        $user = User::find($volunteer->user_id);
        if ($user && $user->role !== 'admin') {
            $user->forceFill(['role' => $volunteer->type])->save();
        }

        return response()->json(['volunteer' => $this->toItem($volunteer->fresh(['user', 'tasks.verifier', 'tasks.team']))], 201);
    }

    public function adminUpdate(Request $request, Volunteer $volunteer)
    {
        $validator = Validator::make($request->all(), [
            'availability' => ['nullable', 'string', 'max:150'],
            'hours_rendered' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'performance_notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // Hours come from attendance now; overriding the total is a correction to someone's
        // record (and staff work shifts themselves), so it's kept for admins.
        if (array_key_exists('hours_rendered', $data)) {
            if (! $request->user()->hasRoleAtLeast('admin')) {
                return response()->json(['message' => 'Only an admin can change hours rendered.'], 403);
            }
            $volunteer->setTotalHours((float) $data['hours_rendered']);
            unset($data['hours_rendered']);
        }

        $volunteer->update($data);

        return response()->json(['volunteer' => $this->toItem($volunteer->fresh(['user', 'tasks.verifier', 'tasks.team']))]);
    }

    public function adminDestroy(Volunteer $volunteer)
    {
        if ($volunteer->tasks()->exists()) {
            return response()->json([
                'message' => 'This personnel has assigned tasks and cannot be removed. Reassign or delete their tasks first.',
            ], 409);
        }

        $user = $volunteer->user;
        // Off every team too; a team they led is left without a leader until another is chosen.
        $volunteer->teams()->detach();
        Team::where('leader_id', $volunteer->id)->update(['leader_id' => null]);
        $volunteer->delete();

        if ($user && ($user->role === 'volunteer' || $user->role === 'staff')) {
            $user->forceFill(['role' => 'user'])->save();
        }

        return response()->json(['message' => 'Personnel removed']);
    }

    public function storeTask(Request $request, Volunteer $volunteer)
    {
        $validator = Validator::make($request->all(), [
            'task_name' => ['required', 'string', 'max:150'],
            'status' => ['nullable', 'in:'.implode(',', self::TASK_STATUSES)],
            'assigned_date' => ['nullable', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $data['status'] = $data['status'] ?? 'assigned';

        $task = $volunteer->tasks()->create($data);

        $volunteer->loadMissing('user');
        if ($volunteer->user) {
            (new VolunteerTaskNotification($task, 'assigned'))->sendTo($volunteer->user);
        }

        return response()->json(['task' => $this->toTaskItem($task)], 201);
    }

    public function updateTask(Request $request, VolunteerTask $task)
    {
        $validator = Validator::make($request->all(), [
            'task_name' => ['sometimes', 'string', 'max:150'],
            'status' => ['sometimes', 'in:'.implode(',', self::TASK_STATUSES)],
            'assigned_date' => ['nullable', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $completing = ($data['status'] ?? null) === 'completed' && $task->status !== 'completed';

        // Completing a task is the admin verifying the work (usually against the proof photo).
        // Staff run the rest of the task flow, but they also do tasks themselves, so letting
        // them complete tasks would let them verify their own work.
        if ($completing && ! $request->user()->hasRoleAtLeast('admin')) {
            return response()->json(['message' => 'Only an admin can verify a task as completed.'], 403);
        }

        $task->fill($data);
        if ($completing) {
            $task->forceFill(['verified_by' => $request->user()->id, 'verified_at' => now()]);
        } elseif (isset($data['status']) && $data['status'] !== 'completed') {
            // Reopened (e.g. sent back for a better photo): the old verification no longer holds.
            $task->forceFill(['verified_by' => null, 'verified_at' => null]);
        }
        $task->save();
        $statusChanged = $task->wasChanged('status');

        if ($statusChanged) {
            $task->loadMissing('volunteer.user');
            if ($task->volunteer && $task->volunteer->user) {
                (new VolunteerTaskNotification($task, 'updated'))->sendTo($task->volunteer->user);
            }
        }

        return response()->json(['task' => $this->toTaskItem($task->load('verifier'))]);
    }

    public function destroyTask(VolunteerTask $task)
    {
        $task->delete();

        return response()->json(['message' => 'Task deleted']);
    }

    private function toItem(Volunteer $v): array
    {
        return [
            'id' => $v->id,
            'type' => $v->type,
            'availability' => $v->availability,
            'hours_rendered' => $v->hours_rendered,
            'performance_notes' => $v->performance_notes,
            'user' => $v->user ? [
                'id' => $v->user->id,
                'full_name' => $v->user->full_name,
                'email' => $v->user->email,
                'phone' => $v->user->phone,
            ] : null,
            'tasks' => $v->tasks->map(fn (VolunteerTask $t) => $this->toTaskItem($t))->values(),
        ];
    }

    /**
     * One task, as both sides see it. The proof is shared deliberately: the admin needs it to
     * sign the task off, and the volunteer needs to see what they sent — and whether it landed.
     */
    private function toTaskItem(VolunteerTask $t): array
    {
        return [
            'id' => $t->id,
            'task_name' => $t->task_name,
            'status' => $t->status,
            'assigned_date' => $t->assigned_date,
            'proof_url' => $t->proof_path ? Storage::url($t->proof_path) : null,
            'proof_note' => $t->proof_note,
            'proof_submitted_at' => $t->proof_submitted_at,
            'verified_at' => $t->verified_at,
            'verified_by' => $t->verifier?->full_name,
            // Set when this is the member's copy of a task given to their whole team.
            'team' => $t->team_id ? $t->team?->name : null,
        ];
    }

    /**
     * The active teams someone is on, as they see them on their dashboard: the leader, their
     * teammates, and the rescues the team is handling right now.
     */
    private function myTeams(Volunteer $volunteer): array
    {
        return $volunteer->teams()->where('is_active', true)
            ->with(['members.user', 'leader.user', 'rescueReports' => fn ($q) => $q->where('status', '!=', 'resolved')->orderByDesc('id')])
            ->orderBy('name')
            ->get()
            ->map(fn (Team $team) => [
                'id' => $team->id,
                'name' => $team->name,
                'purpose' => $team->purpose,
                'leader' => $team->leader?->user?->full_name,
                'is_leader' => (int) $team->leader_id === (int) $volunteer->id,
                'members' => $team->members->map(fn (Volunteer $m) => [
                    'full_name' => $m->user?->full_name,
                    'phone' => $m->user?->phone,
                    'is_leader' => (int) $m->id === (int) $team->leader_id,
                    'is_me' => (int) $m->id === (int) $volunteer->id,
                ])->values(),
                'rescues' => $team->rescueReports->map(fn ($r) => [
                    'id' => $r->id,
                    'location' => $r->location,
                    'urgency' => $r->urgency,
                    'status' => $r->status,
                ])->values(),
            ])->values()->all();
    }
}
