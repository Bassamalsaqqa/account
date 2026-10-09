@props(['routeName', 'parameters' => [], 'permissions' => ['sales.document.pdf']])
@if(collect($permissions)->every(fn ($permission) => auth()->user()?->can($permission)))
<details class="relative" x-data="{
    placeMenu() {
        const menu = this.$refs.menu;
        menu.style.transform = '';
        const bounds = menu.getBoundingClientRect();
        const shift = bounds.left < 16 ? 16 - bounds.left : Math.min(0, window.innerWidth - 16 - bounds.right);
        menu.style.transform = `translateX(${shift}px)`;
    }
}" @toggle="if ($el.open) $nextTick(() => placeMenu())" @resize.window="if ($el.open) placeMenu()" @keydown.escape.stop="$el.removeAttribute('open'); $el.querySelector('summary').focus()">
    <summary class="flex min-h-11 cursor-pointer items-center rounded-control border border-border bg-white px-3 text-xs font-bold text-primary focus-visible:outline-2 focus-visible:outline-primary" aria-haspopup="true">{{ __('documents.actions') }}</summary>
    <div x-ref="menu" class="absolute end-0 z-30 mt-2 min-w-64 max-w-[calc(100vw-2rem)] rounded-card border border-border bg-white p-3 shadow-lg">
        @foreach(['ar' => 'العربية', 'en' => 'English'] as $locale => $label)
            <p class="mt-2 text-xs font-bold text-text-primary" lang="{{ $locale }}">{{ $label }}</p>
            <div class="flex flex-wrap gap-1">
                <a class="flex min-h-11 items-center px-2 text-xs text-primary underline focus-visible:outline-2 focus-visible:outline-primary rounded-sm" href="{{ route($routeName, array_merge($parameters, ['locale' => $locale])) }}" target="_blank" rel="noopener noreferrer" aria-label="{{ __('documents.view_pdf') }} ({{ $label }})">{{ __('documents.view_pdf') }}</a>
                <a class="flex min-h-11 items-center px-2 text-xs text-primary underline focus-visible:outline-2 focus-visible:outline-primary rounded-sm" href="{{ route($routeName, array_merge($parameters, ['locale' => $locale, 'format' => 'print'])) }}" target="_blank" rel="noopener noreferrer" aria-label="{{ __('documents.print') }} ({{ $label }})">{{ __('documents.print') }}</a>
                <a class="flex min-h-11 items-center px-2 text-xs text-primary underline focus-visible:outline-2 focus-visible:outline-primary rounded-sm" href="{{ route($routeName, array_merge($parameters, ['locale' => $locale, 'download' => 1])) }}" aria-label="{{ __('documents.download_pdf') }} ({{ $label }})">{{ __('documents.download_pdf') }}</a>
            </div>
        @endforeach
    </div>
</details>
@endif
