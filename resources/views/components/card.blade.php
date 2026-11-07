@props([
    'padding' => 'p-4',
    'tone' => 'default',
])

@php($surface = match ($tone) { 'info' => 'bg-info-bg border-info/20', 'success' => 'bg-success-bg border-success/20', 'warning' => 'bg-warning-bg border-warning/20', default => 'bg-surface border-border' })

<div {{ $attributes->merge(['class' => "{$surface} border rounded-card shadow-panel {$padding}"]) }}>
    {{ $slot }}
</div>
