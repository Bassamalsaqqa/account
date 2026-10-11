<?php

declare(strict_types=1);

namespace App\Application\Commands;

use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\UpdateSalesInvoiceDraftAction;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Domain\Sales\Queries\CustomerCreditLimitQuery;
use App\Models\CommandReceipt;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LedgerAccount;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Sales\DraftInvoiceIntegrity;
use App\Services\Sales\SalesActorGuard;
use App\Services\Sales\SalesDocumentRules;
use App\Services\Security\CommandSessionGuard;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class SalesInvoiceCommands
{
    public function __construct(
        protected SalesDraftIntent $intent,
        protected StockAvailabilityQuery $stockAvailabilityQuery,
        protected CreateSalesInvoiceDraftAction $createDraftAction,
        protected UpdateSalesInvoiceDraftAction $updateDraftAction,
        protected PostSalesInvoiceAction $postInvoiceAction,
        protected DraftInvoiceIntegrity $draftIntegrity,
        protected SalesActorGuard $actorGuard,
        protected CustomerCreditLimitQuery $creditLimitQuery,
    ) {}

    /**
     * Create a sales invoice draft with atomic receipt persistence and idempotency.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws HttpException
     * @throws InvalidArgumentException
     * @throws AuthorizationException
     */
    public function create(Company $company, User $actor, array $payload, string $key): SalesInvoice
    {
        $this->validateIdempotencyKey($key);

        $canonicalPayload = $this->intent->canonicalize($payload, isUpdate: false);
        $requestHash = $this->intent->canonicalHash($canonicalPayload);

        return DB::transaction(function () use ($company, $actor, $canonicalPayload, $requestHash, $key): SalesInvoice {
            $context = app(CompanyContext::class);
            if (! $context->hasCompany() || (int) $context->companyId() !== (int) $company->id) {
                throw new AuthorizationException("Active company context does not match target company [{$company->id}].");
            }

            Company::where('id', $company->id)->lockForUpdate()->firstOrFail();
            $this->actorGuard->lockAndAuthorize((int) $company->id, $actor, 'sales.invoice.create');
            $this->authorizeApiSession($actor);
            $this->actorGuard->lockAndAuthorize((int) $company->id, $actor, 'sales.invoice.view');

            $receipt = CommandReceipt::where('company_id', $company->id)
                ->where('user_id', $actor->id)
                ->where('action', 'sales_invoice.create')
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();

            if ($receipt !== null) {
                if ($receipt->request_hash !== $requestHash) {
                    throw new HttpException(409, 'Idempotency key reused with different payload.');
                }

                // If draft was deleted (ADR0004 tombstone), return 409 Gone rather than recreating
                if ($receipt->sales_invoice_id === null) {
                    throw new HttpException(409, 'Invoice associated with this idempotency receipt has been deleted.');
                }

                /** @var SalesInvoice|null $invoice */
                $invoice = SalesInvoice::where('company_id', $company->id)->where('id', $receipt->sales_invoice_id)->first();
                if ($invoice === null) {
                    throw new HttpException(409, 'Invoice associated with this idempotency receipt has been deleted.');
                }

                return $invoice->fresh(['lines', 'customer']);
            }

            $invoice = $this->createDraftAction->execute($company, $actor, $canonicalPayload);

            CommandReceipt::create([
                'company_id' => $company->id,
                'user_id' => $actor->id,
                'action' => 'sales_invoice.create',
                'idempotency_key' => $key,
                'target_invoice_id' => null,
                'sales_invoice_id' => $invoice->id,
                'request_hash' => $requestHash,
            ]);

            return $invoice;
        });
    }

    /**
     * Update an existing sales invoice draft with atomic receipt persistence and idempotency.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws HttpException
     * @throws InvalidArgumentException
     * @throws AuthorizationException
     */
    public function update(SalesInvoice $invoice, User $actor, array $payload, string $key): SalesInvoice
    {
        $this->validateIdempotencyKey($key);

        $canonicalPayload = $this->intent->canonicalize($payload, isUpdate: true);
        $requestHash = $this->intent->canonicalHash($canonicalPayload, (int) $invoice->id);

        return DB::transaction(function () use ($invoice, $actor, $canonicalPayload, $requestHash, $key): SalesInvoice {
            $context = app(CompanyContext::class);
            if (! $context->hasCompany() || (int) $context->companyId() !== (int) $invoice->company_id) {
                throw new AuthorizationException("Active company context does not match invoice company [{$invoice->company_id}].");
            }

            Company::where('id', $invoice->company_id)->lockForUpdate()->firstOrFail();
            $this->actorGuard->lockAndAuthorize((int) $invoice->company_id, $actor, 'sales.invoice.edit_draft');
            $this->authorizeApiSession($actor);
            $this->actorGuard->lockAndAuthorize((int) $invoice->company_id, $actor, 'sales.invoice.view');

            $receipt = CommandReceipt::where('company_id', $invoice->company_id)
                ->where('user_id', $actor->id)
                ->where('action', 'sales_invoice.update')
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();

            if ($receipt !== null) {
                if ((int) $receipt->target_invoice_id !== (int) $invoice->id) {
                    throw new HttpException(409, 'Idempotency key reused with different target invoice.');
                }
                if ($receipt->request_hash !== $requestHash) {
                    throw new HttpException(409, 'Idempotency key reused with different payload.');
                }

                if ($receipt->sales_invoice_id === null) {
                    throw new HttpException(409, 'Invoice associated with this idempotency receipt has been deleted.');
                }

                /** @var SalesInvoice|null $res */
                $res = SalesInvoice::where('company_id', $invoice->company_id)->where('id', $receipt->sales_invoice_id)->first();
                if ($res === null) {
                    throw new HttpException(409, 'Invoice associated with this idempotency receipt has been deleted.');
                }

                return $res->fresh(['lines', 'customer']);
            }

            $updatedInvoice = $this->updateDraftAction->execute($invoice, $actor, $canonicalPayload);

            CommandReceipt::create([
                'company_id' => $invoice->company_id,
                'user_id' => $actor->id,
                'action' => 'sales_invoice.update',
                'idempotency_key' => $key,
                'target_invoice_id' => $invoice->id,
                'sales_invoice_id' => $updatedInvoice->id,
                'request_hash' => $requestHash,
            ]);

            return $updatedInvoice;
        });
    }

    /**
     * Read-only preview of a draft sales invoice wrapped in Company-first transaction.
     * Enforces fresh membership and permissions for BOTH view and post, verifies
     * draft status under row locks, reuses canonical rules, and binds displayed context.
     *
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     */
    public function preview(SalesInvoice $invoice, User $actor): array
    {
        return DB::transaction(function () use ($invoice, $actor): array {
            $context = app(CompanyContext::class);
            if (! $context->hasCompany() || (int) $context->companyId() !== (int) $invoice->company_id) {
                throw new AuthorizationException("Active company context does not match invoice company [{$invoice->company_id}].");
            }

            Company::where('id', $invoice->company_id)->lockForUpdate()->firstOrFail();
            $this->actorGuard->lockAndAuthorize((int) $invoice->company_id, $actor, 'sales.invoice.view');
            $this->actorGuard->lockAndAuthorize((int) $invoice->company_id, $actor, 'sales.invoice.post');
            $this->authorizeApiSession($actor);

            /** @var SalesInvoice $lockedInvoice */
            $lockedInvoice = SalesInvoice::where('company_id', $invoice->company_id)
                ->where('id', $invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedInvoice->isDraft()) {
                throw new InvalidArgumentException("Cannot preview sales invoice in [{$lockedInvoice->status}] status. Only drafts can be previewed.");
            }

            return $this->buildPreviewData($lockedInvoice, $actor, lockRows: true);
        });
    }

    /**
     * Post a draft sales invoice with explicit confirmation token validation and atomic receipt persistence.
     * Fresh RBAC check for BOTH view and post precedes receipt lookup / exact replay.
     *
     * @throws HttpException
     * @throws InvalidArgumentException
     * @throws AuthorizationException
     */
    public function execute(SalesInvoice $invoice, User $actor, string $confirmationToken, string $key): SalesInvoice
    {
        $this->validateIdempotencyKey($key);

        if (trim($confirmationToken) === '') {
            throw new InvalidArgumentException('Confirmation token is required to execute invoice posting.');
        }

        if (strlen($confirmationToken) > 4096) {
            throw new InvalidArgumentException('Confirmation token exceeds maximum allowed length.');
        }

        return DB::transaction(function () use ($invoice, $actor, $confirmationToken, $key): SalesInvoice {
            $context = app(CompanyContext::class);
            if (! $context->hasCompany() || (int) $context->companyId() !== (int) $invoice->company_id) {
                throw new AuthorizationException("Active company context does not match invoice company [{$invoice->company_id}].");
            }

            Company::where('id', $invoice->company_id)->lockForUpdate()->firstOrFail();
            // Fresh RBAC recheck for BOTH view and post before exact replay
            $this->actorGuard->lockAndAuthorize((int) $invoice->company_id, $actor, 'sales.invoice.view');
            $this->actorGuard->lockAndAuthorize((int) $invoice->company_id, $actor, 'sales.invoice.post');
            $this->authorizeApiSession($actor);

            $receipt = CommandReceipt::where('company_id', $invoice->company_id)
                ->where('user_id', $actor->id)
                ->where('action', 'sales_invoice.execute')
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();

            $tokenHash = hash('sha256', $invoice->id.'|'.$confirmationToken);

            if ($receipt !== null) {
                if ((int) $receipt->target_invoice_id !== (int) $invoice->id) {
                    throw new HttpException(409, 'Idempotency key reused with different target invoice.');
                }
                if ($receipt->request_hash !== $tokenHash) {
                    throw new HttpException(409, 'Idempotency key reused with different confirmation token.');
                }

                if ($receipt->sales_invoice_id === null) {
                    throw new HttpException(409, 'Invoice associated with this idempotency receipt has been deleted.');
                }

                /** @var SalesInvoice|null $res */
                $res = SalesInvoice::where('company_id', $invoice->company_id)->where('id', $receipt->sales_invoice_id)->first();
                if ($res === null) {
                    throw new HttpException(409, 'Invoice associated with this idempotency receipt has been deleted.');
                }

                return $res->fresh(['lines', 'lotAllocations', 'postingBatch', 'customer']);
            }

            // Decrypt confirmation token
            try {
                $tokenPayload = json_decode(Crypt::decryptString($confirmationToken), true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                throw new InvalidArgumentException('Invalid or tampered confirmation token.');
            }

            if (! is_array($tokenPayload)) {
                throw new InvalidArgumentException('Invalid confirmation token payload.');
            }

            // Validate token binding
            if (($tokenPayload['company_id'] ?? null) !== (int) $invoice->company_id) {
                throw new AuthorizationException('Confirmation token company does not match invoice company.');
            }
            if (($tokenPayload['actor_id'] ?? null) !== (int) $actor->id) {
                throw new AuthorizationException('Confirmation token actor does not match current actor.');
            }
            if (($tokenPayload['invoice_id'] ?? null) !== (int) $invoice->id || ($tokenPayload['invoice_public_id'] ?? null) !== (string) $invoice->public_id) {
                throw new InvalidArgumentException('Confirmation token target does not match invoice.');
            }
            if (($tokenPayload['expires_at'] ?? 0) <= now()->timestamp) {
                throw new HttpException(409, 'Confirmation token has expired.');
            }

            // Lock target invoice FOR UPDATE
            /** @var SalesInvoice $lockedInvoice */
            $lockedInvoice = SalesInvoice::where('company_id', $invoice->company_id)->where('id', $invoice->id)->lockForUpdate()->firstOrFail();
            if (! $lockedInvoice->isDraft()) {
                throw new InvalidArgumentException("Cannot post sales invoice in [{$lockedInvoice->status}] status. Only drafts can be posted.");
            }

            // Recompute preview under current locks
            $currentPreview = $this->buildPreviewData($lockedInvoice, $actor, lockRows: true);

            if (! empty($currentPreview['blockers'])) {
                throw new HttpException(409, 'Invoice execution blocked: '.$currentPreview['blockers'][0]['message']);
            }

            if ($currentPreview['draft_revision'] !== ($tokenPayload['draft_revision'] ?? null)) {
                throw new HttpException(409, 'Draft has been modified since preview.');
            }

            if ($currentPreview['preview_revision'] !== ($tokenPayload['preview_revision'] ?? null)) {
                throw new HttpException(409, 'Inventory, tax, or pricing context has changed since preview.');
            }

            // Dispatch canonical post action
            $postedInvoice = $this->postInvoiceAction->execute($lockedInvoice, $actor);

            // Record receipt atomically
            CommandReceipt::create([
                'company_id' => $invoice->company_id,
                'user_id' => $actor->id,
                'action' => 'sales_invoice.execute',
                'idempotency_key' => $key,
                'target_invoice_id' => $lockedInvoice->id,
                'sales_invoice_id' => $postedInvoice->id,
                'request_hash' => $tokenHash,
            ]);

            return $postedInvoice;
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildPreviewData(SalesInvoice $invoice, User $actor, bool $lockRows): array
    {
        $invQuery = SalesInvoice::where('company_id', $invoice->company_id)->where('id', $invoice->id);
        if ($lockRows) {
            $invQuery->lockForUpdate();
        }
        /** @var SalesInvoice $inv */
        $inv = $invQuery->firstOrFail();

        $linesQuery = $inv->lines()->orderBy('line_number');
        if ($lockRows) {
            $linesQuery->lockForUpdate();
        }
        /** @var Collection<int, SalesInvoiceLine> $lines */
        $lines = $linesQuery->get();

        if ($lines->isEmpty()) {
            throw new InvalidArgumentException('Sales invoice must contain at least one line.');
        }

        // Canonical integrity validation
        $this->draftIntegrity->validate($inv, $lines);

        $blockers = [];
        $warnings = [];

        $issueDate = $inv->issue_date instanceof DateTimeInterface
            ? $inv->issue_date->format('Y-m-d')
            : (string) $inv->issue_date;

        // Canonical header validation
        app(SalesDocumentRules::class)->header(
            $inv->company,
            $inv->currency_code,
            BigDecimal::of((string) $inv->exchange_rate),
            $issueDate,
            $inv->document_locale
        );

        // Customer verification
        $custQuery = Customer::where('company_id', $inv->company_id)->whereKey($inv->customer_id);
        if ($lockRows) {
            $custQuery->lockForUpdate();
        }
        /** @var Customer|null $customer */
        $customer = $custQuery->first();

        if ($customer === null || ! $customer->active) {
            $blockers[] = [
                'code' => 'customer_inactive',
                'message' => "Customer [{$inv->customer_id}] is invalid or inactive.",
            ];
        }

        // Warehouse verification
        $warehouse = null;
        if ($inv->warehouse_id !== null) {
            $whQuery = Warehouse::where('company_id', $inv->company_id)->whereKey($inv->warehouse_id);
            if ($lockRows) {
                $whQuery->lockForUpdate();
            }
            /** @var Warehouse|null $warehouse */
            $warehouse = $whQuery->first();
        }

        // Product, unit, pricing, discount, and tax canonical rule verification
        foreach ($lines as $line) {
            if ($line->product_id !== null) {
                $pQuery = Product::where('company_id', $inv->company_id)->whereKey($line->product_id);
                if ($lockRows) {
                    $pQuery->lockForUpdate();
                }
                /** @var Product|null $prod */
                $prod = $pQuery->first();
                if ($prod === null || ! $prod->active) {
                    $blockers[] = [
                        'code' => 'product_inactive',
                        'message' => 'Product is missing or inactive.',
                        'product_id' => $line->product_id,
                    ];
                } else {
                    try {
                        $unit = app(SalesDocumentRules::class)->selectedUnit(
                            $prod,
                            $line->product_unit_id,
                            Quantity::of((string) $line->quantity)
                        );

                        if (! BigDecimal::of((string) $unit->conversion_to_base)->isEqualTo(BigDecimal::of((string) $line->unit_conversion_ratio))) {
                            $blockers[] = [
                                'code' => 'unit_conversion_changed',
                                'message' => 'Draft unit conversion changed; edit and verify the draft before posting.',
                            ];
                        }

                        app(SalesDocumentRules::class)->price(
                            $prod,
                            $unit,
                            $actor,
                            BigDecimal::of((string) $inv->exchange_rate),
                            $inv->currency_code,
                            BigDecimal::of((string) $line->unit_price)
                        );
                    } catch (AuthorizationException $e) {
                        // Authorization exceptions must remain 403, never caught as blockers
                        throw $e;
                    } catch (InvalidArgumentException $e) {
                        $blockers[] = [
                            'code' => 'unit_or_pricing_invalid',
                            'message' => $e->getMessage(),
                        ];
                    }
                }
            } else {
                if (! $actor->hasPermissionTo('sales.invoice.change_price')) {
                    throw new AuthorizationException('Manual service price requires price-change permission.');
                }
            }

            if (BigDecimal::of((string) $line->line_discount)->isPositive() && ! $actor->hasPermissionTo('sales.invoice.change_discount')) {
                throw new AuthorizationException('Discount permission is required to post this draft.');
            }

            if ($line->tax_rate_id !== null) {
                /** @var TaxRate|null $tr */
                $tr = TaxRate::where('company_id', $inv->company_id)->whereKey($line->tax_rate_id)->first();
                if ($tr === null || ! $tr->active) {
                    $blockers[] = [
                        'code' => 'tax_rate_inactive',
                        'message' => 'Tax rate is missing or inactive.',
                    ];
                } elseif ($line->tax_rate_snapshot === null
                    || ! BigDecimal::of((string) $tr->rate)->isEqualTo(BigDecimal::of((string) $line->tax_rate_snapshot))
                    || ($tr->calculation === TaxRate::CALC_INCLUSIVE) !== (bool) $line->tax_inclusive) {
                    $blockers[] = [
                        'code' => 'tax_snapshot_changed',
                        'message' => 'Draft tax configuration changed; refresh the draft before posting.',
                    ];
                }
            }
        }

        // Stock availability and FEFO projection
        $requirements = [];
        foreach ($lines as $line) {
            if ($line->product_id !== null) {
                $pQuery = Product::where('company_id', $inv->company_id)->whereKey($line->product_id);
                if ($lockRows) {
                    $pQuery->lockForUpdate();
                }
                /** @var Product|null $product */
                $product = $pQuery->first();
                if ($product !== null && $product->track_stock) {
                    $requirements[] = [
                        'product' => $product,
                        'quantity_base' => BigDecimal::of((string) $line->quantity_base),
                    ];
                }
            }
        }

        $stockResult = $this->stockAvailabilityQuery->check(
            $inv->company,
            $warehouse,
            $requirements,
            $issueDate
        );

        foreach ($stockResult['blockers'] as $sb) {
            $blockers[] = $sb;
        }

        // Advisory warnings (never blockers)
        if ($customer !== null) {
            try {
                if ($this->creditLimitQuery->exceeds($customer, BigDecimal::of((string) $inv->grand_total_base))) {
                    $warnings[] = [
                        'code' => 'credit_limit_exceeded',
                        'message' => 'Invoice grand total exceeds customer credit limit.',
                    ];
                }
            } catch (Throwable) {
                $warnings[] = [
                    'code' => 'credit_limit_check_unavailable',
                    'message' => 'Customer credit limit check unavailable.',
                ];
            }
        }

        if ($inv->due_date === null) {
            $warnings[] = [
                'code' => 'no_due_date',
                'message' => 'Invoice has no due date.',
            ];
        }

        // Economic revisions
        $draftRevision = $this->computeDraftRevision($inv, $lines);
        $previewRevision = $this->computePreviewRevision($draftRevision, $stockResult, $inv, $customer, $warehouse, $lines);

        // Confirmation token using now()->timestamp with 300-second TTL
        $createdAt = now()->timestamp;
        $expiresAt = $createdAt + 300;
        $tokenPayload = [
            'company_id' => (int) $inv->company_id,
            'actor_id' => (int) $actor->id,
            'invoice_id' => (int) $inv->id,
            'invoice_public_id' => (string) $inv->public_id,
            'draft_revision' => $draftRevision,
            'preview_revision' => $previewRevision,
            'created_at' => $createdAt,
            'expires_at' => $expiresAt,
        ];
        $confirmationToken = Crypt::encryptString(json_encode($tokenPayload, JSON_THROW_ON_ERROR));

        // Format whitelisted preview lines without cost/cogs
        $previewLines = [];
        foreach ($lines as $l) {
            $previewLines[] = [
                'line_number' => (int) $l->line_number,
                'product_id' => $l->product_id ? (int) $l->product_id : null,
                'product_sku' => $l->product_sku,
                'product_name_ar' => $l->product_name_ar,
                'product_name_en' => $l->product_name_en,
                'item_description' => $l->item_description,
                'quantity' => (string) $l->quantity,
                'quantity_base' => (string) $l->quantity_base,
                'unit_name_ar' => $l->unit_name_ar,
                'unit_name_en' => $l->unit_name_en,
                'unit_conversion_ratio' => (string) $l->unit_conversion_ratio,
                'unit_price' => (string) $l->unit_price,
                'discount_type' => $l->discount_type,
                'discount_value' => (string) $l->discount_value,
                'tax_rate_id' => $l->tax_rate_id ? (int) $l->tax_rate_id : null,
                'tax_rate_snapshot' => $l->tax_rate_snapshot !== null ? (string) $l->tax_rate_snapshot : null,
                'tax_inclusive' => (bool) $l->tax_inclusive,
                'line_subtotal' => (string) $l->line_subtotal,
                'line_discount' => (string) $l->line_discount,
                'line_tax' => (string) $l->line_tax,
                'line_total' => (string) $l->line_total,
                'line_subtotal_base' => (string) $l->line_subtotal_base,
                'line_discount_base' => (string) $l->line_discount_base,
                'line_tax_base' => (string) $l->line_tax_base,
                'line_total_base' => (string) $l->line_total_base,
            ];
        }

        $dueDateStr = null;
        if ($inv->due_date !== null) {
            $dueDateStr = $inv->due_date instanceof DateTimeInterface
                ? $inv->due_date->format('Y-m-d')
                : (string) $inv->due_date;
        }

        return [
            'invoice' => [
                'public_id' => (string) $inv->public_id,
                'status' => (string) $inv->status,
                'issue_date' => $issueDate,
                'due_date' => $dueDateStr,
                'currency_code' => (string) $inv->currency_code,
                'exchange_rate' => (string) $inv->exchange_rate,
                'exchange_rate_source' => 'draft supplied rate, canonical validated; no market quote',
                'document_locale' => (string) $inv->document_locale,
                'notes' => $inv->notes,
                'terms' => $inv->terms,
                'customer' => $customer !== null ? [
                    'id' => (int) $customer->id,
                    'name_ar' => $customer->name_ar,
                    'name_en' => $customer->name_en,
                    'business_name' => $customer->business_name,
                ] : null,
                'warehouse' => $warehouse !== null ? [
                    'id' => (int) $warehouse->id,
                    'name_ar' => $warehouse->name_ar,
                    'name_en' => $warehouse->name_en,
                ] : null,
            ],
            'totals' => [
                'subtotal_currency' => (string) $inv->subtotal_currency,
                'discount_total_currency' => (string) $inv->discount_total_currency,
                'tax_total_currency' => (string) $inv->tax_total_currency,
                'grand_total_currency' => (string) $inv->grand_total_currency,
                'subtotal_base' => (string) $inv->subtotal_base,
                'discount_total_base' => (string) $inv->discount_total_base,
                'tax_total_base' => (string) $inv->tax_total_base,
                'grand_total_base' => (string) $inv->grand_total_base,
            ],
            'lines' => $previewLines,
            'stock_availability' => [
                'disclaimer' => $stockResult['disclaimer'],
                'products' => $stockResult['products'],
            ],
            'blockers' => $blockers,
            'warnings' => $warnings,
            'can_execute' => empty($blockers),
            'draft_revision' => $draftRevision,
            'preview_revision' => $previewRevision,
            'confirmation_token' => $confirmationToken,
            'expires_at' => Carbon::createFromTimestamp($expiresAt)->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, SalesInvoiceLine>  $lines
     */
    protected function computeDraftRevision(SalesInvoice $invoice, Collection $lines): string
    {
        $issueDate = $invoice->issue_date instanceof DateTimeInterface
            ? $invoice->issue_date->format('Y-m-d')
            : (string) $invoice->issue_date;

        $dueDate = null;
        if ($invoice->due_date !== null) {
            $dueDate = $invoice->due_date instanceof DateTimeInterface
                ? $invoice->due_date->format('Y-m-d')
                : (string) $invoice->due_date;
        }

        $data = [
            'company_id' => (int) $invoice->company_id,
            'customer_id' => (int) $invoice->customer_id,
            'warehouse_id' => $invoice->warehouse_id ? (int) $invoice->warehouse_id : null,
            'currency_code' => (string) $invoice->currency_code,
            'exchange_rate' => (string) $invoice->exchange_rate,
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'document_locale' => (string) $invoice->document_locale,
            'notes' => (string) ($invoice->notes ?? ''),
            'terms' => (string) ($invoice->terms ?? ''),
            'subtotal_currency' => (string) $invoice->subtotal_currency,
            'discount_total_currency' => (string) $invoice->discount_total_currency,
            'tax_total_currency' => (string) $invoice->tax_total_currency,
            'grand_total_currency' => (string) $invoice->grand_total_currency,
            'subtotal_base' => (string) $invoice->subtotal_base,
            'discount_total_base' => (string) $invoice->discount_total_base,
            'tax_total_base' => (string) $invoice->tax_total_base,
            'grand_total_base' => (string) $invoice->grand_total_base,
            'lines' => $lines->sortBy('line_number')->values()->map(fn (SalesInvoiceLine $l) => [
                'line_number' => (int) $l->line_number,
                'product_id' => $l->product_id ? (int) $l->product_id : null,
                'product_unit_id' => $l->product_unit_id ? (int) $l->product_unit_id : null,
                'item_description' => (string) $l->item_description,
                'quantity' => (string) $l->quantity,
                'quantity_base' => (string) $l->quantity_base,
                'unit_price' => (string) $l->unit_price,
                'discount_type' => $l->discount_type,
                'discount_value' => (string) $l->discount_value,
                'tax_rate_id' => $l->tax_rate_id ? (int) $l->tax_rate_id : null,
                'tax_rate_snapshot' => $l->tax_rate_snapshot !== null ? (string) $l->tax_rate_snapshot : null,
                'tax_inclusive' => (bool) $l->tax_inclusive,
                'line_subtotal' => (string) $l->line_subtotal,
                'line_discount' => (string) $l->line_discount,
                'line_tax' => (string) $l->line_tax,
                'line_total' => (string) $l->line_total,
                'unit_conversion_ratio' => (string) $l->unit_conversion_ratio,
            ])->all(),
        ];

        return hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * Bind all displayed party identity, warehouse identity, product status/suggested pricing,
     * unit fractional rules and conversion, and tax routing/status into the preview revision.
     *
     * @param  array{disclaimer: string, products: list<mixed>, blockers: list<mixed>}  $stockResult
     * @param  Collection<int, SalesInvoiceLine>  $lines
     */
    protected function computePreviewRevision(
        string $draftRevision,
        array $stockResult,
        SalesInvoice $invoice,
        ?Customer $customer,
        ?Warehouse $warehouse,
        Collection $lines
    ): string {
        $company = $invoice->company;

        $companyIdentity = [
            'name_ar' => (string) $company->name_ar,
            'name_en' => (string) ($company->name_en ?? ''),
            'legal_name_ar' => (string) ($company->legal_name_ar ?? ''),
            'legal_name_en' => (string) ($company->legal_name_en ?? ''),
            'phone' => (string) ($company->phone ?? ''),
            'email' => (string) ($company->email ?? ''),
            'address_ar' => (string) ($company->address_ar ?? ''),
            'address_en' => (string) ($company->address_en ?? ''),
            'tax_number' => (string) ($company->tax_number ?? ''),
            'registration_number' => (string) ($company->registration_number ?? ''),
            'base_currency_code' => (string) $company->base_currency_code,
        ];

        $customerIdentity = $customer ? [
            'id' => (int) $customer->id,
            'name_ar' => (string) $customer->name_ar,
            'name_en' => (string) ($customer->name_en ?? ''),
            'business_name_ar' => (string) ($customer->business_name_ar ?? ''),
            'business_name_en' => (string) ($customer->business_name_en ?? ''),
            'business_name' => (string) ($customer->business_name ?? ''),
            'address_ar' => (string) ($customer->address_ar ?? ''),
            'address_en' => (string) ($customer->address_en ?? ''),
            'address_line_1_ar' => (string) ($customer->address_line_1_ar ?? ''),
            'address_line_1_en' => (string) ($customer->address_line_1_en ?? ''),
            'tax_number' => (string) ($customer->tax_number ?? ''),
            'phone' => (string) ($customer->phone ?? ''),
            'email' => (string) ($customer->email ?? ''),
            'active' => (bool) $customer->active,
        ] : null;

        $warehouseIdentity = $warehouse ? [
            'id' => (int) $warehouse->id,
            'name_ar' => (string) $warehouse->name_ar,
            'name_en' => (string) ($warehouse->name_en ?? ''),
            'active' => (bool) $warehouse->active,
        ] : null;

        // Account routing config used by canonical posting
        $systemAccounts = LedgerAccount::where('company_id', $company->id)
            ->whereIn('system_key', ['accounts_receivable', 'sales_revenue', 'tax_output', 'cogs', 'inventory'])
            ->get(['id', 'system_key', 'active'])
            ->keyBy('system_key')
            ->map(fn ($acc) => ['id' => (int) $acc->id, 'active' => (bool) $acc->active])
            ->all();

        $productsContext = [];
        $unitsContext = [];
        $taxesContext = [];

        foreach ($lines as $line) {
            if ($line->product_id !== null) {
                /** @var Product|null $p */
                $p = Product::where('company_id', $invoice->company_id)->whereKey($line->product_id)->first();
                if ($p !== null) {
                    $productsContext[$p->id] = [
                        'id' => (int) $p->id,
                        'active' => (bool) $p->active,
                        'name_ar' => (string) $p->name_ar,
                        'name_en' => (string) ($p->name_en ?? ''),
                        'sku' => (string) ($p->sku ?? ''),
                        'default_sale_price_base' => (string) ($p->default_sale_price_base ?? ''),
                        'track_stock' => (bool) $p->track_stock,
                        'track_expiry' => (bool) $p->track_expiry,
                    ];
                }
            }

            if ($line->product_unit_id !== null) {
                /** @var ProductUnit|null $pu */
                $pu = ProductUnit::where('company_id', $invoice->company_id)->whereKey($line->product_unit_id)->first();
                if ($pu !== null) {
                    $unitModel = $pu->unit;
                    $unitsContext[$pu->id] = [
                        'id' => (int) $pu->id,
                        'active' => (bool) $pu->active,
                        'conversion_to_base' => (string) $pu->conversion_to_base,
                        'default_sale_price_base' => (string) ($pu->default_sale_price_base ?? ''),
                        'unit_name_ar' => $unitModel?->name_ar,
                        'unit_name_en' => $unitModel?->name_en,
                        'is_default_sale' => (bool) $pu->is_default_sale,
                        'allows_fraction' => $unitModel ? (bool) $unitModel->allows_fraction : false,
                    ];
                }
            }

            if ($line->tax_rate_id !== null) {
                /** @var TaxRate|null $tr */
                $tr = TaxRate::where('company_id', $invoice->company_id)->whereKey($line->tax_rate_id)->first();
                if ($tr !== null) {
                    $taxesContext[$tr->id] = [
                        'id' => (int) $tr->id,
                        'active' => (bool) $tr->active,
                        'rate' => (string) $tr->rate,
                        'calculation' => (string) $tr->calculation,
                        'sales_tax_account_id' => $tr->sales_tax_account_id ? (int) $tr->sales_tax_account_id : null,
                    ];
                }
            }
        }

        $stockFingerprint = [];
        foreach ($stockResult['products'] as $sp) {
            $stockFingerprint[] = [
                'product_id' => $sp['product_id'],
                'track_stock' => $sp['track_stock'],
                'track_expiry' => $sp['track_expiry'],
                'quantity_needed_base' => $sp['quantity_needed_base'],
                'quantity_available_base' => $sp['quantity_available_base'],
                'is_sufficient' => $sp['is_sufficient'],
                'fefo_allocations' => $sp['fefo_allocations'],
            ];
        }

        $previewContext = [
            'draft_revision' => $draftRevision,
            'company_identity' => $companyIdentity,
            'customer_identity' => $customerIdentity,
            'warehouse_identity' => $warehouseIdentity,
            'system_accounts' => $systemAccounts,
            'products_context' => $productsContext,
            'units_context' => $unitsContext,
            'taxes_context' => $taxesContext,
            'stock_fingerprint' => $stockFingerprint,
        ];

        return hash('sha256', json_encode($previewContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function authorizeApiSession(User $actor): void
    {
        if (request()->attributes->has('phase11_api_session')) {
            app(CommandSessionGuard::class)->authorize($actor);
        }
    }

    private function validateIdempotencyKey(string $key): void
    {
        if ($key === '' || strlen($key) > 128 || ! preg_match('/^[A-Za-z0-9_\-\.:]{1,128}$/', $key)) {
            throw new InvalidArgumentException('Malformed idempotency key.');
        }
    }
}
