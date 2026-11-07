@props([
    'variant' => 'default', // default, alert, warn, success, info, draft, posted, void, partial, paid
])

@php
$classes = match($variant) {
    'alert', 'danger', 'void' => 'bg-danger-bg text-danger border-danger/20',
    'warn', 'warning', 'pending' => 'bg-warning-bg text-warning border-warning/20',
    'success', 'posted', 'paid' => 'bg-success-bg text-success border-success/20',
    'info', 'primary', 'partial' => 'bg-primary-50 text-primary border-primary/20',
    'draft' => 'bg-surface-soft text-text-muted border-border',
    default => 'bg-surface-soft text-text-secondary border-border',
};
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center justify-center px-2.5 py-0.5 rounded-full text-xs font-bold tracking-tight border {$classes}"]) }}>
    {{ $slot }}
</span>
