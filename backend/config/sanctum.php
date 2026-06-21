<?php

return [
    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', 'localhost')),
    'stateful_token_prefix' => env('SANCTUM_STATEFUL_TOKEN_PREFIX', ''),

    'guard' => ['web'],

    'expiration' => env('SESSION_EXPIRATION', null),

];

