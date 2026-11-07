<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Sales\DeleteReturnDraftAction;
use App\Application\Platform\TenantWriteAccess;
use App\Models\PurchaseReturn;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Services\Corrections\CorrectionPresentationAccess;
use App\Services\Corrections\TradeCorrection;
use App\Services\Money\MoneyActorGuard;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

trait CorrectsTradeDocument
{
    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $correctionPreview = null;

    public string $correctionMode = 'delete';

    public string $correctionReason = '';

    public string $correctionDate = '';

    public bool $correctionOpen = false;

    abstract protected function correctionFamily(): string;

    abstract protected function correctionPublicId(): string;

    private function correctionSource(): SalesInvoice|SalesReturn|PurchaseReturn
    {
        $company = app(CompanyContext::class)->company();
        $family = $this->correctionFamily();
        $class = TradeCorrection::MODELS[$family];
        $publicId = $this->correctionPublicId();

        return $class::where('company_id', $company->id)->where('public_id', $publicId)->firstOrFail();
    }

    /** @return list<array{label: string, url: string}> */
    #[Computed]
    public function correctionHistory(): array
    {
        $source = $this->correctionSource();
        $family = $this->correctionFamily();

        return (function () use ($source, $family): array {
            $permission = match ($family) {
                'sales_invoice' => 'sales.invoice.view', 'sales_return' => 'sales.return.view', default => 'purchasing.purchase.view'
            };
            app(MoneyActorGuard::class)->authorize((int) $source->company_id, $permission);
            if ($this->correctionPreview !== null) {
                try {
                    CorrectionPresentationAccess::authorize((int) $source->company_id, $this->correctionPreview['presentation_permissions'] ?? null);
                    app(TradeCorrection::class)->authorize($source, auth()->user(), $this->correctionMode, false);
                    if ($family === 'sales_invoice') {
                        $dependentPermissions = [
                            ...(! empty($this->correctionPreview['payments']) ? ['money.receipt.view', 'money.receipt.reverse'] : []),
                            ...(! empty($this->correctionPreview['returns']) ? ['sales.return.view', 'sales.return.void'] : []),
                        ];
                        foreach ($dependentPermissions as $dependentPermission) {
                            app(MoneyActorGuard::class)->authorize((int) $source->company_id, $dependentPermission);
                        }
                    }
                } catch (AuthorizationException) {
                    $this->closeCorrection();
                }
            }
            $receipts = DB::table($family.'_correction_receipts')->where('company_id', $source->company_id)
                ->where(fn ($q) => $q->where('source_id', $source->id)->orWhere('replacement_id', $source->id))->orderBy('id')->get(['source_id', 'replacement_id']);
            $links = [];
            $route = match ($family) {
                'sales_invoice' => 'invoices.show', 'sales_return' => 'returns.show', default => 'purchase-returns.show'
            };
            foreach ($receipts as $receipt) {
                foreach (['source_id' => 'original', 'replacement_id' => 'replacement'] as $field => $label) {
                    if ($receipt->{$field} === null || (int) $receipt->{$field} === (int) $source->id) {
                        continue;
                    }
                    $related = $source->newQuery()->where('company_id', $source->company_id)->findOrFail($receipt->{$field});
                    $links[] = ['label' => __('corrections.'.$label), 'url' => route($route, $related->public_id)];
                }
            }

            return $links;
        })();
    }

    public function deleteReturnDraft(): void
    {
        $source = $this->correctionSource();
        abort_unless($source instanceof SalesReturn || $source instanceof PurchaseReturn, 422);
        app(DeleteReturnDraftAction::class)->execute($source, auth()->user());
        $this->redirectRoute($source instanceof PurchaseReturn ? 'purchase-returns.index' : 'returns.index', navigate: true);
    }

    public function openCorrection(string $mode): void
    {
        $source = $this->correctionSource();
        app(TenantWriteAccess::class)->transaction((int) $source->company_id, fn () => app(TradeCorrection::class)->authorize($source, auth()->user(), $mode));
        abort_unless($source->status === 'posted', 422);
        $this->correctionMode = $mode;
        $this->correctionReason = '';
        $this->correctionDate = now(app(CompanyContext::class)->company()->timezone)->toDateString();
        $this->correctionPreview = null;
        $this->correctionOpen = true;
        $this->resetValidation();
    }

    public function closeCorrection(): void
    {
        $this->correctionOpen = false;
        $this->correctionPreview = null;
    }

    public function previewCorrection(): void
    {
        $this->correctionPreview = null;
        $this->correctionPreview = app(TradeCorrection::class)->preview($this->correctionSource(), auth()->user(), $this->correctionMode, $this->correctionReason, $this->correctionDate);
    }

    public function executeCorrection(): void
    {
        try {
            $result = app(TradeCorrection::class)->execute($this->correctionPreview['token'] ?? '', auth()->user());
            $this->closeCorrection();
            $route = match ($this->correctionFamily()) {
                'sales_invoice' => $result->status === 'draft' ? 'invoices.edit' : 'invoices.show',
                'sales_return' => $result->status === 'draft' ? 'returns.edit' : 'returns.show',
                'purchase_return' => $result->status === 'draft' ? 'purchase-returns.edit' : 'purchase-returns.show',
                default => abort(422),
            };
            $this->redirectRoute($route, $result->public_id, navigate: true);
        } catch (ValidationException $exception) {
            $this->correctionPreview = null;
            throw $exception;
        } catch (AuthorizationException $exception) {
            $this->correctionPreview = null;
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            $this->correctionPreview = null;
            $this->addError('correction', __('corrections.failed'));
        }
    }
}
