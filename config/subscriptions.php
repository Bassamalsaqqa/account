<?php

declare(strict_types=1);

return [
    // Deployment alone must never enable commercial restrictions on existing tenants.
    'enforcement_enabled' => env('SUBSCRIPTION_ENFORCEMENT_ENABLED', false),
    'grace_days' => 7,
    'reminder_days' => [14, 7, 1],
];
