@props(['href' => null, 'label', 'icon', 'tone' => 'neutral', 'newTab' => false, 'shortLabel' => null])
@php($toneClass = match($tone) { 'danger' => 'text-danger', 'primary' => 'text-primary', default => 'text-text-secondary' })
@if($href)
    <a href="{{ $href }}" @if($newTab) target="_blank" rel="noopener noreferrer" @endif title="{{ $label }}" aria-label="{{ $label }}" {{ $attributes->class(['row-action', $toneClass]) }}>
        <x-icon :name="$icon" class="h-4 w-4 shrink-0" aria-hidden="true" /><span class="row-action-label">{{ $shortLabel ?? $label }}</span>
    </a>
@else
    <button type="button" title="{{ $label }}" aria-label="{{ $label }}" {{ $attributes->class(['row-action', $toneClass]) }}>
        <x-icon :name="$icon" class="h-4 w-4 shrink-0" aria-hidden="true" /><span class="row-action-label">{{ $shortLabel ?? $label }}</span>
    </button>
@endif
