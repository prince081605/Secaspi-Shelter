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
            'email' => $u->email,
            'phone' => $u->phone,
            'role' => $u->role,
            'status' => $u->status,
            'email_verified' => (bool) $u->email_verified,
        ]);

        return response()->json($users);
    }

    public function adminUpdate(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'role' => ['sometimes', 'in:admin,volunteer,user'],
            'status' => ['sometimes', 'in:active,suspended,pending'],
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

        // role/status are not mass-assignable on the model (privilege-escalation guard), so set
        // them explicitly here. $data is whitelisted by the validator above to role/status only.
        $user->forceFill($data)->save();

        // Keep the volunteer roster in sync with the role so the Users and Volunteers
        // modules can't disagree: promoting to "volunteer" adds them to the roster,
        // demoting to "user"/"admin" removes their volunteer record (and any tasks).
        if (array_key_exists('role', $data)) {
            if ($user->role === 'volunteer') {
                Volunteer::firstOrCreate(['user_id' => $user->id]);
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
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
                'status' => $user->status,
                'email_verified' => (bool) $user->email_verified,
            ],
        ]);
    }
}
