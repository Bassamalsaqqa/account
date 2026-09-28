@props([
    'variant' => 'default', // default, alert, warn, success, info
])

@php
$classes = match($variant) {
    'alert', 'danger' => 'bg-danger-bg text-danger',
    'warn', 'warning' => 'bg-warning-bg text-warning',
    'success' => 'bg-success-bg text-success',
    'info', 'primary' => 'bg-primary-50 text-primary',
    default => 'bg-[#eef1f6] text-[#58667a]',
};
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center justify-center px-2 py-0.5 rounded-full text-[10px] font-bold tracking-tight {$classes}"]) }}>
    {{ $slot }}
</span>
