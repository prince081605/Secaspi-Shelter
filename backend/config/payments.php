<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Driver
    |--------------------------------------------------------------------------
    |
    | Which implementation of App\Contracts\PaymentGateway handles checkout:
    |
    |   'simulated' — the in-app AspinPay checkout. No money moves, no account needed.
    |   'paymongo'  — PayMongo's hosted checkout. Needs PAYMONGO_SECRET_KEY; with a
    |                 sk_test_ key it runs in PayMongo test mode (real API, real
    |                 checkout page, no real money).
    |
    | The binding lives in AppServiceProvider.
    |
    */

    'driver' => env('PAYMENTS_DRIVER', 'simulated'),

    /*
    |--------------------------------------------------------------------------
    | Session lifetime
    |--------------------------------------------------------------------------
    |
    | How long a checkout link stays payable. Real gateways expire in 10–30
    | minutes; abandoned sessions are swept by `payments:expire-sessions` and
    | also expire lazily the next time the checkout page is opened.
    |
    */

    'session_ttl_minutes' => (int) env('PAYMENTS_SESSION_TTL', 15),

    /*
    |--------------------------------------------------------------------------
    | Which payment methods can be paid online
    |--------------------------------------------------------------------------
    |
    | Anything not listed here (cash) must be settled manually with a screenshot
    | and staff verification. Donors can always choose the manual route even for
    | the methods listed here.
    |
    */

    'gateway_rails' => ['gcash', 'bank'],

    /*
    |--------------------------------------------------------------------------
    | Simulation
    |--------------------------------------------------------------------------
    |
    | The one-time code the fake OTP screen accepts, how many wrong tries kill a
    | session, and the account numbers that force a specific outcome. The
    | triggers exist so a failure path can be demonstrated on demand instead of
    | waiting for a real decline that will never come.
    |
    */

    'otp' => env('PAYMENTS_OTP', '123456'),

    'max_otp_attempts' => (int) env('PAYMENTS_MAX_OTP_ATTEMPTS', 3),

    'triggers' => [
        '09000000001' => 'insufficient_funds',
        '09000000002' => 'declined',
        '09000000003' => 'invalid_account',
    ],

    /*
    |--------------------------------------------------------------------------
    | PayMongo
    |--------------------------------------------------------------------------
    |
    | secret_key      sk_test_… (test mode) or sk_live_… (live, once the shelter's
    |                 business account is verified). Server-side only — never ship
    |                 it to the frontend.
    | webhook_secret  whsk_… — returned when the webhook is registered (see
    |                 `php artisan paymongo:webhook`). Used to check that a call to
    |                 /api/webhooks/paymongo really came from PayMongo.
    | methods         Which PayMongo payment methods the hosted page offers for each
    |                 rail the donor can pick on our donate form.
    | min_amount      PayMongo will not open a checkout below this many pesos.
    |
    */

    'paymongo' => [
        'secret_key'     => env('PAYMONGO_SECRET_KEY'),
        'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),
        'base_url'       => env('PAYMONGO_BASE_URL', 'https://api.paymongo.com/v1'),
        'methods'        => [
            'gcash' => array_filter(explode(',', env('PAYMONGO_GCASH_METHODS', 'gcash'))),
            'bank'  => array_filter(explode(',', env('PAYMONGO_BANK_METHODS', 'dob,card'))),
        ],
        'min_amount'     => (int) env('PAYMONGO_MIN_AMOUNT', 20),
    ],

];
