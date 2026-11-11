<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\MobileApiBoundary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BootstrapController extends Controller
{
    public function show(Request $request, string $companyId): JsonResponse
    {
        // V1 requires non-null offline limits even when disabled. No business policy
        // has approved those limits. Do not publish guessed configuration or leases.
        return MobileApiBoundary::buildErrorResponse(
            code: 'BOOTSTRAP_CONFIG_UNSUPPORTED',
            message: 'Mobile V1 requires offline policy limits that are not approved. Bootstrap is unavailable pending a reviewed contract revision.',
            status: 409
        );
    }
}
