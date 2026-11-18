<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Actions\Expenses\PostExpenseAction;
use App\Http\Controllers\Controller;
use App\Http\Middleware\MobileApiBoundary;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\MoneyAccount;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
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

            return [
                'id' => (string) $e->public_id,
                'expense_number' => (string) ($e->expense_number ?? $e->public_id),
                'date' => $e->expense_date ? $e->expense_date->format('Y-m-d') : now()->format('Y-m-d'),
                'title' => (string) ($e->description ?? $e->category?->name_ar ?? 'مصروف تشغيلي'),
                'category_name' => (string) ($e->category?->name_ar ?? 'مصاريف عامة'),
                'amount_ils' => (string) $amount->toScale(2, RoundingMode::HALF_UP),
                'paid_from' => (string) ($e->moneyAccount?->name_ar ?? 'الصندوق الرئيسي'),
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
    public function store(Request $request, string $companyId, PostExpenseAction $postExpenseAction): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        if (! $user->hasPermissionTo('money.expense.manage')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required money.expense.manage permission.',
                status: 403
            );
        }

        $amountInput = $request->input('amount');
        if ($amountInput === null || ! is_scalar($amountInput) || BigDecimal::of((string) $amountInput)->isNegativeOrZero()) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Expense amount must be a strictly positive decimal.',
                status: 422
            );
        }

        $categoryPublicId = $request->input('category_id');
        $category = null;
        if ($categoryPublicId !== null) {
            $category = ExpenseCategory::where('company_id', $company->id)
                ->where(function ($q) use ($categoryPublicId): void {
                    $q->where('public_id', $categoryPublicId)
                        ->orWhere('id', $categoryPublicId);
                })
                ->first();
        }

        if ($category === null) {
            $category = ExpenseCategory::where('company_id', $company->id)->where('active', true)->first();
        }

        if ($category === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'No active expense category configured for company.',
                status: 422
            );
        }

        $cashAccount = MoneyAccount::where('company_id', $company->id)
            ->where('account_type', MoneyAccount::TYPE_CASH)
            ->where('is_active', true)
            ->first();

        if ($cashAccount === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'No active cash money account configured for company.',
                status: 422
            );
        }

        $idempotencyKey = (string) $request->header('Idempotency-Key', 'exp-'.uniqid('', true));

        $expenseData = [
            'idempotency_key' => $idempotencyKey,
            'expense_date' => (string) ($request->input('expense_date', now()->format('Y-m-d'))),
            'classification' => 'operating',
            'expense_category_id' => $category->id,
            'money_account_id' => $cashAccount->id,
            'currency_code' => $company->base_currency_code,
            'exchange_rate' => '1.0000000000',
            'amount' => (string) BigDecimal::of((string) $amountInput),
            'description' => (string) ($request->input('description', 'مصروف تشغيلي من تطبيق الجوال')),
            'payment_method' => 'cash',
        ];

        try {
            app(CompanyContext::class)->setCompany($company, $user);
            $expense = $postExpenseAction->execute($company, $user, $expenseData);
        } catch (Throwable $e) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Failed to record expense: '.$e->getMessage(),
                status: 422
            );
        }

        return response()->json([
            'id' => (string) $expense->public_id,
            'expense_number' => (string) ($expense->expense_number ?? $expense->public_id),
            'amount_ils' => (string) BigDecimal::of((string) $expense->amount)->toScale(2, RoundingMode::HALF_UP),
            'currency' => (string) $expense->currency_code,
            'posted_at' => $expense->expense_date ? $expense->expense_date->format('Y-m-d\TH:i:s\Z') : now()->format('Y-m-d\TH:i:s\Z'),
        ], 201, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }
}
