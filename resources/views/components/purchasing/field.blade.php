@props(['name', 'label', 'type' => 'text', 'required' => false, 'direction' => null, 'maxlength' => 255])
<div class="min-w-0">
    <label for="vendor-{{ $name }}" class="block text-sm font-semibold text-text-primary mb-1.5">
        {{ $label }} @if ($required)<span class="text-danger" aria-hidden="true">*</span>@endif
    </label>
    @if ($type === 'textarea')
        <textarea id="vendor-{{ $name }}" @error($name) aria-invalid="true" aria-describedby="vendor-{{ $name }}-error" @enderror wire:model="{{ $name }}" rows="3" maxlength="{{ $maxlength }}" @if ($direction) dir="{{ $direction }}" @endif class="w-full min-h-20 px-3 py-2 border border-border-control rounded-control bg-surface text-base lg:text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary/20 outline-hidden">{{ $slot }}</textarea>
    @else
        <input id="vendor-{{ $name }}" @error($name) aria-invalid="true" aria-describedby="vendor-{{ $name }}-error" @enderror type="{{ $type }}" wire:model="{{ $name }}" maxlength="{{ $maxlength }}" @required($required) @if ($direction) dir="{{ $direction }}" @endif class="w-full min-h-11 lg:min-h-9.5 px-3 border border-border-control rounded-control bg-surface text-base lg:text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary/20 outline-hidden" />
    @endif
    @error($name)<p id="vendor-{{ $name }}-error" class="text-xs text-danger mt-1" role="alert">{{ $message }}</p>@enderror
</div>
