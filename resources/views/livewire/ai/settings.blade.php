<div class="mx-auto max-w-5xl ai-product space-y-6">
    <header>
        <a href="{{ route('settings.index') }}" class="text-sm text-primary">{{ __('app.nav_settings') }}</a>
        <h1 class="mt-2 text-2xl font-bold">{{ __('ai_product.title') }}</h1>
        <p class="mt-2 max-w-prose text-sm leading-relaxed text-text-secondary">{{ __('ai_product.setup_help') }}</p>
    </header>
    @if ($isOwner)
        <livewire:ai.deep-seek-setup />
        <details class="rounded-card border border-border bg-surface p-4 sm:p-6">
            <summary class="cursor-pointer font-bold">{{ __('assistant_policy.title') }}</summary>
            <p class="mt-3 text-sm leading-relaxed text-text-secondary">{{ __('assistant_policy.help') }}</p>
            @if ($reportPolicyRevision !== null)
                <form class="mt-4 space-y-4" x-data="{ password: '', busy: false }"
                    @submit.prevent="if (!busy) { busy = true; const pending = $wire.saveReportPolicy(password); password = ''; pending.finally(() => busy = false); }">
                    <fieldset class="grid gap-3 sm:grid-cols-2">
                        <legend class="sr-only">{{ __('assistant_policy.title') }}</legend>
                        @foreach ($reportOptions as $key => $label)
                            <label class="flex items-start gap-3 text-sm">
                                <input type="checkbox" wire:model="reportAllowlist" value="{{ $key }}" class="mt-0.5 rounded border-border text-primary focus:ring-primary" />
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </fieldset>
                    <p class="text-sm text-text-secondary">{{ __('assistant_policy.consent_help') }}</p>
                    <label class="block max-w-sm text-sm">
                        <span>{{ __('assistant_policy.password') }}</span>
                        <input type="password" x-model="password" autocomplete="current-password" class="mt-1 block w-full rounded-control border border-border bg-background px-3 py-2" />
                    </label>
                    <div class="flex flex-wrap gap-3">
                        <button type="submit" :disabled="busy" wire:loading.attr="disabled" wire:target="saveReportPolicy" class="rounded-control bg-primary px-4 py-2 text-sm font-bold text-white">{{ __('assistant_policy.save') }}</button>
                        <button type="button" wire:click="refreshReportPolicy" class="rounded-control border border-border px-4 py-2 text-sm">{{ __('assistant_policy.reload') }}</button>
                    </div>
                </form>
            @else
                <p class="mt-3 text-sm text-text-secondary">{{ __('assistant_policy.setup_first') }}</p>
                <button type="button" wire:click="refreshReportPolicy" class="mt-3 text-sm text-primary">{{ __('assistant_policy.reload') }}</button>
            @endif
            @if ($reportPolicyMessage !== null)
                <p role="status" class="mt-3 text-sm">{{ $reportPolicyMessage }}</p>
            @endif
        </details>
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
