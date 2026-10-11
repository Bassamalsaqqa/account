<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Command API Feature Gate
    |--------------------------------------------------------------------------
    |
    | When disabled, all /api/v1/* routes return 404 Not Found before any
    | session, CSRF, auth, or database queries run. Disabled by default.
    |
    */

    'api_enabled' => (bool) env('COMMAND_API_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Session Security Boundaries
    |--------------------------------------------------------------------------
    |
    | Hard maximum lifetime (in seconds) for API sessions regardless of activity.
    | Defaults to 8 hours (28,800 seconds). Idle expiry is controlled by
    | session.lifetime (in minutes).
    |
    */

    'session_max_age_seconds' => (int) env('COMMAND_API_SESSION_MAX_AGE', 28800),

    /*
    |--------------------------------------------------------------------------
    | Payload Size Limit
    |--------------------------------------------------------------------------
    |
    | Hard maximum incoming body size in bytes. 64 KiB = 65,536 bytes.
    |
    */

    'max_payload_bytes' => (int) env('COMMAND_API_MAX_PAYLOAD_BYTES', 65536),

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting Defaults (per minute)
    |--------------------------------------------------------------------------
    |
    | Limits for pre-auth guest, overall IP, and authenticated actor+company.
    |
    */

    'rate_limits' => [
        'ip_overall_per_minute' => (int) env('COMMAND_API_RATE_LIMIT_IP_OVERALL', 120),
        'guest_per_minute' => (int) env('COMMAND_API_RATE_LIMIT_GUEST', 30),
        'authenticated_per_minute' => (int) env('COMMAND_API_RATE_LIMIT_AUTH', 60),
    ],

];
