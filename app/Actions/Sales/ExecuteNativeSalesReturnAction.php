<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Domain\Inventory\Exceptions\IdempotencyConflictException;
use App\Models\Company;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\NativeCommandReceipt;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Money\CurrencyMinorUnitsResolver;
use App\Support\Mobile\Receipt\NativeCommandReceiptManager;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/** Full native return event: admission, draft, posting and receipt share one transaction. */
final class ExecuteNativeSalesReturnAction
{
    /** @param array<array-key, mixed> $lines
     * @return array{outcome: string, status: int, snapshot: array<string, mixed>, receipt: NativeCommandReceipt}
     */
    public function execute(Company $company, User $actor, SalesInvoice $invoice, array $lines, string $key, ?MobileAccessToken $token, ?MobileDevice $device): array
    {
        $lines = SalesReturnInput::normalize($lines);
        usort($lines, fn (array $a, array $b): int => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));

        return app(NativeCommandReceiptManager::class)->execute(
            (int) $company->id, $actor, $token, $device, 'sales_return', $key,
            ['sales_invoice_id' => (string) $invoice->public_id, 'lines' => $lines],
            ['sales.return.create', 'sales.return.post'],
            function (Company $company, User $actor) use ($invoice, $lines): array {
                app(CompanyContext::class)->setCompany($company, $actor);
                $invoice = SalesInvoice::where('company_id', $company->id)->whereKey($invoice->id)
                    ->with('lines.product')->lockForUpdate()->firstOrFail();

                // Guard against lost-ACK duplicate posting for unrecorded legacy returns from old 8c1
                $unrecordedLegacy = SalesReturn::where('company_id', $company->id)
                    ->where('sales_invoice_id', $invoice->id)
                    ->where('status', SalesReturn::STATUS_POSTED)
                    ->where('reason', 'مرتجع مبيعات من تطبيق الجوال')
                    ->whereNotExists(function ($q) use ($company): void {
                        $q->selectRaw('1')->from('native_command_receipts')
                            ->where('company_id', $company->id)
                            ->where('entity_type', 'sales_return')
                            ->whereColumn('entity_id', 'sales_returns.id');
                    })
                    ->first();
                if ($unrecordedLegacy !== null) {
                    throw new IdempotencyConflictException("Sales invoice [{$invoice->public_id}] already has an unrecorded mobile return [{$unrecordedLegacy->public_id}] from a prior version without an idempotency receipt. Use web management or contact support to review return state.");
                }

                $draftLines = [];
                foreach ($lines as $input) {
                    $original = SalesReturnInput::resolveLine($invoice, $input);
                    if (isset($draftLines[$original->id])) {
                        throw new InvalidArgumentException('Repeated original invoice line.');
                    }
                    $draftLines[$original->id] = ['sales_invoice_line_id' => (int) $original->id, 'quantity' => $input['quantity']];
                }
                $draft = app(CreateSalesReturnDraftAction::class)->execute($company, $actor, [
                    'sales_invoice_id' => (int) $invoice->id,
                    'issue_date' => now()->format('Y-m-d'),
                    'reason' => 'مرتجع مبيعات من تطبيق الجوال',
                    'lines' => array_values($draftLines),
                ]);
                $posted = app(PostSalesReturnAction::class)->execute($draft, $actor);

                app(AuditService::class)->log((int) $company->id, 'sales_return.native_posted', 'Native sales return posted', (int) $actor->id, $posted);

                $cmu = app(CurrencyMinorUnitsResolver::class)->resolve((string) $posted->currency_code, $company);

                return ['snapshot' => [
                    'id' => (string) $posted->public_id,
                    'return_number' => (string) $posted->return_number,
                    'status' => (string) $posted->status,
                    'total_amount' => (string) BigDecimal::of((string) $posted->grand_total_currency)->toScale($cmu, RoundingMode::HALF_UP),
                    'total_amount_exact' => (string) BigDecimal::of((string) $posted->grand_total_currency),
                    'currency' => (string) $posted->currency_code,
                    'currency_minor_units' => $cmu,
                    'posted_at' => $posted->posted_at->format('Y-m-d\TH:i:s\Z'),
                ], 'entity_type' => 'sales_return', 'entity_id' => $posted->id, 'entity_public_id' => $posted->public_id];
            });
    }
}
