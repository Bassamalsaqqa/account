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

        // 2. Resolve bounded limit (max 30 per specification)
        $maxLimit = (int) config('mobile.max_page_limit', 30);
        $rawLimit = $request->query('limit', $maxLimit);
        $limit = is_numeric($rawLimit) ? min(max((int) $rawLimit, 1), $maxLimit) : $maxLimit;

        // 3. Resolve search (max 100 characters)
        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $search = mb_substr(trim($search), 0, 100);
        } else {
            $search = null;
        }

        // 4. Resolve cursor
        $cursor = $request->query('cursor');
        if (! is_string($cursor) || trim($cursor) === '') {
            $cursor = null;
        } else {
            $cursor = trim($cursor);
        }

        // 5. Query Products without cost columns (Strict Server-Side Redaction)
        $query = Product::where('company_id', $company->id)
            ->where('active', true)
            ->with([
                'baseUnit',
                'productUnits.unit',
                'primaryBarcode',
                'barcodes',
                'balances',
            ]);

        if ($search !== null) {
            $query->where(function ($q) use ($search): void {
                $q->where('name_ar', 'like', "%{$search}%")
                    ->orWhere('name_en', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhereHas('barcodes', function ($bq) use ($search): void {
                        $bq->where('barcode', 'like', "%{$search}%");
                    });
            });
        }

        if ($cursor !== null) {
            $query->where('public_id', '>', $cursor);
        }

        $records = $query->orderBy('public_id', 'asc')
            ->limit($limit + 1)
            ->get();

        $hasMore = $records->count() > $limit;
        if ($hasMore) {
            $records = $records->slice(0, $limit);
            $nextCursor = $records->last()?->public_id;
        } else {
            $nextCursor = null;
        }

        if ($records->isNotEmpty()) {
            return MobileApiBoundary::buildErrorResponse(code: 'CATALOG_CONFIG_UNSUPPORTED', message: 'The existing domain has no approved product tax mapping required by Mobile V1.', status: 409);
        }
        $productsPayload = [];
        $catalogSnapshotVersion = CatalogSnapshot::version($company);

        return response()->json([
            'company_id' => $company->public_id,
            'catalog_snapshot_version' => $catalogSnapshotVersion,
            'generated_at' => now()->format('Y-m-d\TH:i:s\Z'),
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
            'products' => $productsPayload,
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
