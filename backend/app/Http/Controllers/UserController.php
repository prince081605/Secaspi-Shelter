<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Volunteer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    public function adminIndex(Request $request)
    {
        $query = User::query();

        if ($q = $request->query('q')) {
            $query->where(function ($w) use ($q) {
                $w->where('full_name', 'like', "%{$q}%")
                    ->orWhere('username', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
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
            'status' => $u->status,
            'suspension_reason' => $u->suspension_reason,
            'email_verified' => (bool) $u->email_verified,
        ]);

        return response()->json($users);
    }

    public function adminUpdate(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'role' => ['sometimes', 'in:admin,staff,volunteer,user'],
            'status' => ['sometimes', 'in:active,suspended,pending'],
            'suspension_reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        if ($user->id === $request->user()->id && (
            (array_key_exists('role', $data) && $data['role'] !== 'admin')
            || (array_key_exists('status', $data) && $data['status'] !== 'active')
        )) {
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
        // demoting to "user"/"admin" removes their personnel record (and any tasks).
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
                'status' => $user->status,
                'suspension_reason' => $user->suspension_reason,
                'email_verified' => (bool) $user->email_verified,
            ],
        ]);
    }
}
