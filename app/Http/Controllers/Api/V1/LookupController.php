<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\CompanyCurrency;
use App\Models\Customer;
use App\Models\Product;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Sales\SalesActorGuard;
use App\Services\Security\CommandSessionGuard;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class LookupController extends ApiController
{
    /**
     * GET /api/v1/companies/{companyId}/lookups/customers
     * Scoped, bounded lookup of active customers inside a single Company-first transaction.
     */
    public function customers(Request $request, string $companyId): JsonResponse
    {
        $traceId = $this->resolveTraceId($request);
        $context = app(CompanyContext::class);
        $company = $context->company();
        /** @var User $actor */
        $actor = auth()->user();

        $limit = $this->resolveLimit($request);
        $search = $this->resolveSearch($request);

        try {
            $data = DB::transaction(function () use ($company, $actor, $limit, $search) {
                app(SalesActorGuard::class)->lockAndAuthorize((int) $company->id, $actor, 'sales.invoice.view');
                app(CommandSessionGuard::class)->authorize($actor);

                $query = Customer::where('company_id', $company->id)
                    ->where('status', 'active');

                if ($search !== null) {
                    $query->where(function ($q) use ($search) {
                        $q->where('name_ar', 'like', "%{$search}%")
                            ->orWhere('name_en', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%")
                            ->orWhere('tax_number', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
                }

                $records = $query->orderBy('name_ar')
                    ->limit($limit)
                    ->get(['id', 'public_id', 'code', 'name_ar', 'name_en', 'tax_number', 'default_currency_code', 'credit_limit']);

                return $records->map(fn ($c) => [
                    'id' => (int) $c->id,
                    'public_id' => $c->public_id,
                    'code' => $c->code,
                    'name_ar' => $c->name_ar,
                    'name_en' => $c->name_en,
                    'tax_number' => $c->tax_number,
                    'default_currency_code' => $c->default_currency_code,
                    'credit_limit' => $c->credit_limit !== null ? (string) $c->credit_limit : null,
                ])->values()->all();
            });
        } catch (AuthorizationException) {
            return $this->problem('Forbidden', 403, 'Actor does not possess permission to view sales customers.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.5.3');
        } catch (AuthenticationException) {
            return $this->problem('Unauthorized', 401, 'Session authentication verification failed.', $traceId, 'https://tools.ietf.org/html/rfc7235#section-3.1');
        } catch (Throwable $e) {
            Log::error(get_class($e), ['trace_id' => $traceId]);

            return $this->problem('Internal Server Error', 500, 'An unexpected error occurred during customer lookup.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.6.1');
        }

        return response()->json(['data' => $data], 200, $this->securityHeaders($traceId));
    }

    /**
     * GET /api/v1/companies/{companyId}/lookups/products
     * Scoped, bounded lookup of products and units inside a single Company-first transaction.
     * Cost data is strictly excluded.
     */
    public function products(Request $request, string $companyId): JsonResponse
    {
        $traceId = $this->resolveTraceId($request);
        $context = app(CompanyContext::class);
        $company = $context->company();
        /** @var User $actor */
        $actor = auth()->user();

        $limit = $this->resolveLimit($request);
        $search = $this->resolveSearch($request);

        try {
            $data = DB::transaction(function () use ($company, $actor, $limit, $search) {
                app(SalesActorGuard::class)->lockAndAuthorize((int) $company->id, $actor, 'sales.invoice.view');
                app(CommandSessionGuard::class)->authorize($actor);

                $query = Product::where('company_id', $company->id)
                    ->where('active', true)
                    ->with(['productUnits.unit']);

                if ($search !== null) {
                    $query->where(function ($q) use ($search) {
                        $q->where('name_ar', 'like', "%{$search}%")
                            ->orWhere('name_en', 'like', "%{$search}%")
                            ->orWhere('sku', 'like', "%{$search}%");
                    });
                }

                $records = $query->orderBy('name_ar')
                    ->limit($limit)
                    ->get();

                return $records->map(function ($p) {
                    return [
                        'id' => (int) $p->id,
                        'public_id' => $p->public_id,
                        'sku' => $p->sku,
                        'name_ar' => $p->name_ar,
                        'name_en' => $p->name_en,
                        'product_type' => $p->product_type,
                        'track_stock' => (bool) $p->track_stock,
                        'track_expiry' => (bool) $p->track_expiry,
                        'sale_price_suggestion' => $p->default_sale_price_base !== null ? (string) $p->default_sale_price_base : null,
                        'units' => $p->productUnits->where('active', true)->map(fn ($pu) => [
                            'id' => (int) $pu->id,
                            'unit_id' => (int) $pu->unit_id,
                            'name_ar' => $pu->unit?->name_ar,
                            'name_en' => $pu->unit?->name_en,
                            'conversion_to_base' => (string) $pu->conversion_to_base,
                            'is_base' => (bool) $pu->is_base,
                            'sale_price_suggestion' => $pu->default_sale_price_base !== null ? (string) $pu->default_sale_price_base : null,
                        ])->values()->all(),
                    ];
                })->values()->all();
            });
        } catch (AuthorizationException) {
            return $this->problem('Forbidden', 403, 'Actor does not possess permission to view sales products.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.5.3');
        } catch (AuthenticationException) {
            return $this->problem('Unauthorized', 401, 'Session authentication verification failed.', $traceId, 'https://tools.ietf.org/html/rfc7235#section-3.1');
        } catch (Throwable $e) {
            Log::error(get_class($e), ['trace_id' => $traceId]);

            return $this->problem('Internal Server Error', 500, 'An unexpected error occurred during product lookup.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.6.1');
        }

        return response()->json(['data' => $data], 200, $this->securityHeaders($traceId));
    }

    /**
     * GET /api/v1/companies/{companyId}/lookups/warehouses
     * Scoped, bounded lookup of warehouses inside a single Company-first transaction.
     */
    public function warehouses(Request $request, string $companyId): JsonResponse
    {
        $traceId = $this->resolveTraceId($request);
        $context = app(CompanyContext::class);
        $company = $context->company();
        /** @var User $actor */
        $actor = auth()->user();

        $limit = $this->resolveLimit($request);
        $search = $this->resolveSearch($request);

        try {
            $data = DB::transaction(function () use ($company, $actor, $limit, $search) {
                app(SalesActorGuard::class)->lockAndAuthorize((int) $company->id, $actor, 'sales.invoice.view');
                app(CommandSessionGuard::class)->authorize($actor);

                $query = Warehouse::where('company_id', $company->id)
                    ->where('active', true);

                if ($search !== null) {
                    $query->where(function ($q) use ($search) {
                        $q->where('name_ar', 'like', "%{$search}%")
                            ->orWhere('name_en', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%");
                    });
                }

                $records = $query->orderBy('name_ar')
                    ->limit($limit)
                    ->get(['id', 'public_id', 'code', 'name_ar', 'name_en', 'is_default']);

                return $records->map(fn ($w) => [
                    'id' => (int) $w->id,
                    'public_id' => $w->public_id,
                    'code' => $w->code,
                    'name_ar' => $w->name_ar,
                    'name_en' => $w->name_en,
                    'is_default' => (bool) $w->is_default,
                ])->values()->all();
            });
        } catch (AuthorizationException) {
            return $this->problem('Forbidden', 403, 'Actor does not possess permission to view warehouses.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.5.3');
        } catch (AuthenticationException) {
            return $this->problem('Unauthorized', 401, 'Session authentication verification failed.', $traceId, 'https://tools.ietf.org/html/rfc7235#section-3.1');
        } catch (Throwable $e) {
            Log::error(get_class($e), ['trace_id' => $traceId]);

            return $this->problem('Internal Server Error', 500, 'An unexpected error occurred during warehouse lookup.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.6.1');
        }

        return response()->json(['data' => $data], 200, $this->securityHeaders($traceId));
    }

    /**
     * GET /api/v1/companies/{companyId}/lookups/currencies
     * Scoped lookup of enabled company currencies inside a single Company-first transaction.
     */
    public function currencies(Request $request, string $companyId): JsonResponse
    {
        $traceId = $this->resolveTraceId($request);
        $context = app(CompanyContext::class);
        $company = $context->company();
        /** @var User $actor */
        $actor = auth()->user();

        try {
            $data = DB::transaction(function () use ($company, $actor) {
                app(SalesActorGuard::class)->lockAndAuthorize((int) $company->id, $actor, 'sales.invoice.view');
                app(CommandSessionGuard::class)->authorize($actor);

                $records = CompanyCurrency::where('company_id', $company->id)
                    ->where('enabled', true)
                    ->with('currency')
                    ->orderByDesc('is_base')
                    ->orderBy('display_order')
                    ->get();

                return $records->map(fn ($cc) => [
                    'currency_code' => $cc->currency_code,
                    'name' => $cc->currency?->name,
                    'symbol' => $cc->currency?->symbol,
                    'minor_units' => $cc->currency->minor_units,
                    'is_base' => (bool) $cc->is_base,
                ])->values()->all();
            });
        } catch (AuthorizationException) {
            return $this->problem('Forbidden', 403, 'Actor does not possess permission to view currencies.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.5.3');
        } catch (AuthenticationException) {
            return $this->problem('Unauthorized', 401, 'Session authentication verification failed.', $traceId, 'https://tools.ietf.org/html/rfc7235#section-3.1');
        } catch (Throwable $e) {
            Log::error(get_class($e), ['trace_id' => $traceId]);

            return $this->problem('Internal Server Error', 500, 'An unexpected error occurred during currency lookup.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.6.1');
        }

        return response()->json(['data' => $data], 200, $this->securityHeaders($traceId));
    }

    /**
     * GET /api/v1/companies/{companyId}/lookups/taxes
     * Scoped lookup of active tax rates inside a single Company-first transaction.
     * Note: Schema 2026_10_02_000032 does not have an is_default column on tax_rates.
     */
    public function taxes(Request $request, string $companyId): JsonResponse
    {
        $traceId = $this->resolveTraceId($request);
        $context = app(CompanyContext::class);
        $company = $context->company();
        /** @var User $actor */
        $actor = auth()->user();

        $limit = $this->resolveLimit($request);
        $search = $this->resolveSearch($request);

        try {
            $data = DB::transaction(function () use ($company, $actor, $limit, $search) {
                app(SalesActorGuard::class)->lockAndAuthorize((int) $company->id, $actor, 'sales.invoice.view');
                app(CommandSessionGuard::class)->authorize($actor);

                $query = TaxRate::where('company_id', $company->id)
                    ->where('active', true);

                if ($search !== null) {
                    $query->where(function ($q) use ($search) {
                        $q->where('name_ar', 'like', "%{$search}%")
                            ->orWhere('name_en', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%");
                    });
                }

                $records = $query->orderBy('name_ar')
                    ->limit($limit)
                    ->get(['id', 'public_id', 'code', 'name_ar', 'name_en', 'rate', 'calculation']);

                return $records->map(fn ($tr) => [
                    'id' => (int) $tr->id,
                    'public_id' => $tr->public_id,
                    'code' => $tr->code,
                    'name_ar' => $tr->name_ar,
                    'name_en' => $tr->name_en,
                    'rate' => (string) $tr->rate,
                    'calculation' => (string) $tr->calculation,
                ])->values()->all();
            });
        } catch (AuthorizationException) {
            return $this->problem('Forbidden', 403, 'Actor does not possess permission to view tax rates.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.5.3');
        } catch (AuthenticationException) {
            return $this->problem('Unauthorized', 401, 'Session authentication verification failed.', $traceId, 'https://tools.ietf.org/html/rfc7235#section-3.1');
        } catch (Throwable $e) {
            Log::error(get_class($e), ['trace_id' => $traceId]);

            return $this->problem('Internal Server Error', 500, 'An unexpected error occurred during tax rate lookup.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.6.1');
        }

        return response()->json(['data' => $data], 200, $this->securityHeaders($traceId));
    }

    /**
     * Extract bounded search query (max 100 characters).
     */
    private function resolveSearch(Request $request): ?string
    {
        $search = $request->query('search');
        if (! is_string($search) || trim($search) === '') {
            return null;
        }

        return mb_substr(trim($search), 0, 100);
    }

    /**
     * Extract bounded pagination limit (1..50, default 15).
     */
    private function resolveLimit(Request $request): int
    {
        $limit = $request->query('limit', 15);
        if (! is_numeric($limit)) {
            return 15;
        }

        return min(max((int) $limit, 1), 50);
    }
}
