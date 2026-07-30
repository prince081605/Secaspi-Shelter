<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Driver
    |--------------------------------------------------------------------------
    |
    | Which implementation of App\Contracts\PaymentGateway handles checkout.
    | Only 'simulated' ships today: the shelter is not a registered merchant, so
    | there is no live provider to talk to. The binding lives in
    | AppServiceProvider — adding a real PayMongo/Xendit driver means writing one
    | class and flipping this value, nothing else in the app changes.
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

];
