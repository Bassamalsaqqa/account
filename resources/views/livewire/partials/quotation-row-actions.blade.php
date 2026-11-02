<div class="row-action-group" role="group" aria-label="{{ __('sales.actions') }}">
    <x-row-action data-row-link :href="route('quotations.show', $quote->public_id)" :label="__('workflow.open')" icon="eye" />
    @if($quote->isDraft() && auth()->user()->can('sales.quote.edit'))
        <x-row-action :href="route('quotations.edit', $quote->public_id)" :label="__('workflow.edit')" icon="edit" tone="primary" />
    @endif
    @can('sales.document.pdf')
        <x-row-action :href="route('pdf.quotation', ['publicId' => $quote->public_id, 'locale' => app()->getLocale()])" :label="__('refinement_lists.pdf')" icon="receipt" :new-tab="true" />
    @endcan
</div>
