@props([
    'padding' => 'p-4',
])

<div {{ $attributes->merge(['class' => "bg-white border border-border rounded-card shadow-panel {$padding}"]) }}>
    {{ $slot }}
</div>
