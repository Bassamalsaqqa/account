<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Actions\Expenses\PostExpenseAction;
use App\Domain\Inventory\Exceptions\IdempotencyConflictException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\MobileApiBoundary;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\User;
use App\Services\Money\CurrencyMinorUnitsResolver;
use App\Support\Mobile\Receipt\NativeCommandReceiptManager;
use App\Support\Mobile\Receipt\NativeWriteContextResolver;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class ExpenseController extends Controller
{
    /**
     * GET /api/mobile/v1/companies/{companyId}/expenses
     */
    public function index(Request $request, string $companyId): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        if (! $user->hasPermissionTo('money.expense.view') && ! $user->hasPermissionTo('money.expense.manage')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required money.expense.view permission.',
                status: 403
            );
        }

        $limit = min((int) ($request->query('limit', 50)), 100);
        $today = now()->format('Y-m-d');

        $query = Expense::where('company_id', $company->id)
            ->with(['category', 'moneyAccount']);

        // Filter by shift (today) vs archive
        $filter = $request->query('filter');
        if ($filter === 'today' || $filter === 'shift') {
            $query->whereDate('expense_date', $today);
        } elseif ($filter === 'archive') {
            $query->whereDate('expense_date', '<', $today);
        }

        $records = $query->orderBy('id', 'desc')
            ->limit($limit)
            ->get();

        $expenses = $records->map(function (Expense $e): array {
            $amount = BigDecimal::of((string) $e->amount);
            $catName = $e->category ? (string) $e->category->name_ar : 'مصاريف عامة';
            $paidFrom = $e->moneyAccount ? (string) $e->moneyAccount->name_ar : 'الصندوق الرئيسي';
            $title = (string) ($e->description ?? $catName);

            return [
                'id' => (string) $e->public_id,
                'expense_number' => (string) ($e->expense_number ?? $e->public_id),
                'date' => $e->expense_date->format('Y-m-d'),
                'title' => $title,
                'category_name' => $catName,
                'amount_ils' => (string) $amount->toScale(2, RoundingMode::HALF_UP),
                'paid_from' => $paidFrom,
                'currency' => (string) $e->currency_code,
            ];
        })->values()->all();

        return response()->json([
            'company_id' => $company->public_id,
            'count' => count($expenses),
            'expenses' => $expenses,
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    /**
     * GET /api/mobile/v1/companies/{companyId}/expenses/categories
     */
    public function categories(Request $request, string $companyId): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        if (! $user->hasPermissionTo('money.expense.view') && ! $user->hasPermissionTo('money.expense.manage')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required money.expense.view permission.',
                status: 403
            );
        }

        $categories = ExpenseCategory::where('company_id', $company->id)
            ->where('active', true)
            ->orderBy('id', 'asc')
            ->get()
            ->map(fn (ExpenseCategory $c) => [
                'id' => (string) $c->public_id,
                'name_ar' => $c->name_ar,
                'name_en' => $c->name_en,
                'code' => $c->code,
            ])
            ->values()
            ->all();

        return response()->json([
            'company_id' => $company->public_id,
            'categories' => $categories,
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    /**
     * POST /api/mobile/v1/companies/{companyId}/expenses
     */
    /**
     * POST /api/mobile/v1/companies/{companyId}/expenses
     */
    public function store(
        Request $request,
        string $companyId,
        PostExpenseAction $postExpenseAction,
        NativeCommandReceiptManager $receiptManager,
        NativeWriteContextResolver $contextResolver
    ): JsonResponse {
        $rawKey = $request->header('Idempotency-Key') ?? $request->header('X-Idempotency-Key');
        $idempotencyKey = is_string($rawKey) ? trim($rawKey) : '';
        if ($idempotencyKey === '') {
            return MobileApiBoundary::buildErrorResponse(
                code: 'MISSING_IDEMPOTENCY_KEY',
                message: 'Idempotency-Key header is required for this operation.',
                status: 422
            );
        }

        /** @var Company|null $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User|null $user */
        $user = $request->attributes->get('mobile_user');
        /** @var MobileAccessToken|null $token */
        $token = $request->attributes->get('mobile_token');
        /** @var MobileDevice|null $device */
        $device = $request->attributes->get('mobile_device');

        if ($company === null || $user === null || $token === null || $device === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'UNAUTHENTICATED',
                message: 'Authentication required or token expired.',
                status: 401
            );
        }

        try {
            $amount = NativeWriteContextResolver::validateDecimalString(
                $request->input('amount'),
                'amount',
                6,
                true
            );
        } catch (Throwable $e) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: $e->getMessage(),
                status: 422
            );
        }

        $categoryPublicId = $request->input('expense_category_id') ?? $request->input('category_id');
        if (! is_string($categoryPublicId) || trim($categoryPublicId) === '') {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Valid active expense category ID is required.',
                status: 422
            );
        }

        $rawDescription = $request->input('description');
        if (! is_string($rawDescription) || trim($rawDescription) === '') {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Expense description is required.',
                status: 422
            );
        }
        $description = trim($rawDescription);

        try {
            $expenseDate = $request->has('expense_date')
                ? NativeWriteContextResolver::validateDate($request->input('expense_date'), 'expense_date')
                : now()->format('Y-m-d');
        } catch (Throwable $e) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: $e->getMessage(),
                status: 422
            );
        }

        $rawAccountId = $request->input('cash_account_id', $request->input('money_account_id'));
        $notes = $request->has('notes') && is_string($request->input('notes')) ? trim($request->input('notes')) : null;

        $canonicalPayload = [
            'actor_id' => (int) $user->id,
            'device_id' => (string) $device->device_id,
            'category_id' => trim($categoryPublicId),
            'cash_account_id' => is_string($rawAccountId) ? trim($rawAccountId) : null,
            'amount' => (string) BigDecimal::of($amount)->toScale(6, RoundingMode::HALF_UP),
            'currency' => (string) $company->base_currency_code,
            'description' => $description,
            'expense_date' => $expenseDate,
            'notes' => $notes,
        ];

        try {
            $result = $receiptManager->execute(
                companyId: (int) $company->id,
                actor: $user,
                token: $token,
                device: $device,
                commandType: 'expense',
                idempotencyKey: $idempotencyKey,
                canonicalPayload: $canonicalPayload,
                requiredPermissions: ['money.expense.manage'],
                executor: function (Company $lockedCompany, User $lockedActor, MobileDevice $lockedDevice, MobileAccessToken $lockedToken) use (
                    $categoryPublicId,
                    $amount,
                    $description,
                    $expenseDate,
                    $rawAccountId,
                    $notes,
                    $idempotencyKey,
                    $postExpenseAction,
                    $contextResolver
                ): array {
                    $category = $contextResolver->resolveExpenseCategory($lockedCompany, $categoryPublicId);
                    $cashAccount = $contextResolver->resolveCashAccount(
                        $lockedCompany,
                        is_string($rawAccountId) ? $rawAccountId : null
                    );

                    app(CompanyContext::class)->setCompany($lockedCompany, $lockedActor);

                    $expenseData = [
                        'idempotency_key' => $idempotencyKey,
                        'expense_date' => $expenseDate,
                        'classification' => 'operating',
                        'category_id' => (int) $category->id,
                        'money_account_id' => (int) $cashAccount->id,
                        'currency_code' => (string) $lockedCompany->base_currency_code,
                        'exchange_rate' => '1.0000000000',
                        'amount' => $amount,
                        'description' => $description,
                        'payment_method' => 'cash',
                        'notes' => $notes,
                    ];

                    $expense = $postExpenseAction->execute($lockedCompany, $lockedActor, $expenseData);

                    $minorUnits = app(CurrencyMinorUnitsResolver::class)->resolveForCompany(
                        (int) $lockedCompany->id,
                        (string) $expense->currency_code
                    );

                    $snapshot = [
                        'id' => (string) $expense->public_id,
                        'expense_number' => (string) ($expense->expense_number ?? $expense->public_id),
                        'amount' => (string) BigDecimal::of((string) $expense->amount)->toScale($minorUnits, RoundingMode::HALF_UP),
                        'amount_ils' => (string) BigDecimal::of((string) $expense->amount)->toScale($minorUnits, RoundingMode::HALF_UP),
                        'currency' => (string) $expense->currency_code,
                        'posted_at' => $expense->expense_date->format('Y-m-d\TH:i:s\Z'),
                        'category_id' => (string) $category->public_id,
                        'expense_category_id' => (string) $category->public_id,
                        'category_name' => (string) ($category->name_ar ?? $category->name_en),
                        'description' => (string) $expense->description,
                        'success' => true,
                    ];

                    return [
                        'snapshot' => $snapshot,
                        'entity_type' => 'expense',
                        'entity_id' => (int) $expense->id,
                        'entity_public_id' => (string) $expense->public_id,
                    ];
                }
            );

            $headers = [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
                'Idempotency-Key' => $idempotencyKey,
            ];
            if ($result['outcome'] === 'replay') {
                $headers['X-Idempotent-Replay'] = 'true';
            }

            return response()->json($result['snapshot'], $result['status'], $headers);
        } catch (AuthenticationException $e) {
            return MobileApiBoundary::buildErrorResponse(code: 'UNAUTHENTICATED', message: $e->getMessage(), status: 401);
        } catch (AuthorizationException $e) {
            return MobileApiBoundary::buildErrorResponse(code: 'FORBIDDEN', message: $e->getMessage(), status: 403);
        } catch (IdempotencyConflictException $e) {
            return MobileApiBoundary::buildErrorResponse(code: 'IDEMPOTENCY_KEY_CONFLICT', message: $e->getMessage(), status: 409);
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'not found')) {
                return MobileApiBoundary::buildErrorResponse(code: 'NOT_FOUND', message: $e->getMessage(), status: 404);
            }

            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Failed to record expense: '.$e->getMessage(),
                status: 422
            );
        }
    }
}
