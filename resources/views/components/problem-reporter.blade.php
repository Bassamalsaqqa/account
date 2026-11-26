<template id="problem-reporter-template">
    <dialog id="problem-reporter-dialog"
            role="dialog"
            aria-modal="true"
            aria-labelledby="problem-reporter-title"
            data-endpoint="{{ route('diagnostics.bug-reports.store') }}"
            data-included-with="{{ __('diagnostics.included_with_diagnostics') }}"
            data-included-without="{{ __('diagnostics.included_without_diagnostics') }}"
            data-submitting-label="{{ __('diagnostics.submitting') }}"
            data-copied-label="{{ __('diagnostics.copied') }}"
            data-note-short-error="{{ __('diagnostics.error_note_short') }}"
            data-rate-error="{{ __('diagnostics.error_rate_limited') }}"
            data-session-error="{{ __('diagnostics.error_session') }}"
            data-generic-error="{{ __('diagnostics.error_generic') }}"
            class="fixed inset-0 z-[90] m-auto w-[min(32rem,calc(100vw-2rem))] max-h-[90dvh] rounded-card border border-border bg-surface p-0 text-text-primary shadow-xl backdrop:bg-slate-900/50">
        <div class="flex flex-col max-h-[90dvh] overflow-y-auto p-5 sm:p-6 space-y-4">
            <div class="flex items-start justify-between gap-3 pb-3 border-b border-border">
                <div class="min-w-0">
                    <h2 id="problem-reporter-title" class="text-base font-bold text-text-primary">
                        {{ __('diagnostics.modal_title') }}
                    </h2>
                    <p class="mt-0.5 text-xs text-text-secondary">
                        {{ __('diagnostics.modal_subtitle') }}
                    </p>
                </div>
                <button type="button"
                        data-pr-close
                        aria-label="{{ __('diagnostics.close') }}"
                        class="min-h-11 min-w-11 inline-flex items-center justify-center rounded-control border border-border bg-surface text-text-secondary hover:bg-surface-soft hover:text-text-primary transition-colors cursor-pointer shrink-0">
                    <x-icon name="close" class="w-4 h-4" />
                </button>
            </div>

            <div role="note" class="rounded-control border border-warning/25 bg-warning-bg px-3.5 py-2.5 text-xs font-medium leading-relaxed text-warning">
                {{ __('diagnostics.privacy_warning') }}
            </div>

            <div data-pr-error hidden role="alert" class="rounded-control border border-danger/25 bg-danger-bg px-3.5 py-2.5 text-sm font-medium text-danger"></div>

            <form data-pr-form class="space-y-4" novalidate>
                <div>
                    <label for="pr-category" class="block text-sm font-semibold text-text-primary mb-1.5">
                        {{ __('diagnostics.category_label') }}
                    </label>
                    <select id="pr-category"
                            data-pr-category
                            class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-base sm:text-sm text-text-primary focus:border-primary">
                        @foreach(\App\Models\BugReport::CATEGORIES as $cat)
                            <option value="{{ $cat }}" @selected($cat === 'other')>{{ __('diagnostics.categories.'.$cat) }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <div class="flex items-center justify-between gap-2 mb-1.5">
                        <label for="pr-note" class="block text-sm font-semibold text-text-primary">
                            {{ __('diagnostics.note_label') }}
                        </label>
                        <span data-pr-count class="text-xs font-mono text-text-muted tabular-nums" dir="ltr">0 / 1000</span>
                    </div>
                    <textarea id="pr-note"
                              data-pr-note
                              rows="4"
                              required
                              minlength="5"
                              maxlength="1000"
                              aria-describedby="pr-note-help"
                              placeholder="{{ __('diagnostics.note_placeholder') }}"
                              class="w-full rounded-control border border-border bg-surface p-3 text-base sm:text-sm text-text-primary placeholder:text-text-muted focus:border-primary"></textarea>
                    <p id="pr-note-help" class="mt-1 text-xs text-text-muted">{{ __('diagnostics.note_help') }}</p>
                </div>

                <div class="rounded-control border border-border bg-surface-soft/70 p-3.5 space-y-2">
                    <label class="flex items-start gap-2.5 cursor-pointer min-h-11 py-1">
                        <input type="checkbox"
                               data-pr-include
                               checked
                               class="mt-1 h-4 w-4 rounded border-border-strong text-primary focus:ring-primary shrink-0">
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold text-text-primary">{{ __('diagnostics.include_diagnostics_label') }}</span>
                            <span class="block text-xs text-text-secondary mt-0.5 leading-relaxed">{{ __('diagnostics.include_diagnostics_help') }}</span>
                        </span>
                    </label>
                    <div class="pt-2 border-t border-border/70 text-xs text-text-secondary space-y-1">
                        <p class="font-semibold text-text-primary">{{ __('diagnostics.whats_included_heading') }}</p>
                        <p data-pr-included-text class="leading-relaxed">{{ __('diagnostics.included_with_diagnostics') }}</p>
                        <p data-pr-meta-preview class="font-mono text-xs text-text-muted break-all" dir="ltr"></p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-2 pt-2 border-t border-border">
                    <button type="button"
                            data-pr-close
                            class="min-h-11 inline-flex items-center justify-center rounded-control border border-border bg-surface px-4 text-sm font-semibold text-text-secondary hover:bg-surface-soft cursor-pointer">
                        {{ __('diagnostics.cancel') }}
                    </button>
                    <button type="submit"
                            data-pr-submit
                            class="min-h-11 inline-flex items-center justify-center rounded-control bg-primary px-5 text-sm font-semibold text-white hover:bg-primary-hover shadow-panel cursor-pointer">
                        <span data-pr-submit-label>{{ __('diagnostics.submit') }}</span>
                    </button>
                </div>
            </form>

            <div data-pr-success hidden role="status" aria-live="polite" class="space-y-4">
                <div class="rounded-control border border-success/25 bg-success-bg p-4 space-y-2">
                    <p class="text-sm font-bold text-success">{{ __('diagnostics.success_title') }}</p>
                    <p class="text-xs text-text-secondary">{{ __('diagnostics.success_body') }}</p>
                    <div class="flex flex-wrap items-center justify-between gap-2 pt-1">
                        <code data-pr-ref class="font-mono text-sm font-bold text-text-primary bg-surface px-2.5 py-1.5 rounded border border-border select-all" dir="ltr"></code>
                        <button type="button"
                                data-pr-copy
                                class="min-h-11 inline-flex items-center justify-center rounded-control border border-border bg-surface px-3.5 text-xs font-semibold text-text-primary hover:bg-surface-soft cursor-pointer">
                            {{ __('diagnostics.copy_reference') }}
                        </button>
                    </div>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <button type="button"
                            data-pr-another
                            class="min-h-11 inline-flex items-center justify-center rounded-control border border-border bg-surface px-4 text-sm font-semibold text-text-secondary hover:bg-surface-soft cursor-pointer">
                        {{ __('diagnostics.report_another') }}
                    </button>
                    <button type="button"
                            data-pr-close
                            class="min-h-11 inline-flex items-center justify-center rounded-control bg-primary px-5 text-sm font-semibold text-white hover:bg-primary-hover cursor-pointer">
                        {{ __('diagnostics.close') }}
                    </button>
                </div>
            </div>
        </div>
    </dialog>
</template>
