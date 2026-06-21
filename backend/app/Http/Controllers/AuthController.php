<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
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
                'email'     => $user->email,
                'role'      => $user->role,
            ],
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
            'email'     => $request->input('email'),
            'password'  => Hash::make($request->input('password')),
        ]);

        return response()->json([
            'user' => [
                'id'        => $user->id,
                'full_name' => $user->full_name,
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

            if (app()->environment('local')) {
                return response()->json([
                    'message' => 'Password reset token generated (development)',
                    'token'   => $token,
                ]);
            }
        }

        return response()->json([
            'message' => 'If the email exists, a password reset token will be generated.',
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