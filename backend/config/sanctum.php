<?php

return [
    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', 'localhost')),
    'stateful_token_prefix' => env('SANCTUM_STATEFUL_TOKEN_PREFIX', ''),

    'guard' => ['web'],

    // Token lifetime in minutes. Default 14 days so a leaked bearer token can't live forever
    // (previously null = never expired). Override per-environment via SANCTUM_TOKEN_EXPIRATION.
    'expiration' => (int) env('SANCTUM_TOKEN_EXPIRATION', 60 * 24 * 14),

];

