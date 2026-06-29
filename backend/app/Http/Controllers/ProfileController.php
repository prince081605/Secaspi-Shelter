<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;

class ProfileController extends Controller
{
    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'full_name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        try {
            $user->update($validator->validated());
        } catch (\Throwable $e) {
            Log::error('Failed to update profile', [
                'user_id' => $user->id,
                'exception' => $e,
            ]);

            return response()->json(['message' => 'Failed to update profile. Please try again.'], 500);
        }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
            ],
        ]);
    }

    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        if (! Hash::check($request->input('current_password'), $user->password)) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => ['current_password' => ['The current password is incorrect.']],
            ], 422);
        }

        try {
            $user->update(['password' => Hash::make($request->input('password'))]);
        } catch (\Throwable $e) {
            Log::error('Failed to change password', [
                'user_id' => $user->id,
                'exception' => $e,
            ]);

            return response()->json(['message' => 'Failed to change password. Please try again.'], 500);
        }

        // Revoke every other session after a password change, keeping only the current token so
        // the user stays logged in here. Stale/compromised sessions elsewhere are signed out.
        $current = $request->user()->currentAccessToken();
        $user->tokens()
            ->when(
                $current instanceof PersonalAccessToken,
                fn ($query) => $query->where('id', '!=', $current->getKey()),
            )
            ->delete();

        return response()->json(['message' => 'Password changed successfully']);
    }
}
