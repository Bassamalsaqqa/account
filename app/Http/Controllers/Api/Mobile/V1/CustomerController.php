<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\MobileApiBoundary;
use App\Models\Company;
use App\Models\CompanyMobileSetting;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CustomerController extends Controller
{
    /**
     * GET /api/mobile/v1/companies/{companyId}/customers
     */
    public function index(Request $request, string $companyId): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        // 1. Authorize permission
        if (! $this->canReadCustomers($user, $company)) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required customer directory permissions.',
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

        // 5. Query active customers
        $query = Customer::where('company_id', $company->id)
            ->where('status', 'active');

        if ($search !== null) {
            $escapedSearch = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $query->where(function ($q) use ($escapedSearch): void {
                $q->where('name_ar', 'like', "{$escapedSearch}%")
                    ->orWhere('name_en', 'like', "{$escapedSearch}%")
                    ->orWhere('code', 'like', "{$escapedSearch}%")
                    ->orWhere('phone', 'like', "{$escapedSearch}%");
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

        // Determine configured walk-in customer ID for this company
        /** @var CompanyMobileSetting|null $mobileSetting */
        $mobileSetting = CompanyMobileSetting::where('company_id', $company->id)->first();
        $walkInCustomerId = $mobileSetting?->walk_in_customer_id;

        // 6. Map customers payload conforming to customer-directory-response.json
        $customersPayload = $records->map(function (Customer $customer) use ($walkInCustomerId) {
            $isWalkIn = false;
            if ($walkInCustomerId !== null && (int) $customer->id === (int) $walkInCustomerId) {
                $isWalkIn = true;

            }

            $code = $customer->code;
            if (empty($code)) {
                $code = 'CUST-'.substr($customer->public_id, -6);
            }

            return [
                'id' => $customer->public_id,
                'code' => $code,
                'name' => $customer->displayName(),
                'phone' => $customer->phone !== null && trim($customer->phone) !== '' ? $customer->phone : null,
                'is_walk_in' => $isWalkIn,
            ];
        })->values()->all();

        return response()->json([
            'company_id' => $company->public_id,
            'generated_at' => now()->format('Y-m-d\TH:i:s\Z'),
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
            'customers' => $customersPayload,
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Check if actor has permission to read customers.
     */
    private function canReadCustomers(User $user, Company $company): bool
    {
        return $user->hasPermissionTo('customers.view');
    }
}
