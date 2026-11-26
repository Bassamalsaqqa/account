@props([
    'padding' => 'p-4',
    'tone' => 'default',
])

@php($surface = match ($tone) { 'info' => 'bg-surface border-info/30', 'success' => 'bg-surface border-success/30', 'warning' => 'bg-surface border-warning/30', default => 'bg-surface border-border' })

<div {{ $attributes->merge(['class' => "{$surface} border rounded-card shadow-panel {$padding}"]) }}>
    {{ $slot }}
</div>
