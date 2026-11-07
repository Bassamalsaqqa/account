@props(['family', 'publicId', 'status', 'detail' => false])
@php
    $permissions = match($family) {
        'sales_invoice' => ['sales.invoice.view', 'sales.invoice.void'],
        'sales_return' => ['sales.return.view', 'sales.return.void'],
        'purchase_return' => ['purchasing.purchase.view', 'purchasing.return.void', 'purchasing.cost.view'],
    };
    $editPermissions = match($family) {
        'sales_invoice' => ['sales.invoice.create', 'sales.invoice.edit_draft'],
        'sales_return' => ['sales.return.create', 'sales.return.manage', 'sales.invoice.view'],
        'purchase_return' => ['purchasing.return.manage'],
    };
    $route = match($family) { 'sales_invoice' => 'invoices.show', 'sales_return' => 'returns.show', 'purchase_return' => 'purchase-returns.show' };
@endphp
@if($status === 'posted' && collect($permissions)->every(fn ($p) => auth()->user()->can($p)))
    @if(collect($editPermissions)->every(fn ($p) => auth()->user()->can($p)))
        @if($detail)
            <x-row-action wire:click="openCorrection('edit')" wire:loading.attr="disabled" :label="__('corrections.edit')" icon="edit" tone="primary" />
        @else
            <x-row-action :href="route($route, ['publicId' => $publicId, 'correction' => 'edit'])" :label="__('corrections.edit')" icon="edit" tone="primary" />
        @endif
    @endif
    @if($detail)
        <x-row-action wire:click="openCorrection('delete')" wire:loading.attr="disabled" :label="__('corrections.delete')" icon="trash" tone="danger" />
    @else
        <x-row-action :href="route($route, ['publicId' => $publicId, 'correction' => 'delete'])" :label="__('corrections.delete')" icon="trash" tone="danger" />
    @endif
@endif
