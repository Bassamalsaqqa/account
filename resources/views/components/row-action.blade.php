@props(['href' => null, 'label', 'icon', 'tone' => 'neutral', 'newTab' => false, 'shortLabel' => null])
@php($toneClass = match($tone) { 'danger' => 'text-danger hover:text-danger-hover', 'primary' => 'text-primary hover:text-primary-hover', default => 'text-text-secondary hover:text-text-primary' })
@if($href)
    <a href="{{ $href }}" @if($newTab) target="_blank" rel="noopener noreferrer" @endif title="{{ $label }}" aria-label="{{ $label }}" {{ $attributes->class(['row-action group', $toneClass]) }}>
        <x-icon :name="$icon" class="h-4 w-4 shrink-0" aria-hidden="true" /><span class="row-action-label">{{ $shortLabel ?? $label }}</span><span class="row-action-tooltip" role="tooltip" aria-hidden="true">{{ $label }}</span>
    </a>
@else
    <button type="button" title="{{ $label }}" aria-label="{{ $label }}" {{ $attributes->class(['row-action group', $toneClass]) }}>
        <x-icon :name="$icon" class="h-4 w-4 shrink-0" aria-hidden="true" /><span class="row-action-label">{{ $shortLabel ?? $label }}</span><span class="row-action-tooltip" role="tooltip" aria-hidden="true">{{ $label }}</span>
    </button>
@endif
