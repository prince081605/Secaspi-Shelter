<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Models\User;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);


        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid credentials', 'errors' => $validator->errors()], 422);
        }

        $user = User::where('email', $request->input('email'))->first();
        if (!$user || !Hash::check($request->input('password'), $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => [
                'id'        => $user->id,
                'full_name' => $user->full_name,
                'username'  => $user->username,
                'email'     => $user->email,
                'role'      => $user->role,
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
            'password' => ['required', 'string', 'min:8'],
        ]);


        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $user = User::create([
            'full_name' => $request->input('name'),
            // System-assigned (ignores any client-supplied value), guaranteed unique.
            'username'  => User::generateUsername($request->input('name')),
            'email'     => $request->input('email'),
            'password'  => Hash::make($request->input('password')),
        ]);

        return response()->json([
            'user' => [
                'id'        => $user->id,
                'full_name' => $user->full_name,
                'username'  => $user->username,
                'email'     => $user->email,
                'role'      => $user->role,
            ],
        ], 201);
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
        $user  = User::where('email', $email)->first();

        if ($user) {
            $token = Str::random(64);
            Cache::put("password_reset:{$email}", ['token' => $token, 'user_id' => $user->id], 30 * 60);

            $resetUrl = rtrim(config('app.frontend_url'), '/')
                . '/reset-password?email=' . urlencode($email)
                . '&token=' . $token;

            // Deliver the reset link by email. Wrapped so a mail-transport failure never
            // 500s the request or reveals whether the address exists.
            try {
                Mail::raw(
                    "Hi {$user->full_name},\n\n"
                    . "We received a request to reset your SECASPI Shelter password. "
                    . "Open the link below to choose a new password (valid for 30 minutes):\n\n"
                    . "{$resetUrl}\n\n"
                    . "If you didn't request this, you can safely ignore this email.",
                    function ($message) use ($email) {
                        $message->to($email)->subject('Reset your SECASPI Shelter password');
                    }
                );
            } catch (\Throwable $e) {
                Log::error('Failed to send password reset email', ['email' => $email, 'exception' => $e]);
            }

            // In local dev (mail goes to the log) also return the token directly so the flow
            // can be exercised without a configured mail transport.
            if (app()->environment('local')) {
                return response()->json([
                    'message' => 'Password reset link sent (development — token shown below).',
                    'token'   => $token,
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
            'email'    => ['required', 'email'],
            'token'    => ['required', 'string'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $email = $request->input('email');
        $token = $request->input('token');
        $cache = Cache::get("password_reset:{$email}");

        if (!$cache || !hash_equals((string) $cache['token'], (string) $token)) {
            return response()->json(['message' => 'Invalid or expired token'], 401);
        }

        $user = User::find($cache['user_id']);
        if (!$user) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        $user->password = Hash::make($request->input('password'));
        $user->save();

        Cache::forget("password_reset:{$email}");

        return response()->json(['message' => 'Password updated successfully']);
    }

    public function me(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        return response()->json([
            'user' => [
                'id'        => $user->id,
                'full_name' => $user->full_name,
                'username'  => $user->username,
                'email'     => $user->email,
                'phone'     => $user->phone,
                'role'      => $user->role,
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