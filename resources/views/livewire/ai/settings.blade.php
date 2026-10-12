<div class="mx-auto max-w-5xl ai-product space-y-6">
    <header>
        <a href="{{ route('settings.index') }}" class="text-sm text-primary">{{ __('app.nav_settings') }}</a>
        <h1 class="mt-2 text-2xl font-bold">{{ __('ai_product.title') }}</h1>
        <p class="mt-2 max-w-prose text-sm leading-relaxed text-text-secondary">{{ __('ai_product.setup_help') }}</p>
    </header>
    @if ($isOwner)
        <livewire:ai.deep-seek-setup />
    @else
        <p class="rounded-card border border-border bg-surface p-4 text-sm">{{ __('ai_product.owner_managed') }}</p>
    @endif
    @if ($usage !== [])
        <section class="rounded-card border border-border bg-surface p-4 sm:p-6">
            <h2 class="text-lg font-bold">{{ __('assistant_v2.actual_usage') }}</h2>
            <p class="mt-2 text-sm text-text-secondary">{{ __('assistant_v2.usage_truth') }}</p>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                @foreach ($usage as $row)
                    <dl class="space-y-1 rounded-control border border-border p-3 text-sm">
                        <dt class="font-bold">{{ __('assistant_v2.'.$row['period']) }} · {{ __('assistant_v2.scope_'.$row['scope']) }}</dt>
                        <dd>{{ __('assistant_v2.requests') }}: <span class="num">{{ $row['requests'] }}</span></dd>
                        @foreach (['estimated', 'reserved', 'reported'] as $field)
                            <dd>{{ __('assistant_v2.cost_'.$field) }}: <span class="num" dir="ltr">{{ $row[$field] === null ? __('assistant_v2.unknown') : $row[$field].' USD' }}</span></dd>
                        @endforeach
                        <dd>{{ __('assistant_v2.unknown_requests') }}: <span class="num">{{ $row['unknown_requests'] }}</span></dd>
                    </dl>
                @endforeach
            </div>
        </section>
    @endif

</div>
