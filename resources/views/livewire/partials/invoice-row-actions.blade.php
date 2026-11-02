<div class="row-action-group" role="group" aria-label="{{ __('sales.actions') }}">
    <x-row-action data-row-link :href="route('invoices.show', $inv->public_id)" :label="__('workflow.open')" icon="eye" />
    @if($inv->isDraft() && auth()->user()->can('sales.invoice.edit_draft'))
        <x-row-action :href="route('invoices.edit', $inv->public_id)" :label="__('workflow.edit')" icon="edit" tone="primary" />
        @if(auth()->user()->hasRole('Owner') && $inv->quotation_id === null)
            <x-row-action :label="__('sales.delete_draft')" :short-label="__('workflow.delete')" icon="trash" tone="danger" wire:click="deleteDraft('{{ $inv->public_id }}')" wire:confirm="{{ __('sales.delete_draft_confirm') }}" wire:loading.attr="disabled" />
        @endif
    @elseif($inv->isPosted() && auth()->user()->can('money.receipt.create') && \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive($summaries[$inv->id]['outstanding']))
        <x-row-action :href="route('payments.create', ['invoice_id' => $inv->id])" :label="__('mobile.record_payment')" icon="plus" tone="primary" />
    @endif
    @can('sales.document.pdf')
        <x-row-action :href="route('pdf.invoice', ['publicId' => $inv->public_id, 'locale' => app()->getLocale()])" :label="__('refinement_lists.pdf')" icon="receipt" :new-tab="true" />
    @endcan
</div>
