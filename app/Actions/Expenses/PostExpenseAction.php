<?php

declare(strict_types=1);

namespace App\Actions\Expenses;

use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Domain\Posting\DTO\PostingCommand;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Check;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\DocumentSequence;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\LedgerAccount;
use App\Models\MoneyAccount;
use App\Models\PostingBatch;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Audit\AuditService;
use App\Services\Expenses\ExpenseAttachment;
use App\Services\Expenses\ExpenseLedger;
use App\Services\Money\CheckPaymentSource;
use App\Services\Money\MoneyAccountLedger;
use App\Services\Money\MoneyActorGuard;
use App\Services\Money\MoneyEventScope;
use App\Services\Money\MoneyValues;
use App\Services\Phase7\OwnsPhase7Event;
use App\Services\Phase7\Phase7EventOwner;
use App\Services\Phase7\Phase7History;
use App\Services\Purchasing\PurchaseIdentitySnapshot;
use App\Services\Sales\DocumentSequenceService;
use App\Services\Sales\ReceiptRequestValues;
use App\Services\Sales\SalesDocumentRules;
use App\Services\Sales\SalesPostingLines;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PostExpenseAction implements Phase7EventOwner
{
    use OwnsPhase7Event;

    public function __construct(
        private readonly DocumentSequenceService $sequenceService,
        private readonly MoneyAccountLedger $moneyAccountLedger,
        private readonly CheckPaymentSource $checkPaymentSource,
        private readonly PurchaseIdentitySnapshot $vendorSnapshot,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(Company $company, User $actor, array $data): Expense
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || (int) $context->companyId() !== (int) $company->id) {
            throw new NoActiveCompanyException("Active company context does not match company [{$company->id}].");
        }

        if (! auth()->check() || (int) auth()->id() !== (int) $actor->id) {
            throw new AuthorizationException('Actor does not match authenticated user.');
        }

        if (! $actor->belongsToCompany($company->id)) {
            throw new AuthorizationException("User does not belong to company [{$company->id}].");
        }

        setPermissionsTeamId($company->id);
        if (! $actor->hasPermissionTo('money.expense.manage')) {
            throw new AuthorizationException('User does not have permission to manage expenses.');
        }

        $idempotencyKey = ReceiptRequestValues::key($data['idempotency_key'] ?? null);
        $expenseDate = (string) ($data['expense_date'] ?? '');
        app(SalesDocumentRules::class)->date($expenseDate);

        $classification = (string) ($data['classification'] ?? 'operating');
        if (! in_array($classification, ['operating', 'landed_cost'], true)) {
            throw new InvalidArgumentException("Expense classification [{$classification}] is invalid. Must be operating or landed_cost.");
        }

        if ($classification === 'landed_cost') {
            if (! $actor->hasPermissionTo('purchasing.cost.view') || ! $actor->hasPermissionTo('purchasing.landed_cost.manage')) {
                throw new AuthorizationException('User does not have permission to record landed cost expenses.');
            }
        }

        $categoryId = ReceiptRequestValues::id($data['category_id'] ?? null);
        $vendorId = ! empty($data['vendor_id']) ? ReceiptRequestValues::id($data['vendor_id']) : null;
        $payeeName = MoneyValues::text($data['payee_name'] ?? null, 255);
        $description = MoneyValues::text($data['description'] ?? null);
        if ($description === null || trim($description) === '') {
            throw new InvalidArgumentException('Expense description is required.');
        }

        $currencyCode = (string) ($data['currency_code'] ?? '');
        $paymentMethod = (string) ($data['payment_method'] ?? '');
        if (! in_array($paymentMethod, ['cash', 'bank', 'check'], true)) {
            throw new InvalidArgumentException("Payment method [{$paymentMethod}] is not supported. Use cash, bank, or check.");
        }

        $moneyAccountId = null;
        $checkId = null;
        if ($paymentMethod === 'check') {
            $checkId = ReceiptRequestValues::id($data['check_id'] ?? null);
        } else {
            $moneyAccountId = ReceiptRequestValues::id($data['money_account_id'] ?? null);
        }

        $amount = MoneyValues::amount($data['amount'] ?? '0', $currencyCode);
        $exchangeRate = MoneyValues::rate($data['exchange_rate'] ?? '1', $currencyCode, $company->base_currency_code);
        $baseAmount = MoneyValues::base($amount, $exchangeRate);

        $notes = MoneyValues::text($data['notes'] ?? null, 2000);
        $attachmentPath = isset($data['attachment_path']) ? (string) $data['attachment_path'] : null;
        $attachmentName = isset($data['attachment_name']) ? (string) $data['attachment_name'] : null;
        $attachmentMime = isset($data['attachment_mime']) ? (string) $data['attachment_mime'] : null;
        $attachmentSize = isset($data['attachment_size']) ? (int) $data['attachment_size'] : null;

        app(ExpenseAttachment::class)->validate((int) $company->id, $attachmentPath, $attachmentName, $attachmentMime, $attachmentSize);

        $canonicalPayload = [
            'company_id' => (int) $company->id,
            'actor_id' => (int) $actor->id,
            'category_id' => $categoryId,
            'vendor_id' => $vendorId,
            'payee_name' => $payeeName,
            'classification' => $classification,
            'description' => $description,
            'currency_code' => $currencyCode,
            'amount' => (string) $amount,
            'exchange_rate' => (string) $exchangeRate,
            'base_amount' => (string) $baseAmount,
            'payment_method' => $paymentMethod,
            'money_account_id' => $moneyAccountId,
            'check_id' => $checkId,
            'expense_date' => $expenseDate,
            'notes' => $notes,
        ];
        $requestHash = hash('sha256', json_encode($canonicalPayload, JSON_THROW_ON_ERROR));

        return $this->canonicalTransaction((int) $company->id, $actor, function () use (
            $company,
            $actor,
            $idempotencyKey,
            $requestHash,
            $categoryId,
            $vendorId,
            $payeeName,
            $classification,
            $description,
            $currencyCode,
            $amount,
            $exchangeRate,
            $baseAmount,
            $paymentMethod,
            $moneyAccountId,
            $checkId,
            $expenseDate,
            $notes,
            $attachmentPath,
            $attachmentName,
            $attachmentMime,
            $attachmentSize
        ): Expense {
            $lockedCompany = Company::where('id', $company->id)->lockForUpdate()->firstOrFail();
            app(MoneyActorGuard::class)->authorize((int) $lockedCompany->id, 'money.expense.manage');
            if ($classification === 'landed_cost') {
                app(MoneyActorGuard::class)->authorize((int) $lockedCompany->id, 'purchasing.cost.view');
                app(MoneyActorGuard::class)->authorize((int) $lockedCompany->id, 'purchasing.landed_cost.manage');
            }

            // Idempotency check
            $existing = Expense::where('company_id', $lockedCompany->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->request_hash !== $requestHash) {
                    throw new IdempotencyConflictException("Idempotency key [{$idempotencyKey}] was already used with different expense parameters.");
                }

                app(Phase7History::class)->validate($existing);

                return $existing->load(['category', 'vendor', 'moneyAccount', 'postingBatch']);
            }

            if (! CompanyCurrency::where('company_id', $lockedCompany->id)->where('currency_code', $currencyCode)->where('enabled', true)->exists()) {
                throw new InvalidArgumentException("Expense currency [{$currencyCode}] is not enabled for this company.");
            }

            $category = ExpenseCategory::where('company_id', $lockedCompany->id)->lockForUpdate()->findOrFail($categoryId);
            if (! $category->active) {
                throw new InvalidArgumentException("Expense category [{$category->code}] is inactive.");
            }

            $vendor = null;
            $vendorSnapshotData = null;
            if ($vendorId !== null) {
                $vendor = Vendor::withTrashed()->where('company_id', $lockedCompany->id)->lockForUpdate()->findOrFail($vendorId);
                $vendorSnapshotData = $this->vendorSnapshot->vendor($vendor);
            }

            $check = null;
            $cashLedgerAccount = null;
            if ($paymentMethod === 'check') {
                $check = $this->checkPaymentSource->instrumentForExpense(
                    (int) $lockedCompany->id,
                    $actor,
                    ['check_id' => $checkId, 'expense_date' => $expenseDate, 'currency_code' => $currencyCode, 'amount' => (string) $amount, 'exchange_rate' => (string) $exchangeRate, 'vendor_id' => $vendorId]
                );
                $cashLedgerAccount = $this->checkPaymentSource->ledger($check);
            } else {
                $moneyAccount = MoneyAccount::where('company_id', $lockedCompany->id)->lockForUpdate()->findOrFail($moneyAccountId);
                $cashLedgerAccount = $this->moneyAccountLedger->validate($moneyAccount, true);
                if ($moneyAccount->currency_code !== $currencyCode) {
                    throw new InvalidArgumentException("Money account currency [{$moneyAccount->currency_code}] does not match expense currency [{$currencyCode}].");
                }
                if ($paymentMethod === 'cash' && $moneyAccount->account_type !== 'cash') {
                    throw new InvalidArgumentException("Money account [{$moneyAccount->id}] is not a cash account.");
                }
                if ($paymentMethod === 'bank' && $moneyAccount->account_type !== 'bank') {
                    throw new InvalidArgumentException("Money account [{$moneyAccount->id}] is not a bank account.");
                }
            }

            app(ExpenseLedger::class)->validate(LedgerAccount::where('company_id', $lockedCompany->id)->findOrFail($category->ledger_account_id), true);

            // Consume expense document sequence
            $year = (int) Carbon::parse($expenseDate)->format('Y');
            $expenseNumber = $this->sequenceService->generateNextNumber((int) $lockedCompany->id, DocumentSequence::TYPE_EXPENSE, $year);

            $categorySnapshotData = [
                'id' => (int) $category->id,
                'code' => $category->code,
                'name_ar' => $category->name_ar,
                'name_en' => $category->name_en,
                'ledger_account_id' => (int) $category->ledger_account_id,
            ];

            // Build double-entry posting command
            $lines = [];
            $zero = MoneyAmount::from('0');

            // Line 1: Debit expense account (or landed cost clearing if landed_cost classification)
            $debitAccountId = (int) $category->ledger_account_id;
            $descSuffix = $category->name_ar;
            if ($classification === Expense::CLASSIFICATION_LANDED_COST) {
                $clearingAccount = LedgerAccount::where('company_id', $lockedCompany->id)
                    ->where('system_key', 'landed_cost_clearing')
                    ->firstOrFail();
                $debitAccountId = (int) $clearingAccount->id;
                $descSuffix = 'تكلفة شحن وتخليص إضافية';
            }

            app(SalesPostingLines::class)->append(
                $lines,
                1,
                $debitAccountId,
                MoneyAmount::from($baseAmount),
                $zero,
                $currencyCode,
                MoneyAmount::from($amount),
                ExchangeRate::from($exchangeRate),
                "Expense {$expenseNumber} - {$descSuffix}"
            );

            // Line 2: Credit payment account (Cash/Bank or Checks Issued)
            app(SalesPostingLines::class)->append(
                $lines,
                2,
                (int) $cashLedgerAccount->id,
                $zero,
                MoneyAmount::from($baseAmount),
                $currencyCode,
                MoneyAmount::from($amount),
                ExchangeRate::from($exchangeRate),
                "Expense {$expenseNumber} payment"
            );

            // Reserve provisional ID
            $provisionalPublicId = (string) Str::ulid();

            // Create preliminary expense record to establish foreign key for posting batch
            $expense = new Expense([
                'public_id' => $provisionalPublicId,
                'company_id' => (int) $lockedCompany->id,
                'expense_number' => $expenseNumber,
                'expense_date' => $expenseDate,
                'category_id' => (int) $category->id,
                'category_snapshot' => $categorySnapshotData,
                'vendor_id' => $vendor?->id,
                'vendor_snapshot' => $vendorSnapshotData,
                'payee_name' => $payeeName,
                'classification' => $classification,
                'description' => $description,
                'currency_code' => $currencyCode,
                'amount' => (string) $amount,
                'exchange_rate' => (string) $exchangeRate,
                'base_amount' => (string) $baseAmount,
                'payment_method' => $paymentMethod,
                'money_account_id' => $moneyAccountId,
                'check_id' => $check?->id,
                'attachment_path' => $attachmentPath,
                'attachment_name' => $attachmentName,
                'attachment_mime' => $attachmentMime,
                'attachment_size' => $attachmentSize,
                'notes' => $notes,
                'posting_batch_id' => null, // temporary, updated below
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by' => (int) $actor->id,
                'request_hash' => $requestHash,
                'idempotency_key' => $idempotencyKey,
                'created_by' => (int) $actor->id,
            ]);
            $this->persistPhase7($expense);

            $postingCommand = new PostingCommand(
                $lockedCompany,
                Carbon::parse($expenseDate),
                'expense',
                (int) $expense->id,
                $currencyCode,
                $lockedCompany->base_currency_code,
                ExchangeRate::from($exchangeRate),
                "expense:{$expense->public_id}",
                $actor,
                "Expense {$expenseNumber}",
                lines: $lines
            );

            if ($paymentMethod === 'check') {
                app(MoneyEventScope::class)->prepareCheckPaymentCommand($postingCommand, (int) $check->id, 'outgoing');
            }

            /** @var PostingBatch $batch */
            $batch = $this->postPhase7($postingCommand);

            $expense->posting_batch_id = (int) $batch->id;
            $this->persistPhase7($expense);

            app(AuditService::class)->log(
                (int) $lockedCompany->id,
                'expense.posted',
                "Posted expense {$expenseNumber}",
                (int) $actor->id,
                $expense,
                meta: [
                    'expense_id' => $expense->id,
                    'expense_number' => $expenseNumber,
                    'amount' => (string) $amount,
                    'currency' => $currencyCode,
                    'classification' => $classification,
                    'payment_method' => $paymentMethod,
                ]
            );

            app(Phase7History::class)->validate($expense);

            return $expense->load(['category', 'vendor', 'moneyAccount', 'postingBatch']);
        });
    }
}
