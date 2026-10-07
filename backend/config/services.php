<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    // Brevo (formerly Sendinblue) transactional email over its HTTPS API. Used in production
    // because Render's free tier blocks outbound SMTP ports — see App\Support\Mailer. Leave
    // BREVO_API_KEY empty to fall back to the SMTP/log mailer (local dev).
    'brevo' => [
        'key' => env('BREVO_API_KEY'),
    ],

    // Cloudflare Turnstile — the "Verify you are human" check on the public forms (login,
    // register, forgot password, rescue report); see App\Support\Captcha. The check is on only
    // when BOTH keys are set, so local dev and tests run without it.
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
