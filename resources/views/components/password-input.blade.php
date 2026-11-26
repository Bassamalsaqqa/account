@props(['disabled' => false])
<div class="relative" x-data="{ revealed: false }">
    <x-text-input type="password" x-bind:type="revealed ? 'text' : 'password'" :disabled="$disabled" {{ $attributes->class(['w-full pe-12']) }} />
    <button type="button" @disabled($disabled) x-on:click="revealed = !revealed"
            x-bind:aria-pressed="revealed" aria-controls="{{ $attributes->get('id') }}"
            x-bind:aria-label="revealed ? @js(__('app.hide_password')) : @js(__('app.show_password'))"
            aria-label="{{ __('app.show_password') }}"
            class="absolute inset-y-0 end-0 min-w-11 flex items-center justify-center rounded-control text-text-secondary hover:text-text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-50">
        <x-icon name="eye" class="size-5" />
    </button>
</div>
