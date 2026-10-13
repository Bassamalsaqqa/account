<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | PWA Deployment Flag
    |--------------------------------------------------------------------------
    |
    | Disabled by default. PWA manifest, meta tags, and service worker registration
    | are only exposed when PWA_ENABLED is explicitly enabled in the environment.
    |
    */
    'enabled' => (bool) env('PWA_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | PWA Meta Presentation
    |--------------------------------------------------------------------------
    |
    | Only minimal presentation metadata consumed by shared layout heads.
    | Manifest details are managed canonically in public/manifest.webmanifest.
    |
    */
    'theme_color' => '#255fd6',
    'short_name' => 'التاجر الصغير',
];
