<?php

namespace App\Http\Controllers;

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
        $volunteer = Volunteer::with('tasks')->where('user_id', $request->user()->id)->first();

        if (! $volunteer) {
            return response()->json(['volunteer' => null]);
        }

        return response()->json(['volunteer' => $this->toItem($volunteer)]);
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
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $task = $volunteer->tasks()->create([
            'task_name' => $validator->validated()['task_name'],
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
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
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
        $query = Volunteer::query()->with(['user', 'tasks']);

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        } else {
            $query->where('type', 'volunteer');
        }

        $volunteers = $query->orderByDesc('id')->paginate(20)->withQueryString();

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
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
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

        return response()->json(['volunteer' => $this->toItem($volunteer->fresh(['user', 'tasks']))], 201);
    }

    public function adminUpdate(Request $request, Volunteer $volunteer)
    {
        $validator = Validator::make($request->all(), [
            'availability' => ['nullable', 'string', 'max:150'],
            'hours_rendered' => ['sometimes', 'integer', 'min:0'],
            'performance_notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $volunteer->update($validator->validated());

        return response()->json(['volunteer' => $this->toItem($volunteer->fresh(['user', 'tasks']))]);
    }

    public function adminDestroy(Volunteer $volunteer)
    {
        if ($volunteer->tasks()->exists()) {
            return response()->json([
                'message' => 'This personnel has assigned tasks and cannot be removed. Reassign or delete their tasks first.',
            ], 409);
        }

        $user = $volunteer->user;
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
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
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
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $task->update($validator->validated());
        $statusChanged = $task->wasChanged('status');

        if ($statusChanged) {
            $task->loadMissing('volunteer.user');
            if ($task->volunteer && $task->volunteer->user) {
                (new VolunteerTaskNotification($task, 'updated'))->sendTo($task->volunteer->user);
            }
        }

        return response()->json(['task' => $this->toTaskItem($task)]);
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
        ];
    }
}
