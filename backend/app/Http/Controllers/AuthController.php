<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Mailer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid credentials', 'errors' => $validator->errors()], 422);
        }

        $user = User::where('email', $request->input('email'))->first();
        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        // Suspended accounts may have valid credentials but must not be allowed in.
        // Surface the admin's reason so the user knows why and who to contact.
        if ($user->isSuspended()) {
            return response()->json([
                'message' => $user->suspensionMessage(),
                'status' => 'suspended',
                'reason' => $user->suspension_reason,
            ], 403);
        }

        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'username' => $user->username,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);

    }

    /**
     * Preview the system-assigned username for a given full name, so the sign-up form can show
     * it live (read-only) as the visitor types. Public — used before an account exists. The
     * actual username is assigned server-side in register(), so this is a best-effort preview.
     */
    public function suggestUsername(Request $request)
    {
        $name = (string) $request->input('name', '');

        return response()->json([
            'username' => trim($name) === '' ? '' : User::generateUsername($name),
        ]);
    }

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            // `confirmed` requires a matching `password_confirmation` field — same convention as
            // the change-password flow. Server-enforced so the client-side match check can't be
            // the only guard.
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $user = User::create([
            'full_name' => $request->input('name'),
            // System-assigned (ignores any client-supplied value), guaranteed unique.
            'username' => User::generateUsername($request->input('name')),
            'email' => $request->input('email'),
            'password' => Hash::make($request->input('password')),
        ]);

        // role/status are set by DB-column defaults (they're guarded against mass assignment), so
        // the freshly-created model doesn't know them yet — reload so the response reports role
        // 'user' instead of null.
        $user->refresh();

        // Issue an email-verification token and send the confirmation link. `email_verified`
        // stays false (its DB default) until the user clicks through.
        $token = $this->issueVerificationToken($user);
        $verifyUrl = $this->sendVerificationEmail($user, $token);

        $response = [
            'message' => 'Account created. Check your email to verify your address.',
            'user' => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'username' => $user->username,
                'email' => $user->email,
                'role' => $user->role,
                'email_verified' => (int) ($user->email_verified ?? 0),
            ],
        ];

        // In local dev (mail goes to the log by default) also return the link directly so the
        // flow can be exercised without a configured mail transport — mirrors forgotPassword().
        if (app()->environment('local')) {
            $response['verify_url'] = $verifyUrl;
        }

        return response()->json($response, 201);
    }

    /**
     * Confirm an email address from the link sent at registration. Idempotent: a
     * request for an already-verified account succeeds without changing anything.
     */
    public function verifyEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $user = User::where('email', $request->input('email'))->first();

        if ($user && $user->email_verified) {
            return response()->json(['message' => 'Email already verified. You can log in.']);
        }

        // Constant-time token comparison; a missing token/user fails the same way an invalid
        // one does, so the endpoint can't be used to probe which addresses are pending.
        $stored = (string) ($user->email_verification_token ?? '');
        $supplied = (string) $request->input('token');
        $expired = $user && $user->email_verification_token_expires_at
            && now()->greaterThan($user->email_verification_token_expires_at);

        if (! $user || $stored === '' || ! hash_equals($stored, $supplied) || $expired) {
            return response()->json([
                'message' => 'This verification link is invalid or has expired. Request a new one below.',
            ], 401);
        }

        $user->forceFill([
            'email_verified' => true,
            'email_verification_token' => null,
            'email_verification_token_expires_at' => null,
        ])->save();

        return response()->json(['message' => 'Email verified. You can now log in.']);
    }

    /**
     * Re-send the verification link. Always returns the same generic response so it
     * can't be used to enumerate which addresses are registered or already verified.
     */
    public function resendVerification(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $user = User::where('email', $request->input('email'))->first();
        $verifyUrl = null;

        if ($user && ! $user->email_verified) {
            $token = $this->issueVerificationToken($user);
            $verifyUrl = $this->sendVerificationEmail($user, $token);
        }

        $response = [
            'message' => 'If that account exists and is unverified, a new verification link has been sent.',
        ];

        if ($verifyUrl && app()->environment('local')) {
            $response['verify_url'] = $verifyUrl;
        }

        return response()->json($response);
    }

    /**
     * Generate a fresh verification token, persist it (plus a 24h expiry) on the user, and
     * return the plaintext token. The columns are guarded against mass assignment, so this
     * writes them with forceFill.
     */
    private function issueVerificationToken(User $user): string
    {
        $token = Str::random(64);

        $user->forceFill([
            'email_verification_token' => $token,
            'email_verification_token_expires_at' => now()->addDay(),
        ])->save();

        return $token;
    }

    /**
     * Email the verification link and return the URL. A mail-transport failure is logged but
     * never surfaced to the caller — registration must not 500 because SMTP is down.
     */
    private function sendVerificationEmail(User $user, string $token): string
    {
        $verifyUrl = rtrim(config('app.frontend_url'), '/')
            .'/verify-email?email='.urlencode($user->email)
            .'&token='.$token;

        Mailer::send(
            $user->email,
            $user->full_name,
            'Verify your SECASPI Shelter email',
            "Hi {$user->full_name},\n\n"
            .'Welcome to SECASPI Shelter! Please confirm your email address by opening the '
            ."link below (valid for 24 hours):\n\n"
            ."{$verifyUrl}\n\n"
            ."If you didn't create this account, you can safely ignore this email."
        );

        return $verifyUrl;
    }

    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $email = $request->input('email');
        $user = User::where('email', $email)->first();

        if ($user) {
            $token = Str::random(64);
            Cache::put("password_reset:{$email}", ['token' => $token, 'user_id' => $user->id], 30 * 60);

            $resetUrl = rtrim(config('app.frontend_url'), '/')
                .'/reset-password?email='.urlencode($email)
                .'&token='.$token;

            // Deliver the reset link by email. Best-effort: a mail-transport failure is logged
            // inside Mailer, never surfaced, so this can't 500 or reveal whether the address exists.
            Mailer::send(
                $email,
                $user->full_name,
                'Reset your SECASPI Shelter password',
                "Hi {$user->full_name},\n\n"
                .'We received a request to reset your SECASPI Shelter password. '
                ."Open the link below to choose a new password (valid for 30 minutes):\n\n"
                ."{$resetUrl}\n\n"
                ."If you didn't request this, you can safely ignore this email."
            );

            // In local dev (mail goes to the log) also return the token directly so the flow
            // can be exercised without a configured mail transport.
            if (app()->environment('local')) {
                return response()->json([
                    'message' => 'Password reset link sent (development — token shown below).',
                    'token' => $token,
                ]);
            }
        }

        // Always the same generic response so the endpoint can't enumerate registered accounts.
        return response()->json([
            'message' => 'If that email is registered, a password reset link has been sent.',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $email = $request->input('email');
        $token = $request->input('token');
        $cache = Cache::get("password_reset:{$email}");

        if (! $cache || ! hash_equals((string) $cache['token'], (string) $token)) {
            return response()->json(['message' => 'Invalid or expired token'], 401);
        }

        $user = User::find($cache['user_id']);
        if (! $user) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        $user->password = Hash::make($request->input('password'));
        $user->save();

        // A password reset is the canonical account-recovery action: revoke every existing
        // session so any attacker token created before the reset is immediately invalidated.
        $user->tokens()->delete();

        Cache::forget("password_reset:{$email}");

        return response()->json(['message' => 'Password updated successfully']);
    }

    public function me(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'username' => $user->username,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
                'email_verified' => (int) ($user->email_verified ?? 0),
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()?->tokens()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }
}
