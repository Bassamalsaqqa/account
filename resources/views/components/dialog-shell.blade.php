@props([
    'title',
    'titleId' => null,
    'maxWidth' => 'max-w-md',
    'closeAction' => null,
    'saveTarget' => null,
])

@php
    $id = $titleId ?? 'dialog-title-' . substr(md5($title . ($closeAction ?? '')), 0, 8);
    $wireClose = $closeAction ? "\$wire.{$closeAction}()" : "\$dispatch('close')";
@endphp

<div x-data="{
        triggerEl: null,
        isSaving: false,
        saveTarget: '{{ $saveTarget }}',
        init() {
            this.triggerEl = document.activeElement;
            this.$nextTick(() => {
                const target = this.$refs.firstInput || this.$el.querySelector('input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea:not([disabled]), [autofocus]');
                target?.focus();
            });
            if (typeof Livewire !== 'undefined' && this.saveTarget) {
                this._cleanupCommit = Livewire.hook('commit', ({ component, commit, succeed, fail }) => {
                    if (component.id === $wire?.$id || this.$el.contains(component.el)) {
                        const calls = commit.calls || [];
                        if (calls.some(c => c.method === this.saveTarget)) {
                            this.isSaving = true;
                            succeed(() => { this.isSaving = false; });
                            fail(() => { this.isSaving = false; });
                        }
                    }
                });
            }
            if (typeof this.$cleanup === 'function') {
                this.$cleanup(() => this.destroy());
            }
        },
        dismiss() {
            if (this.isSaving) return;
            if (typeof saving !== 'undefined' && saving) return;
            @if($saveTarget)
                if (window.isDocumentBusy && window.isDocumentBusy($wire)) return;
            @endif
            {{ $wireClose }};
        },
        destroy() {
            if (this._cleanupCommit) this._cleanupCommit();
            if (this.triggerEl && typeof this.triggerEl.focus === 'function') {
                this.triggerEl.focus();
            }
        }
     }"
     x-trap.inert.noscroll="true"
     @keydown.escape.window.stop.prevent="dismiss()"
     @click.self="dismiss()"
     @dialog-close.stop="dismiss()"
     class="app-dialog-enter fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-xs"
     role="dialog"
     aria-modal="true"
     aria-labelledby="{{ $id }}"
     {{ $attributes }}>
    <div @click.stop class="bg-surface border border-border rounded-card p-6 w-full {{ $maxWidth }} shadow-lg space-y-4 max-h-[90dvh] overflow-y-auto">
        <div class="flex items-center justify-between pb-3 border-b border-border">
            <h3 id="{{ $id }}" class="text-base font-bold text-text-primary">{{ $title }}</h3>
            <button type="button"
                    @click="dismiss()"
                    aria-label="{{ __('workflow.close') }}"
                    class="min-h-11 min-w-11 rounded-control text-text-muted hover:text-text-primary hover:bg-surface-soft flex items-center justify-center text-sm font-bold cursor-pointer transition-colors"
                    wire:loading.attr="disabled"
                    @if($closeAction) wire:target="{{ $closeAction }}" @endif>
                <x-icon name="close" class="h-4 w-4" />
            </button>
        </div>

        {{ $slot }}

        @if(isset($footer))
            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                {{ $footer }}
            </div>
        @endif
    </div>
</div>
