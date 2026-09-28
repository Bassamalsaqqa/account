@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-semibold text-sm text-text-secondary']) }}>
    {{ $value ?? $slot }}
</label>
