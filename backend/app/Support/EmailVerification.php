<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * The confirmation link for an email address: sent when an account is created, when the user
 * asks for it again, and when an admin changes the account's email.
 */
class EmailVerification
{
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
    public static function send(User $user, string $token, bool $changedByAdmin = false): string
    {
        $verifyUrl = rtrim(config('app.frontend_url'), '/')
            .'/verify-email?email='.urlencode($user->email)
            .'&token='.$token;

        $intro = $changedByAdmin
            ? "A SECASPI Shelter administrator changed your account's email to this address. Please confirm it by opening the "
            : 'Welcome to SECASPI Shelter! Please confirm your email address by opening the ';

        Mailer::send(
            $user->email,
            $user->full_name,
            'Verify your SECASPI Shelter email',
            "Hi {$user->full_name},\n\n"
            .$intro
            ."link below (valid for 24 hours):\n\n"
            ."{$verifyUrl}\n\n"
            .($changedByAdmin
                ? "If you didn't expect this change, please contact the shelter."
                : "If you didn't create this account, you can safely ignore this email.")
        );

        return $verifyUrl;
    }
}
