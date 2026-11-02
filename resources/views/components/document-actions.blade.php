@props(['routeName', 'parameters' => [], 'permissions' => ['sales.document.pdf']])
@if(collect($permissions)->every(fn ($permission) => auth()->user()?->can($permission)))
    <a href="{{ route($routeName, array_merge($parameters, ['locale' => app()->getLocale()])) }}"
       target="_blank" rel="noopener noreferrer"
       class="touch-action inline-flex min-h-11 items-center justify-center gap-1.5 rounded-control border border-border bg-white px-3 text-xs font-semibold text-primary hover:bg-surface-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">
        <x-icon name="print" class="h-4 w-4" />
        <span>{{ __('documents.print') }}</span>
    </a>
@endif
