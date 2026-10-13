<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * The confirmation link for an email address: sent when an account is created and when the
 * user asks for it again.
 */
class EmailVerification
{
    /**
     * Generate a fresh verification token, persist it (plus a 24h expiry) on the user, and
     * return the plaintext token. The columns are guarded against mass assignment, so this
     * writes them with forceFill.
     */
    public static function issue(User $user): string
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
    public static function send(User $user, string $token): string
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
}
