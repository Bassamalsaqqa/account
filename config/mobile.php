<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Mobile API Master Activation
    |--------------------------------------------------------------------------
    |
    | Gate mobile API endpoints. Defaults to false for shared-hosting safety.
    | When disabled, all /api/mobile/v1/* routes return 404 with standard error.
    |
    */
    'enabled' => (bool) env('MOBILE_API_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Mobile V2 API Master Activation (Draft Boundary Qualification)
    |--------------------------------------------------------------------------
    |
    | Gate mobile V2 API endpoints. Defaults to false.
    | When disabled, all /api/mobile/v2/* routes return 404 with standard sanitized JSON error.
    | Both mobile.enabled AND mobile.v2_enabled must be true to open the boundary.
    |
    */
    'v2_enabled' => (bool) env('MOBILE_V2_API_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Challenge and Token Lifetimes
    |--------------------------------------------------------------------------
    |
    | Challenge TTL is 5 minutes (300s) per ADR-0001. Token TTL is 7 days (604800s).
    |
    */
    'challenge_ttl_seconds' => (int) env('MOBILE_CHALLENGE_TTL', 300),
    'token_ttl_seconds' => (int) env('MOBILE_TOKEN_TTL', 604800),

    /*
    |--------------------------------------------------------------------------
    | Bounded Pagination Limits
    |--------------------------------------------------------------------------
    |
    | Maximum results per page bounded to 30 per slice specification.
    |
    */
    'max_page_limit' => 30,
    'default_page_limit' => 30,

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting Thresholds
    |--------------------------------------------------------------------------
    |
    | Bounded attempts per minute for guest, challenges, login, and authenticated requests.
    |
    */
    'rate_limits' => [
        'ip_overall_per_minute' => (int) env('MOBILE_RATE_LIMIT_IP', 120),
        'challenges_per_minute' => (int) env('MOBILE_RATE_LIMIT_CHALLENGE', 30),
        'login_attempts_per_minute' => (int) env('MOBILE_RATE_LIMIT_LOGIN', 10),
        'authenticated_per_minute' => (int) env('MOBILE_RATE_LIMIT_AUTH', 120),
    ],
];
