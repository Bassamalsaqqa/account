<div class="row-action-group" role="group" aria-label="{{ __('sales.actions') }}">
    <x-row-action data-row-link :href="route('purchases.show', $purchase->public_id)" :label="__('workflow.open')" icon="eye" />
    @if($purchase->isDraft() && auth()->user()->can('purchasing.purchase.edit_draft'))
        <x-row-action :href="route('purchases.edit', $purchase->public_id)" :label="__('workflow.edit')" icon="edit" tone="primary" />
    @endif
    @can('purchasing.document.pdf')
        <x-row-action :href="route('pdf.purchase', ['publicId' => $purchase->public_id, 'locale' => app()->getLocale()])" :label="__('refinement_lists.pdf')" icon="receipt" :new-tab="true" />
    @endcan
</div>
