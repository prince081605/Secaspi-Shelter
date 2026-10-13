<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Volunteer;
use App\Support\EmailVerification;
use App\Support\Mailer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

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

        $users->getCollection()->transform(fn (User $u) => $this->toItem($u));

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
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
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

        return response()->json(['user' => $this->toItem($user)]);
    }

    /**
     * Change an account's email and/or username — admins only; nobody changes their own email.
     * A new email has to be confirmed again: the link goes to the new address, and the old one
     * is told about the change in case it wasn't expected.
     */
    public function adminUpdateLogin(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', 'max:255'],
            'username' => ['required', 'string', 'min:3', 'max:60', 'regex:'.User::USERNAME_PATTERN],
        ], [
            'username.regex' => 'A username can use letters, numbers, spaces, dots, dashes and underscores, but not @.',
            'username.min' => 'A username needs at least 3 characters.',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $email = trim($validator->validated()['email']);
        $username = trim((string) preg_replace('/\s+/u', ' ', $validator->validated()['username']));

        // Unique whatever the letter case, so two accounts can't differ only by capitals.
        $taken = fn (string $column, string $value) => User::whereRaw("LOWER({$column}) = ?", [mb_strtolower($value)])
            ->whereKeyNot($user->id)->exists();
        if ($taken('email', $email)) {
            return response()->json(['message' => 'Another account already uses this email.', 'errors' => ['email' => ['Another account already uses this email.']]], 422);
        }
        if ($taken('username', $username)) {
            return response()->json(['message' => 'Another account already uses this username.', 'errors' => ['username' => ['Another account already uses this username.']]], 422);
        }

        $oldEmail = $user->email;
        $emailChanged = mb_strtolower($email) !== mb_strtolower((string) $oldEmail);

        $user->forceFill(['username' => $username, 'email' => $email]);
        if ($emailChanged) {
            $user->forceFill(['email_verified' => false]);
        }
        $user->save();

        $verifyUrl = null;
        if ($emailChanged) {
            $verifyUrl = EmailVerification::send($user, EmailVerification::issue($user), changedByAdmin: true);

            if ($oldEmail) {
                Mailer::send(
                    $oldEmail,
                    $user->full_name,
                    'Your SECASPI Shelter email was changed',
                    "Hi {$user->full_name},\n\n"
                    ."A SECASPI Shelter administrator changed the email on your account from this address to {$email}. "
                    ."From now on, sign in with the new email or your username.\n\n"
                    ."If you didn't expect this, please contact the shelter."
                );
            }
        }

        $response = ['user' => $this->toItem($user->fresh()), 'email_changed' => $emailChanged];
        // Local dev sends mail to the log, so hand back the link to try the flow — as register() does.
        if ($verifyUrl && app()->environment('local')) {
            $response['verify_url'] = $verifyUrl;
        }

        return response()->json($response);
    }

    private function toItem(User $user): array
    {
        return [
            'id' => $user->id,
            'full_name' => $user->full_name,
            'username' => $user->username,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'status' => $user->status,
            'suspension_reason' => $user->suspension_reason,
            'email_verified' => (bool) $user->email_verified,
        ];
    }
}
