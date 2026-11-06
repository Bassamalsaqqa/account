@props(['model', 'controls'])
<label class="inline-flex min-h-11 cursor-pointer items-center gap-3 text-sm font-medium text-text-primary">
    <input type="checkbox" role="switch" x-model="{{ $model }}" aria-controls="{{ $controls }}" class="peer sr-only" />
    <span aria-hidden="true" class="relative h-6 w-11 shrink-0 rounded-full bg-border-strong transition-colors peer-checked:bg-primary peer-focus-visible:ring-2 peer-focus-visible:ring-primary peer-focus-visible:ring-offset-2 after:absolute after:start-0.5 after:top-0.5 after:h-5 after:w-5 after:rounded-full after:bg-surface after:transition-transform peer-checked:after:translate-x-5 rtl:peer-checked:after:-translate-x-5 motion-reduce:transition-none motion-reduce:after:transition-none"></span>
    <span>{{ __('workflow.more_options') }}</span>
</label>
