<?php

namespace App\Http\Controllers;

use App\Models\BlockedReporter;
use App\Models\User;
use App\Models\Volunteer;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function adminIndex(Request $request)
    {
        $query = User::query();

        if ($q = $request->query('q')) {
            // Case-insensitive on every engine: plain `LIKE` is case-insensitive on MySQL/SQLite
            // but case-sensitive on Postgres (prod), so normalise both sides with LOWER().
            // Mirrors the search in AnimalController::index().
            $needle = '%' . mb_strtolower($q) . '%';
            $query->where(function ($w) use ($needle) {
                $w->whereRaw('LOWER(full_name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(username) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$needle]);
            });
        }

        foreach (['role', 'status'] as $field) {
            if ($value = $request->query($field)) {
                $query->where($field, $value);
            }
        }

        $users = $query->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $users->getCollection()->transform(fn (User $u) => [
            'id' => $u->id,
            'full_name' => $u->full_name,
            'username' => $u->username,
            'email' => $u->email,
            'phone' => $u->phone,
            'role' => $u->role,
            'role_label' => $u->roleLabel(),
            'status' => $u->status,
            'suspension_reason' => $u->suspension_reason,
            'email_verified' => (bool) $u->email_verified,
        ]);

        return response()->json($users);
    }

    public function adminUpdate(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'role' => ['sometimes', Rule::in(array_keys(Permissions::LABELS))],
            'status' => ['sometimes', 'in:active,suspended,pending'],
            'suspension_reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        $changesRole = array_key_exists('role', $data) && $data['role'] !== $user->role;
        $deactivates = array_key_exists('status', $data) && $data['status'] !== 'active';

        // This also keeps at least one active System Admin, the only role that can hand out roles:
        // a System Admin can be demoted or suspended only by another, active, System Admin.
        if ($user->id === $request->user()->id && ($changesRole || $deactivates)) {
            return response()->json(['message' => 'You cannot change your own role or suspend your own account.'], 422);
        }

        // The reason only makes sense alongside a suspension: when status changes to
        // 'suspended' we record the supplied reason; any other status clears it so a
        // reactivated account doesn't keep a stale explanation.
        $reason = $data['suspension_reason'] ?? null;
        unset($data['suspension_reason']);
        if (array_key_exists('status', $data)) {
            $data['suspension_reason'] = $data['status'] === 'suspended' ? $reason : null;
        }

        // role/status/suspension_reason are not mass-assignable on the model
        // (privilege-escalation guard), so set them explicitly here. $data is whitelisted
        // by the validator above.
        $user->forceFill($data)->save();

        // Suspending must end any active session immediately: revoke all tokens so a user
        // who is currently logged in is kicked out on their next request, not merely blocked
        // from logging in again.
        if (($data['status'] ?? null) === 'suspended') {
            $user->tokens()->delete();
        }

        // Keep the personnel roster in sync with the role so the Users and Personnel
        // modules can't disagree: promoting to "volunteer" or "staff" adds them to the roster,
        // any other role (user, or one of the admin types) removes their personnel record (and any tasks).
        if (array_key_exists('role', $data)) {
            if ($user->role === 'volunteer' || $user->role === 'staff') {
                Volunteer::firstOrCreate(
                    ['user_id' => $user->id],
                    ['type' => $user->role]
                )->update(['type' => $user->role]);
            } else {
                $volunteer = Volunteer::where('user_id', $user->id)->first();
                if ($volunteer) {
                    $volunteer->tasks()->delete();
                    $volunteer->delete();
                }
            }
        }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'username' => $user->username,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
                'role_label' => $user->roleLabel(),
                'status' => $user->status,
                'suspension_reason' => $user->suspension_reason,
                'email_verified' => (bool) $user->email_verified,
            ],
        ]);
    }

    /** The System Admin's home: accounts by role and status, and the reporter block list size. */
    public function summary()
    {
        $byRole = User::query()->selectRaw('role, COUNT(*) AS total')->groupBy('role')->pluck('total', 'role');

        return response()->json([
            'roles' => collect(Permissions::LABELS)->map(fn (string $label, string $role) => [
                'role' => $role,
                'label' => $label,
                'count' => (int) ($byRole[$role] ?? 0),
            ])->values(),
            'total' => (int) $byRole->sum(),
            'suspended' => User::where('status', 'suspended')->count(),
            'unverified' => User::where('email_verified', 0)->count(),
            'blocked_reporters' => BlockedReporter::count(),
        ]);
    }
}
