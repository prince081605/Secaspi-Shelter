<?php

namespace App\Http\Controllers;

use App\Models\RescueReport;
use App\Models\Team;
use App\Models\Volunteer;
use App\Models\VolunteerTask;
use App\Notifications\VolunteerTaskNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Team management (Personnel → Teams): teams of staff and volunteers with a leader, the rescues
 * assigned to them, and tasks given to a whole team. Teams are archived, never deleted.
 */
class TeamController extends Controller
{
    /** Every team (archived ones last), with the numbers the list shows. */
    public function index()
    {
        $teams = Team::query()
            ->with('leader.user')
            ->withCount([
                'members',
                'rescueReports as open_rescues_count' => fn ($q) => $q->where('status', '!=', 'resolved'),
                'tasks as open_tasks_count' => fn ($q) => $q->where('status', '!=', 'completed'),
            ])
            ->orderByDesc('is_active')->orderBy('name')
            ->get();

        return response()->json(['teams' => $teams->map(fn (Team $t) => [
            'id' => $t->id,
            'name' => $t->name,
            'purpose' => $t->purpose,
            'is_active' => $t->is_active,
            'leader' => $t->leader?->user?->full_name,
            'members_count' => $t->members_count,
            'open_rescues_count' => $t->open_rescues_count,
            'open_tasks_count' => $t->open_tasks_count,
        ])->values()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, [
            'name' => ['required', 'string', 'max:100'],
            'purpose' => ['nullable', 'string', 'max:1000'],
        ]);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        if ($taken = $this->nameTaken($data['name'])) {
            return $taken;
        }

        $team = Team::create(['name' => trim($data['name']), 'purpose' => $data['purpose'] ?? null, 'is_active' => true]);

        return response()->json(['team' => $this->toDetail($team)], 201);
    }

    public function show(Team $team)
    {
        return response()->json(['team' => $this->toDetail($team)]);
    }

    /** Rename, change the purpose, choose the leader (a member, or nobody), archive or restore. */
    public function update(Request $request, Team $team)
    {
        $data = $this->validated($request, [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'purpose' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'leader_id' => ['sometimes', 'nullable', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
            if ($taken = $this->nameTaken($data['name'], $team)) {
                return $taken;
            }
        }
        if (! empty($data['leader_id']) && ! $team->members()->whereKey($data['leader_id'])->exists()) {
            return $this->fail('The team leader has to be a member of the team.', 'leader_id');
        }

        $team->update($data);

        return response()->json(['team' => $this->toDetail($team->fresh())]);
    }

    public function addMember(Request $request, Team $team)
    {
        $data = $this->validated($request, ['volunteer_id' => ['required', 'integer', Rule::exists('volunteers', 'id')]], [
            'volunteer_id.required' => 'Choose who to add.',
            'volunteer_id.exists' => 'That person is not in Personnel.',
        ]);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        if ($team->members()->whereKey($data['volunteer_id'])->exists()) {
            return $this->fail('They are already on this team.', 'volunteer_id');
        }

        $team->members()->attach($data['volunteer_id']);

        return response()->json(['team' => $this->toDetail($team->fresh())], 201);
    }

    /** Taking the leader off the team leaves it without a leader until another is chosen. */
    public function removeMember(Team $team, Volunteer $volunteer)
    {
        DB::transaction(function () use ($team, $volunteer) {
            $team->members()->detach($volunteer->id);
            if ((int) $team->leader_id === (int) $volunteer->id) {
                $team->update(['leader_id' => null]);
            }
        });

        return response()->json(['team' => $this->toDetail($team->fresh())]);
    }

    /**
     * Give a task to everyone on the team. Each member gets their own copy — and sends their own
     * proof, through the usual task flow — and the copies share a batch id so the team's progress
     * on it can be shown as a whole.
     */
    public function assignTask(Request $request, Team $team)
    {
        $data = $this->validated($request, [
            'task_name' => ['required', 'string', 'max:150'],
            'assigned_date' => ['nullable', 'date'],
        ], ['task_name.required' => 'Describe the task.']);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        if (! $team->is_active) {
            return $this->fail('This team is archived. Restore it before giving it tasks.');
        }

        $members = $team->members()->with('user')->get();
        if ($members->isEmpty()) {
            return $this->fail('Add members to the team before giving it a task.');
        }

        $batch = (string) Str::uuid();
        $tasks = DB::transaction(fn () => $members->map(fn (Volunteer $member) => $member->tasks()->create([
            'task_name' => $data['task_name'],
            'status' => 'assigned',
            'assigned_date' => $data['assigned_date'] ?? null,
            'team_id' => $team->id,
            'team_batch' => $batch,
        ])));

        foreach ($tasks as $i => $task) {
            if ($user = $members[$i]->user) {
                (new VolunteerTaskNotification($task, 'assigned'))->sendTo($user);
            }
        }

        return response()->json(['team' => $this->toDetail($team->fresh())], 201);
    }

    private function toDetail(Team $team): array
    {
        $team->loadMissing(['members.user', 'leader.user']);

        $batches = VolunteerTask::where('team_id', $team->id)->whereNotNull('team_batch')
            ->select('team_batch', 'task_name', 'assigned_date')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed")
            ->selectRaw("SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as submitted")
            ->selectRaw('MIN(id) as first_id')
            ->groupBy('team_batch', 'task_name', 'assigned_date')
            ->orderByDesc('first_id')
            ->limit(20)
            ->get();

        $rescues = RescueReport::where('team_id', $team->id)
            ->orderByRaw("CASE WHEN status = 'resolved' THEN 1 ELSE 0 END")
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'location', 'urgency', 'status', 'created_at']);

        return [
            'id' => $team->id,
            'name' => $team->name,
            'purpose' => $team->purpose,
            'is_active' => $team->is_active,
            'leader_id' => $team->leader_id,
            'leader' => $team->leader?->user?->full_name,
            'members' => $team->members->map(fn (Volunteer $v) => [
                'id' => $v->id,
                'type' => $v->type,
                'full_name' => $v->user?->full_name,
                'email' => $v->user?->email,
                'phone' => $v->user?->phone,
                'is_leader' => (int) $v->id === (int) $team->leader_id,
            ])->values(),
            'tasks' => $batches->map(fn ($b) => [
                'batch' => $b->team_batch,
                'task_name' => $b->task_name,
                'assigned_date' => $b->assigned_date,
                'total' => (int) $b->total,
                'completed' => (int) $b->completed,
                'submitted' => (int) $b->submitted,
            ])->values(),
            'rescues' => $rescues->map(fn (RescueReport $r) => [
                'id' => $r->id,
                'location' => $r->location,
                'urgency' => $r->urgency,
                'status' => $r->status,
                'created_at' => $r->created_at,
            ])->values(),
        ];
    }

    private function nameTaken(string $name, ?Team $except = null): ?JsonResponse
    {
        $taken = Team::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))
            ->exists();

        return $taken ? $this->fail('There is already a team called "'.trim($name).'".', 'name') : null;
    }

    private function validated(Request $request, array $rules, array $messages = []): array|JsonResponse
    {
        $validator = Validator::make($request->all(), $rules, $messages);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        return $validator->validated();
    }

    private function fail(string $message, string $field = 'team'): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
    }
}
