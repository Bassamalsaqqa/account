<div class="ai-composer">
    <label for="assistant-description-{{ $this->getId() }}" class="sr-only">{{ __('ai_product.message') }}</label>
    <div class="ai-input-wrap">
        <textarea x-ref="description" id="assistant-description-{{ $this->getId() }}" wire:model="text" rows="2" maxlength="4000"
            placeholder="{{ __('ai_product.placeholder') }}" :disabled="recording || busy || sending"
            @keydown.ctrl.enter.prevent="send()" @keydown.meta.enter.prevent="send()"></textarea>
        <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-1">
                <button type="button" x-show="!recording" @click="start()" :disabled="busy || sending" class="ai-icon-button"
                    aria-label="{{ __('ai_product.dictate') }}" title="{{ __('ai_product.dictate') }}">
                    <x-icon name="microphone" />
                </button>
                <button type="button" x-show="recording" x-cloak @click="stop()" class="ai-icon-button text-danger"
                    aria-label="{{ __('ai_product.stop_listening') }}"><x-icon name="stop" /></button>
                <button type="button" x-show="recording || transcriptReady || busy" x-cloak @click="cancelVoice()" class="min-h-11 px-2 text-sm text-text-secondary underline">
                    {{ __('ai_product.cancel_voice') }}
                </button>
                <span x-show="recording" x-cloak role="status" class="ai-voice-state"><span class="ai-voice-pulse" aria-hidden="true"></span><span class="num" x-text="seconds"></span></span>
            </div>
            <button type="button" @click="send()" wire:loading.attr="disabled" wire:target="prepare,clarify,selectPeriod,chooseOperation"
                :disabled="recording || busy || sending || !$wire.text.trim()" class="ai-send">
                <span>{{ __('ai_product.send') }}</span><x-icon name="arrow-up" class="h-4 w-4" />
            </button>
        </div>
    </div>
    <p class="mt-2 text-xs leading-relaxed text-text-secondary">{{ __('ai_product.unsaved_note') }}</p>
    <p x-show="interim" x-cloak x-text="interim" role="status" class="mt-2 text-sm text-text-secondary"></p>
    <p x-show="transcriptReady" x-cloak role="status" class="mt-2 text-xs text-success">{{ __('ai_product.transcript_ready') }}</p>
    <p x-show="recording" x-cloak class="mt-2 text-xs text-text-secondary" x-text="localVoice ? @js(__('ai_product.local_voice')) : @js(__('ai_product.remote_voice_active'))"></p>
    <div x-show="voiceDisclosure" x-cloak class="mt-3 rounded-control bg-canvas p-3 text-sm">
        <p class="font-semibold">{{ __('ai_product.voice_privacy_title') }}</p>
        <p class="mt-1 leading-relaxed text-text-secondary">{{ __('ai_product.voice_privacy') }}</p>
        <label class="mt-3 flex items-start gap-2"><input type="checkbox" x-model="remoteVoiceConsent" class="mt-1 rounded border-border" /><span>{{ __('ai_product.voice_consent') }}</span></label>
        <div class="mt-2 flex flex-wrap gap-2">
            <button type="button" @click="start()" :disabled="!remoteVoiceConsent || busy" class="ai-send">{{ __('ai_product.start_listening') }}</button>
            <button type="button" @click="voiceDisclosure = false; remoteVoiceConsent = false" class="ai-icon-button px-3 text-sm">{{ __('assistant_v2.cancel') }}</button>
        </div>
    </div>
    <p x-show="error" x-cloak x-text="error" role="alert" class="mt-2 text-sm text-danger"></p>
</div>
