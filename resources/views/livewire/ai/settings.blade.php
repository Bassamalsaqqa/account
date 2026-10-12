<div class="mx-auto max-w-5xl space-y-6">
    <header>
        <a href="{{ route('settings.index') }}" class="text-sm text-primary">{{ __('app.nav_settings') }}</a>
        <h1 class="mt-2 text-2xl font-bold">{{ __('assistant_v2.settings_title') }}</h1>
        <p class="mt-2 max-w-prose text-sm leading-relaxed text-text-secondary">{{ __('assistant_v2.settings_help') }}</p>
    </header>
    @if (session('ai_settings_success'))<p role="status" class="rounded-control bg-success-bg p-3 text-sm text-success">{{ session('ai_settings_success') }}</p>@endif
    @if (session('ai_settings_error'))<p role="alert" class="rounded-control bg-danger-bg p-3 text-sm text-danger">{{ session('ai_settings_error') }}</p>@endif
    @if ($message)<p role="status" class="rounded-control bg-success-bg p-3 text-sm text-success">{{ $message }}</p>@endif
    @if ($errors->any())<div role="alert" class="rounded-control bg-danger-bg p-3 text-sm text-danger"><ul class="list-disc ps-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

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

    @if ($permissions['ai.settings.manage'])
        <form wire:submit="save" class="space-y-6">
            <section class="rounded-card border border-border bg-surface p-4 sm:p-6">
                <h2 class="text-lg font-bold">{{ __('assistant_v2.company_policy') }}</h2>
                <div class="mt-4 space-y-3 text-sm">
                    <label class="flex items-center gap-2"><input type="checkbox" wire:model="enabled" @disabled(!$isOwner) class="rounded border-border" />{{ __('assistant_v2.company_enabled') }}</label>
                    <label class="flex items-center gap-2"><input type="checkbox" wire:model="personalEnabled" @disabled(!$isOwner) class="rounded border-border" />{{ __('assistant_v2.allow_personal') }}</label>
                    <label class="flex items-center gap-2"><input type="checkbox" wire:model="followUpEnabled" class="rounded border-border" />{{ __('assistant_v2.allow_followup') }}</label>
                    <p class="text-text-secondary">{{ __('assistant_v2.owner_control') }}</p>
                    <label class="flex items-center gap-2"><input type="checkbox" wire:model="globalButtonEnabled" class="rounded border-border" />{{ __('assistant_v2.global_button') }}</label>
                    <p class="max-w-prose text-text-secondary">{{ __('assistant_v2.global_button_help') }}</p>
                    <fieldset class="space-y-2">
                        <legend class="font-semibold">{{ __('assistant_v2.allowed_intents') }}</legend>
                        @foreach (['sales', 'purchase'] as $intent)
                            <label class="flex items-center gap-2"><input type="checkbox" wire:model="allowedIntents" value="{{ $intent }}" class="rounded border-border" />{{ __('assistant_v2.intent_'.$intent) }}</label>
                        @endforeach
                    </fieldset>
                    <fieldset class="space-y-2 border-t border-border pt-3">
                        <legend class="font-semibold">{{ __('assistant_v2.questions_policy') }}</legend>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" wire:model.live="questionsEnabled" class="rounded border-border" />
                            {{ __('assistant_v2.questions_enabled') }}
                        </label>
                        <p class="max-w-prose text-text-secondary">{{ __('assistant_v2.questions_help') }}</p>
                        @if ($questionsEnabled)
                            <div class="mt-2 space-y-2 ps-4">
                                <span class="block font-semibold">{{ __('assistant_v2.allowed_reports') }}</span>
                                @foreach ($availableReports as $reportKey)
                                    <label class="flex items-center gap-2">
                                        <input type="checkbox" wire:model="allowedReports" value="{{ $reportKey }}" class="rounded border-border" />
                                        {{ __('assistant_v2.report_'.$reportKey) }}
                                    </label>
                                @endforeach
                            </div>
                        @endif
                    </fieldset>
                </div>
                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <label class="text-sm font-semibold">{{ __('assistant_v2.workflow_mode') }}
                        <select wire:model="mode" class="mt-1 min-h-11 w-full rounded-control border border-border px-2">
                            <option value="seamless">{{ __('assistant_v2.seamless') }}</option><option value="guided">{{ __('assistant_v2.guided') }}</option>
                        </select>
                    </label>
                    <label class="text-sm font-semibold">{{ __('assistant_v2.speech_method') }}
                        <select wire:model="speechMethod" class="mt-1 min-h-11 w-full rounded-control border border-border px-2">
                            @foreach(['disabled', 'cloud', 'local_only', 'local_if_supported'] as $value)<option value="{{ $value }}">{{ __('assistant_v2.'.$value) }}</option>@endforeach
                        </select>
                    </label>
                </div>
                <p class="mt-3 text-sm text-text-secondary">{{ __('assistant_v2.local_help') }}</p>
                <h3 class="mt-6 font-semibold">{{ __('assistant_v2.allowed_connections') }}</h3>
                <div class="mt-3 space-y-2 text-sm">
                    @foreach($connections as $connection)
                        @if($connection['credential_source'] === 'company_byok' && $connection['revoked_at'] === null)
                            <label class="flex items-start gap-2"><input type="checkbox" wire:model.live="allowedConnections" value="{{ $connection['public_id'] }}" class="mt-1 rounded border-border" /><span class="break-words">{{ $connection['provider'] }} · {{ $connection['model'] }} · {{ $connection['region'] }}</span></label>
                        @endif
                    @endforeach
                </div>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    @foreach(['textConnection' => 'text_structured', 'speechConnection' => 'audio_transcription'] as $field => $capability)
                        <label class="text-sm font-semibold">{{ __('assistant_v2.'.$capability) }}
                            <select wire:model="{{ $field }}" class="mt-1 min-h-11 w-full rounded-control border border-border px-2"><option value="">{{ __('assistant_v2.choose') }}</option>
                                @foreach($connections as $connection)
                                    @if($connection['credential_source'] === 'company_byok' && $connection['capability'] === $capability && $connection['revoked_at'] === null)
                                        <option value="{{ $connection['public_id'] }}">{{ $connection['provider'] }} · {{ $connection['model'] }} · {{ $connection['region'] }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </label>
                    @endforeach
                </div>
                <details class="mt-5 text-sm">
                    <summary class="cursor-pointer py-2 font-semibold">{{ __('assistant_v2.fallback_policy') }}</summary>
                    <label class="my-3 flex items-center gap-2"><input type="checkbox" wire:model="fallbackEnabled" class="rounded border-border" />{{ __('assistant_v2.fallback_enabled') }}</label>
                    <p class="text-text-secondary">{{ __('assistant_v2.fallback_help') }}</p>
                    <div class="mt-3 grid gap-4 sm:grid-cols-2">
                        @foreach(['fallbackText' => 'text_structured', 'fallbackSpeech' => 'audio_transcription'] as $field => $capability)
                            <label>{{ __('assistant_v2.'.$capability) }}<select wire:model="{{ $field }}" class="mt-1 min-h-11 w-full rounded-control border border-border px-2"><option value="">{{ __('assistant_v2.choose') }}</option>
                                @foreach($connections as $connection)@if($connection['credential_source'] === 'company_byok' && $connection['capability'] === $capability && $connection['revoked_at'] === null)<option value="{{ $connection['public_id'] }}">{{ $connection['provider'] }} · {{ $connection['model'] }} · {{ $connection['region'] }}</option>@endif @endforeach
                            </select></label>
                        @endforeach
                    </div>
                </details>
                <details class="mt-4 text-sm">
                    <summary class="cursor-pointer py-2 font-semibold">{{ __('assistant_v2.personal_models') }}</summary>
                    <p class="my-2 text-text-secondary">{{ __('assistant_v2.personal_help') }}</p>
                    <div class="space-y-2">
                        @foreach ($routes as $key => $route)
                            <label class="flex items-start gap-2"><input type="checkbox" wire:model.live="personalRoutes" value="{{ $key }}" class="mt-1 rounded border-border" /><span>{{ $route['provider'] }} · {{ $route['model'] }} · {{ $route['region'] }} · {{ __('assistant_v2.'.$route['capability']) }}</span></label>
                        @endforeach
                    </div>
                </details>
            </section>

            <section class="rounded-card border border-border bg-surface p-4 sm:p-6">
                <h2 class="text-lg font-bold">{{ __('assistant_v2.privacy_terms') }}</h2>
                <p class="mt-2 max-w-prose text-sm text-text-secondary">{{ __('assistant_v2.privacy_help') }}</p>
                <div class="mt-4 space-y-4">
                    <label class="block text-sm font-semibold">{{ __('assistant_v2.operators') }}<input wire:model="privacyOperator" maxlength="200" class="mt-1 min-h-11 w-full rounded-control border border-border px-3" /></label>
                    <label class="block text-sm font-semibold">{{ __('assistant_v2.retention_terms') }}<textarea wire:model="privacyRetention" maxlength="500" rows="3" class="mt-1 w-full rounded-control border border-border px-3 py-2"></textarea></label>
                    <label class="block text-sm font-semibold">{{ __('assistant_v2.privacy_policy') }}<input wire:model="privacyUrl" type="url" dir="ltr" maxlength="500" class="mt-1 min-h-11 w-full rounded-control border border-border px-3" /></label>
                </div>
            </section>

            <section class="rounded-card border border-border bg-surface p-4 sm:p-6">
                <h2 class="text-lg font-bold">{{ __('assistant_v2.usage_limits') }}</h2>
                <p class="mt-2 max-w-prose text-sm text-text-secondary">{{ __('assistant_v2.usage_help') }}</p>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    @foreach($limits as $key => $value)
                        <label class="text-sm font-semibold">{{ __('assistant_v2.limit_'.$key) }}<input wire:model="limits.{{ $key }}" inputmode="{{ str_ends_with($key, '_micros') ? 'decimal' : 'numeric' }}" dir="ltr" class="mt-1 min-h-11 w-full rounded-control border border-border px-3" /></label>
                    @endforeach
                    <label class="text-sm font-semibold">{{ __('assistant_v2.audit_retention') }}<input wire:model="retentionDays" type="number" min="1" max="365" class="mt-1 min-h-11 w-full rounded-control border border-border px-3" /></label>
                </div>
                <h3 class="mt-6 font-semibold">{{ __('assistant_v2.dated_prices') }}</h3>
                @foreach(array_unique([...$allowedConnections, ...$personalRoutes]) as $key)
                    <fieldset class="mt-4 space-y-3 border-t border-border pt-4">
                        <legend class="break-words text-sm font-semibold">{{ isset($routes[$key]) ? implode(' · ', $routes[$key]) : collect($connections)->firstWhere('public_id', $key)['model'] ?? __('assistant_v2.choose') }}</legend>
                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach(['input_micros_per_million', 'output_micros_per_million', 'audio_micros_per_minute'] as $field)
                                <label class="text-sm">{{ __('assistant_v2.price_'.$field) }}<input wire:model="pricing.{{ $key }}.{{ $field }}" inputmode="decimal" dir="ltr" class="mt-1 min-h-11 w-full rounded-control border border-border px-3" /></label>
                            @endforeach
                            <label class="text-sm">{{ __('assistant_v2.price_date') }}<input wire:model="pricing.{{ $key }}.checked_at" type="date" class="mt-1 min-h-11 w-full rounded-control border border-border px-3" /></label>
                            <label class="text-sm sm:col-span-2">{{ __('assistant_v2.price_source') }}<input wire:model="pricing.{{ $key }}.reference" type="url" dir="ltr" class="mt-1 min-h-11 w-full rounded-control border border-border px-3" /></label>
                        </div>
                    </fieldset>
                @endforeach
            </section>
            <button type="submit" wire:loading.attr="disabled" class="min-h-11 rounded-control bg-primary px-5 text-sm font-bold text-white hover:bg-primary-hover disabled:opacity-50">{{ __('assistant_v2.save_policy') }}</button>
        </form>
    @endif

    <section class="rounded-card border border-border bg-surface p-4 sm:p-6">
        <h2 class="text-lg font-bold">{{ __('assistant_v2.credentials') }}</h2>
        <p class="mt-2 max-w-prose text-sm text-text-secondary">{{ __('assistant_v2.secret_help') }}</p>
        @foreach($connections as $connection)
            @if (($connection['credential_source'] === 'company_byok' && $permissions['ai.credentials.manage']) || ($connection['credential_source'] === 'personal_byok' && $permissions['ai.personal_credentials.manage']))
                <details class="mt-4 border-t border-border pt-3">
                    <summary class="cursor-pointer break-words py-2 text-sm font-semibold">{{ $connection['provider'] }} · {{ $connection['model'] }} · {{ $connection['region'] }} · {{ __('assistant_v2.'.$connection['credential_source']) }} · {{ __('assistant_v2.'.($connection['revoked_at'] === null ? 'configured_unverified' : 'revoked')) }}</summary>
                    <form method="POST" action="{{ route('settings.ai.credentials') }}" autocomplete="off" class="mt-3 space-y-3">
                        @csrf
                        <input type="hidden" name="connection_id" value="{{ $connection['public_id'] }}" />
                        <input type="hidden" name="credential_source" value="{{ $connection['credential_source'] }}" />
                        <input type="hidden" name="route" value="{{ implode('|', [$connection['provider'], $connection['model'], $connection['region'], $connection['capability']]) }}" />
                        <label class="block text-sm">{{ __('assistant_v2.new_secret') }}<input type="password" name="provider_secret" autocomplete="new-password" maxlength="4096" dir="ltr" class="mt-1 min-h-11 w-full rounded-control border border-border px-3" /></label>
                        <label class="block text-sm">{{ __('assistant_v2.current_password') }}<input type="password" name="password" required autocomplete="current-password" class="mt-1 min-h-11 w-full rounded-control border border-border px-3" /></label>
                        <div class="flex flex-wrap gap-2">
                            <button name="operation" value="replace" class="min-h-11 rounded-control border border-border px-3 text-sm text-primary">{{ __('assistant_v2.replace') }}</button>
                            <button name="operation" value="validate" class="min-h-11 rounded-control border border-border px-3 text-sm">{{ __('assistant_v2.validate_local') }}</button>
                            <button name="operation" value="revoke" class="min-h-11 rounded-control border border-danger px-3 text-sm text-danger">{{ __('assistant_v2.revoke') }}</button>
                        </div>
                    </form>
                </details>
            @endif
        @endforeach
        @if ($permissions['ai.credentials.manage'] || $permissions['ai.personal_credentials.manage'])
        <form method="POST" action="{{ route('settings.ai.credentials') }}" autocomplete="off" class="mt-6 space-y-4 border-t border-border pt-5">
            @csrf
            <input type="hidden" name="operation" value="create" />
            <h3 class="font-semibold">{{ __('assistant_v2.add_connection') }}</h3>
            <label class="block text-sm">{{ __('assistant_v2.connection_route') }}
                <select name="route" required class="mt-1 min-h-11 w-full rounded-control border border-border px-2">
                    @foreach($routes as $key => $route)<option value="{{ $key }}">{{ $route['provider'] }} · {{ $route['model'] }} · {{ $route['region'] }} · {{ __('assistant_v2.'.$route['capability']) }}</option>@endforeach
                </select>
            </label>
            <label class="block text-sm">{{ __('assistant_v2.key_source') }}
                <select name="credential_source" required class="mt-1 min-h-11 w-full rounded-control border border-border px-2">
                    @if($permissions['ai.credentials.manage'])<option value="company_byok">{{ __('assistant_v2.company_byok') }}</option>@endif
                    @if($permissions['ai.personal_credentials.manage'])<option value="personal_byok">{{ __('assistant_v2.personal_byok') }}</option>@endif
                </select>
            </label>
            <label class="block text-sm">{{ __('assistant_v2.new_secret') }}<input type="password" name="provider_secret" autocomplete="new-password" required maxlength="4096" dir="ltr" class="mt-1 min-h-11 w-full rounded-control border border-border px-3" /></label>
            <label class="block text-sm">{{ __('assistant_v2.current_password') }}<input type="password" name="password" required autocomplete="current-password" class="mt-1 min-h-11 w-full rounded-control border border-border px-3" /></label>
            <button type="submit" class="min-h-11 rounded-control bg-primary px-4 text-sm font-bold text-white hover:bg-primary-hover">{{ __('assistant_v2.add_connection') }}</button>
        </form>
        @endif
    </section>
</div>
