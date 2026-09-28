<x-guest-layout>
    <div x-data="{ recovery: false }">
        <div class="mb-4 text-sm text-text-secondary">
            <span x-show="! recovery">
                {{ __('auth.two_factor_code_prompt', ['default' => 'يرجى تأكيد الوصول إلى حسابك عن طريق إدخال رمز المصادقة المقدم من تطبيق المصادقة الخاص بك.']) }}
            </span>
            <span x-show="recovery" style="display: none;">
                {{ __('auth.two_factor_recovery_prompt', ['default' => 'يرجى تأكيد الوصول إلى حسابك عن طريق إدخال أحد رموز الاسترداد في حالات الطوارئ.']) }}
            </span>
        </div>

        <form method="POST" action="{{ route('two-factor.login') }}">
            @csrf

            <div class="mt-4" x-show="! recovery">
                <x-input-label for="code" :value="__('auth.two_factor_code', ['default' => 'رمز المصادقة'])" />
                <x-text-input id="code" class="block mt-1 w-full text-center tracking-widest font-mono text-lg"
                              type="text"
                              inputmode="numeric"
                              name="code"
                              autofocus
                              x-ref="code"
                              autocomplete="one-time-code" />
                <x-input-error :messages="$errors->get('code')" class="mt-2" />
            </div>

            <div class="mt-4" x-show="recovery" style="display: none;">
                <x-input-label for="recovery_code" :value="__('auth.two_factor_recovery_code', ['default' => 'رمز الاسترداد'])" />
                <x-text-input id="recovery_code" class="block mt-1 w-full font-mono text-sm"
                              type="text"
                              name="recovery_code"
                              x-ref="recovery_code"
                              autocomplete="one-time-code" />
                <x-input-error :messages="$errors->get('recovery_code')" class="mt-2" />
            </div>

            <div class="flex items-center justify-between mt-6">
                <button type="button" class="text-xs text-primary hover:underline cursor-pointer"
                        x-show="! recovery"
                        x-on:click="recovery = true; $nextTick(() => { $refs.recovery_code.focus() })">
                    {{ __('auth.use_recovery_code', ['default' => 'استخدم رمز الاسترداد']) }}
                </button>

                <button type="button" class="text-xs text-primary hover:underline cursor-pointer"
                        x-show="recovery"
                        style="display: none;"
                        x-on:click="recovery = false; $nextTick(() => { $refs.code.focus() })">
                    {{ __('auth.use_auth_code', ['default' => 'استخدم رمز المصادقة']) }}
                </button>

                <x-primary-button>
                    {{ __('auth.confirm', ['default' => 'تأكيد']) }}
                </x-primary-button>
            </div>
        </form>
    </div>
</x-guest-layout>
