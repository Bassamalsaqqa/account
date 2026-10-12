<section class="ai-setup" aria-labelledby="deepseek-setup-title-{{ $this->getId() }}">
    @if ($ownerAuthorized)
        <div class="flex items-start gap-3">
            <span class="ai-mark"><x-icon name="sparkles" aria-hidden="true" /></span>
            <div>
                <h2 id="deepseek-setup-title-{{ $this->getId() }}" class="text-xl font-bold">DeepSeek AI</h2>
            </div>
        </div>
        <div class="mt-5 flex items-center gap-2 text-sm {{ $connectionStatus === 'verified' ? 'text-success' : 'text-text-secondary' }}" role="status">
            <x-icon :name="$connectionStatus === 'verified' ? 'check-circle' : 'shield'" class="h-4 w-4" />
            <span>{{ __('ai_product.'.match ($connectionStatus) { 'verified' => 'status_verified', 'configured_unverified' => 'status_configured', default => 'status_unconfigured' }) }}</span>
        </div>
        @if ($needsTransition)
            <p class="mt-4 rounded-control bg-info-bg p-3 text-sm leading-relaxed text-info">{{ __('ai_product.existing_settings') }}</p>
        @endif
        <form x-data="{ key: '', password: '', consent: false, busy: false }"
            @submit.prevent="if (!busy) { busy = true; const pending = $wire.testConnection(key, password, consent); key = ''; password = ''; pending.finally(() => busy = false); }"
            class="mt-5 space-y-4" autocomplete="off">
            <div>
                <label for="deepseek-key-{{ $this->getId() }}" class="block text-sm font-semibold">{{ __('ai_product.key') }}</label>
                <input id="deepseek-key-{{ $this->getId() }}" type="password" x-model="key" dir="ltr" maxlength="4096"
                    autocomplete="new-password" spellcheck="false" autocapitalize="off" class="ai-setup-input mt-2"
                    placeholder="{{ $connectionStatus === 'not_configured' ? '' : '••••••••••••••••' }}"
                    aria-describedby="deepseek-key-help-{{ $this->getId() }}" />
                <p id="deepseek-key-help-{{ $this->getId() }}" class="mt-1.5 text-xs text-text-secondary">{{ __('ai_product.key_help') }}</p>
            </div>
            <div>
                <label for="deepseek-password-{{ $this->getId() }}" class="block text-sm font-semibold">{{ __('assistant_v2.current_password') }}</label>
                <input id="deepseek-password-{{ $this->getId() }}" type="password" x-model="password" autocomplete="current-password" class="ai-setup-input mt-2" />
            </div>
            <div class="rounded-control bg-canvas p-3 text-xs leading-relaxed text-text-secondary">
                <p>{{ __('ai_product.cost_summary', ['input' => $disclosure['pricing']['input_display'], 'output' => $disclosure['pricing']['output_display']]) }}</p>
                <p class="mt-1">{{ __('ai_product.privacy_summary') }}</p>
                <details class="mt-2"><summary class="cursor-pointer">{{ __('ai_product.privacy') }}</summary><p class="mt-1">{{ $disclosure['operator'] }} · {{ $disclosure['retention'] }}</p></details>
                <div class="mt-2 flex flex-wrap gap-x-4 gap-y-2 text-xs">
                    <a href="{{ $disclosure['privacy_url'] }}" target="_blank" rel="noopener noreferrer" class="text-primary underline">{{ __('assistant_v2.privacy_policy') }}</a>
                    <a href="{{ $disclosure['pricing']['reference'] }}" target="_blank" rel="noopener noreferrer" class="text-primary underline">{{ __('ai_product.pricing_as_of', ['date' => $disclosure['pricing']['checked_at']]) }}</a>
                </div>
            </div>
            <label class="flex items-start gap-3 text-sm leading-relaxed"><input type="checkbox" x-model="consent" class="mt-1 h-4 w-4 rounded border-border" /><span>{{ __('ai_product.setup_consent') }} {{ __('ai_product.setup_question_consent') }} {{ __('ai_product.test_help') }}</span></label>
            <div class="flex flex-wrap items-center gap-3">
                @if ($needsTransition)
                    <button type="button" @click="if (!busy) { busy = true; const pending = $wire.useDeepSeekOnly(password); password = ''; pending.finally(() => busy = false); }" :disabled="busy" class="ai-send">{{ __('ai_product.transition_action') }}</button>
                @elseif ($companyDisabled)
                    <button type="button" @click="if (!busy) { busy = true; const pending = $wire.enableCompany(password, consent); password = ''; pending.finally(() => busy = false); }" :disabled="busy" class="ai-send">{{ __('ai_product.enable_company') }}</button>
                @endif
                <button type="submit" :disabled="busy" @disabled($status !== 'idle' || $needsTransition) wire:loading.attr="disabled" wire:target="testConnection" class="ai-send"><x-icon name="shield" class="h-4 w-4" /><span>{{ __('ai_product.test') }}</span></button>
                <span wire:loading wire:target="testConnection" role="status" class="text-sm text-text-secondary">{{ __('ai_product.thinking') }}</span>
            </div>
        </form>
        @if ($status !== 'idle')
            <button type="button" wire:click="allowAnotherTest" class="mt-3 text-sm text-primary underline">{{ __('ai_product.another_test') }}</button>
        @endif
        @if ($message)
            <p role="{{ $status === 'verified' ? 'status' : 'alert' }}" class="mt-4 rounded-control p-3 text-sm leading-relaxed {{ $status === 'verified' ? 'bg-success-bg text-success' : 'bg-danger-bg text-danger' }}">{{ $message }}</p>
        @endif
        @if ($errors->any())<div role="alert" class="mt-4 text-sm text-danger"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @if ($connectionStatus === 'verified' && ! $companyDisabled && ! $needsTransition)
            <button type="button" wire:click="startUsing" wire:loading.attr="disabled" wire:target="startUsing" class="ai-send mt-5"><x-icon name="sparkles" class="h-4 w-4" />{{ __('ai_product.start_using') }}</button>
        @endif
    @endif
</section>
