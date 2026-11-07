<div class="row-action-group" role="group" aria-label="{{ __('purchasing.actions') }}">
    <x-row-action data-row-link :href="route('purchases.show', $purchase->public_id)" :label="__('workflow.open')" icon="eye" />
    @if($purchase->isDraft())
        @can('purchasing.purchase.edit_draft')
            <x-row-action :href="route('purchases.edit', $purchase->public_id)" :label="__('workflow.edit')" icon="edit" tone="primary" />
        @endcan
        @can('purchasing.purchase.post')
            <x-row-action :href="route('purchases.show', $purchase->public_id)" wire:click.prevent="postPurchase('{{ $purchase->public_id }}')" wire:loading.attr="disabled" :label="__('purchasing.post_purchase')" icon="check-circle" tone="primary" />
        @endcan
    @elseif($purchase->isPosted() && ($withCost ?? auth()->user()->can('purchasing.cost.view')) && auth()->user()->can('money.vendor_payment.create') && (isset($payablePositions[$purchase->id]) ? \Brick\Math\BigDecimal::of($payablePositions[$purchase->id]->outstanding)->isPositive() : $purchase->payablePosition()->hasOutstanding()))
        <x-row-action :href="route('vendor-payments.create', ['purchase_id' => $purchase->id])" wire:click.prevent="openPaymentModal({{ $purchase->id }})" :label="__('purchasing.pay')" icon="money" tone="primary" />
    @endif
    @can('purchasing.document.pdf')
        <x-row-action :href="route('pdf.purchase', ['publicId' => $purchase->public_id, 'locale' => app()->getLocale()])" :label="__('refinement_lists.pdf')" icon="pdf" :new-tab="true" />
    @endcan
    @if($purchase->isPosted() && ($withCost ?? false) && auth()->user()->can('purchasing.purchase.void'))
        @if(auth()->user()->can('purchasing.purchase.create') && auth()->user()->can('purchasing.purchase.edit_draft'))
            <x-row-action :href="route('purchases.show', ['publicId' => $purchase->public_id, 'correction' => 'edit'])" :label="__('corrections.edit')" icon="edit" />
        @endif
        <x-row-action :href="route('purchases.show', ['publicId' => $purchase->public_id, 'correction' => 'delete'])" :label="__('corrections.delete')" icon="trash" />
    @endif
</div>
