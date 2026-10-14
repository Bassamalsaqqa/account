<div class="ai-composer">
    <label for="assistant-description-{{ $this->getId() }}" class="sr-only">{{ __('ai_product.message') }}</label>
    <div class="ai-input-wrap">
        <textarea x-ref="description" id="assistant-description-{{ $this->getId() }}" wire:model="text" rows="2" maxlength="4000"
            placeholder="{{ __('ai_product.placeholder') }}" :disabled="(recording && !grace) || busy || sending"
            @input="edit()"
            @keydown.ctrl.enter.prevent="send()" @keydown.meta.enter.prevent="send()"></textarea>
        <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-1">
                <button type="button" x-show="!recording && !grace" @click="start()" :disabled="busy || sending" class="ai-icon-button"
                    aria-label="{{ __('ai_product.dictate') }}" title="{{ __('ai_product.dictate') }}">
                    <x-icon name="microphone" />
                </button>
                <button type="button" x-show="recording && !grace" x-cloak @click="stop()" class="ai-icon-button text-danger"
                    aria-label="{{ __('ai_product.stop_listening') }}"><x-icon name="stop" /></button>
                <button type="button" x-show="recording || transcriptReady || busy || grace" x-cloak @click="cancelVoice()" class="min-h-11 px-2 text-sm text-text-secondary underline">
                    {{ __('ai_product.cancel_voice') }}
                </button>
                <span x-show="recording" x-cloak role="status" class="ai-voice-state"><span class="ai-voice-pulse" aria-hidden="true"></span><span class="num" x-text="seconds"></span></span>
            </div>
            <button type="button" @click="send()" wire:loading.attr="disabled" wire:target="prepare,clarify,selectPeriod,chooseOperation"
                :disabled="(recording && !grace) || busy || sending || !$wire.text.trim()" class="ai-send">
                <span>{{ __('ai_product.send') }}</span><x-icon name="arrow-up" class="h-4 w-4" />
            </button>
        </div>
    </div>
    <p class="mt-2 text-xs leading-relaxed text-text-secondary">{{ __('ai_product.unsaved_note') }}</p>
    <p x-show="interim" x-cloak x-text="interim" role="status" class="mt-2 text-sm text-text-secondary"></p>
    <p x-show="transcriptReady && !grace" x-cloak role="status" class="mt-2 text-xs text-success">{{ __('ai_product.transcript_ready') }}</p>
    <div x-show="grace" x-cloak role="status" class="mt-2 flex items-center justify-between gap-2 text-xs text-primary font-medium">
        <span>{{ __('ai_product.voice_grace') }}</span>
        <button type="button" @click="edit()" class="min-h-11 px-2 font-semibold underline focus-visible:outline-2 focus-visible:outline-primary">
            {{ __('ai_product.voice_edit') }}
        </button>
    </div>
    <p x-show="error" x-cloak x-text="error" role="alert" class="mt-2 text-sm text-danger"></p>
</div>
