<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\MobileApiBoundary;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Policies\ProductPolicy;
use App\Support\Mobile\CatalogSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProductController extends Controller
{
    /**
     * GET /api/mobile/v1/companies/{companyId}/products
     */
    public function index(Request $request, string $companyId): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        // 1. Authorize permission
        if (! $this->canReadProducts($user, $company)) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required catalog permissions.',
                status: 403
            );
        }

        // Until the catalog contract is supported, reject populated companies
        // independently of search/cursor so empty pages cannot bypass this gate.
        if (Product::where('company_id', $company->id)->where('active', true)->exists()) {
            return MobileApiBoundary::buildErrorResponse(code: 'CATALOG_CONFIG_UNSUPPORTED', message: 'The existing domain has no approved product tax mapping required by Mobile V1.', status: 409);
        }

        return response()->json([
            'company_id' => $company->public_id,
            'catalog_snapshot_version' => CatalogSnapshot::emptyVersion(),
            'generated_at' => now()->format('Y-m-d\TH:i:s\Z'),
            'next_cursor' => null,
            'has_more' => false,
            'products' => [],
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Check if actor has permission to read products.
     */
    private function canReadProducts(User $user, Company $company): bool
    {
        return app(ProductPolicy::class)->viewAny($user);
    }
}
