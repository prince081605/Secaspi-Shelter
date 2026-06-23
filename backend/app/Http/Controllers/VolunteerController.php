<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Volunteer;
use App\Models\VolunteerTask;
use App\Notifications\VolunteerTaskNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VolunteerController extends Controller
{
    /**
     * The current user's own volunteer record + tasks, or { volunteer: null } if they
     * aren't a volunteer yet. Powers the self-service /volunteer page.
     */
    public function me(Request $request)
    {
        $volunteer = Volunteer::with('tasks')->where('user_id', $request->user()->id)->first();

        if (!$volunteer) {
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

        if (!$volunteer) {
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

        return response()->json(['task' => $task], 201);
    }

    public function adminIndex(Request $request)
    {
        $query = Volunteer::query()->with(['user', 'tasks']);

        $volunteers = $query->orderByDesc('id')->paginate(20)->withQueryString();

        $volunteers->getCollection()->transform(fn (Volunteer $v) => $this->toItem($v));

        return response()->json($volunteers);
    }

    public function adminStore(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => ['required', 'integer', 'exists:users,id', 'unique:volunteers,user_id'],
            'availability' => ['nullable', 'string', 'max:150'],
            'performance_notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $volunteer = Volunteer::create($validator->validated());

        // role isn't mass-assignable (privilege-escalation guard), so forceFill it —
        // same approach as UserController::adminUpdate.
        $user = User::find($volunteer->user_id);
        if ($user && $user->role !== 'admin') {
            $user->forceFill(['role' => 'volunteer'])->save();
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
                'message' => 'This volunteer has assigned tasks and cannot be removed. Reassign or delete their tasks first.',
            ], 409);
        }

        $user = $volunteer->user;
        $volunteer->delete();

        if ($user && $user->role === 'volunteer') {
            $user->forceFill(['role' => 'user'])->save();
        }

        return response()->json(['message' => 'Volunteer removed']);
    }

    public function storeTask(Request $request, Volunteer $volunteer)
    {
        $validator = Validator::make($request->all(), [
            'task_name' => ['required', 'string', 'max:150'],
            'status' => ['nullable', 'in:requested,assigned,ongoing,completed'],
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

        return response()->json(['task' => $task], 201);
    }

    public function updateTask(Request $request, VolunteerTask $task)
    {
        $validator = Validator::make($request->all(), [
            'task_name' => ['sometimes', 'string', 'max:150'],
            'status' => ['sometimes', 'in:requested,assigned,ongoing,completed'],
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

        return response()->json(['task' => $task]);
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
            'availability' => $v->availability,
            'hours_rendered' => $v->hours_rendered,
            'performance_notes' => $v->performance_notes,
            'user' => $v->user ? [
                'id' => $v->user->id,
                'full_name' => $v->user->full_name,
                'email' => $v->user->email,
                'phone' => $v->user->phone,
            ] : null,
            'tasks' => $v->tasks->map(fn (VolunteerTask $t) => [
                'id' => $t->id,
                'task_name' => $t->task_name,
                'status' => $t->status,
                'assigned_date' => $t->assigned_date,
            ])->values(),
        ];
    }
}
