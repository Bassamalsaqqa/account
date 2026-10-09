@props(['routeName', 'parameters' => [], 'permissions' => ['sales.document.pdf']])
@if(collect($permissions)->every(fn ($permission) => auth()->user()?->can($permission)))
<details class="relative">
    <summary class="flex min-h-11 cursor-pointer items-center rounded-control border border-border bg-white px-3 text-xs font-bold text-primary focus-visible:outline-2">{{ __('documents.actions') }}</summary>
    <div class="absolute end-0 z-30 mt-2 min-w-64 rounded-card border border-border bg-white p-3 shadow-lg">
        @foreach(['ar' => 'العربية', 'en' => 'English'] as $locale => $label)
            <p class="mt-2 text-xs font-bold" lang="{{ $locale }}">{{ $label }}</p>
            <div class="flex flex-wrap gap-1">
                <a class="flex min-h-11 items-center px-2 text-xs text-primary underline" href="{{ route($routeName, array_merge($parameters, ['locale' => $locale])) }}" target="_blank" rel="noopener">{{ __('documents.view_pdf') }}</a>
                <a class="flex min-h-11 items-center px-2 text-xs text-primary underline" href="{{ route($routeName, array_merge($parameters, ['locale' => $locale, 'format' => 'print'])) }}" target="_blank" rel="noopener">{{ __('documents.print') }}</a>
                <a class="flex min-h-11 items-center px-2 text-xs text-primary underline" href="{{ route($routeName, array_merge($parameters, ['locale' => $locale, 'download' => 1])) }}">{{ __('documents.download_pdf') }}</a>
            </div>
        @endforeach
    </div>
</details>
@endif
