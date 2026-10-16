@props([
    'buttonClass' => null,
    'buttonLabel' => null,
])

<div x-data="cameraScanner($wire, {
        offlineMessage: '{{ __('camera.offline') }}',
        unsupportedMessage: '{{ __('camera.unsupported') }}',
        networkErrorMessage: '{{ __('camera.network_error') }}'
    })"
     x-id="['camera-modal', 'camera-modal-title', 'camera-modal-desc', 'camera-live-status']"
     @mobile-camera-open.window="open()"
     class="inline-block">

    <!-- Open Camera Button (Touch Target >= 44px) -->
    <button type="button"
            @click="open()"
            class="{{ $buttonClass ?? 'min-h-[44px] min-w-[44px] px-3.5 py-2 inline-flex items-center justify-center gap-2 rounded-control border border-border bg-white text-text-primary text-xs font-semibold hover:bg-surface-soft hover:border-primary/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 transition-colors' }}"
            title="{{ $buttonLabel ?? __('camera.scan_camera') }}"
            aria-haspopup="dialog"
            :aria-expanded="isOpen.toString()">
        <svg class="w-5 h-5 shrink-0 text-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/>
            <circle cx="12" cy="13" r="3"/>
        </svg>
        <span>{{ $buttonLabel ?? __('camera.scan_camera') }}</span>
    </button>
    <p x-cloak x-show="!isOpen && status === 'offline'" role="status" class="mt-2 text-sm text-text-secondary">{{ __('camera.offline') }}</p>
    <p x-cloak x-show="!isOpen && status === 'unsupported'" role="status" class="mt-2 text-sm text-text-secondary">{{ __('camera.unsupported') }}</p>

    <!-- Modal Dialog (Teleported to body with z >= 90) -->
    <template x-teleport="body">
        <div x-show="isOpen"
             x-cloak
             @keydown.escape.window="handleEscape($event)"
             class="fixed inset-0 z-[95] overflow-y-auto"
             role="dialog"
             aria-modal="true"
             :aria-labelledby="$id('camera-modal-title')"
             :aria-describedby="$id('camera-modal-desc')">

            <!-- Backdrop -->
            <div x-show="isOpen"
                 x-transition:enter="ease-out duration-200"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="ease-in duration-150"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs transition-opacity"
                 @click="close()"></div>

            <!-- Dialog Content Shell -->
            <div class="fixed inset-0 z-10 flex items-center justify-center p-3 sm:p-4 pointer-events-none">
                <div x-show="isOpen"
                     x-transition:enter="ease-out duration-200"
                     x-transition:enter-start="opacity-0 scale-95"
                     x-transition:enter-end="opacity-100 scale-100"
                     x-transition:leave="ease-in duration-150"
                     x-transition:leave-start="opacity-100 scale-100"
                     x-transition:leave-end="opacity-0 scale-95"
                     @click.stop
                     x-trap.inert.noscroll="isOpen"
                     class="pointer-events-auto relative w-full max-w-lg bg-white rounded-control shadow-xl border border-border overflow-hidden flex flex-col max-h-[90vh]">

                    <!-- Header -->
                    <div class="flex items-center justify-between px-4 py-3 border-b border-border bg-surface-soft">
                        <div class="flex items-center gap-2">
                            <svg class="w-5 h-5 text-primary shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/>
                                <circle cx="12" cy="13" r="3"/>
                            </svg>
                            <h2 :id="$id('camera-modal-title')" class="text-sm font-bold text-text-primary">
                                {{ __('camera.scanner_modal_title') }}
                            </h2>
                        </div>

                        <!-- Feedback and Close Controls (Touch targets >= 44px) -->
                        <div class="flex items-center gap-1">
                            <button type="button"
                                    @click="toggleFeedback()"
                                    class="min-h-[44px] min-w-[44px] p-2 text-text-secondary hover:text-text-primary rounded-control focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary flex items-center justify-center"
                                    :title="feedbackEnabled ? '{{ __('camera.feedback_enabled') }}' : '{{ __('camera.feedback_disabled') }}'"
                                    :aria-label="feedbackEnabled ? '{{ __('camera.feedback_enabled') }}' : '{{ __('camera.feedback_disabled') }}'">
                                <svg x-show="feedbackEnabled" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/>
                                    <path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"/>
                                </svg>
                                <svg x-show="!feedbackEnabled" x-cloak class="w-5 h-5 text-text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/>
                                    <line x1="23" y1="9" x2="17" y2="15"/>
                                    <line x1="17" y1="9" x2="23" y2="15"/>
                                </svg>
                            </button>

                            <button type="button"
                                    @click="close()"
                                    class="min-h-[44px] min-w-[44px] p-2 text-text-secondary hover:text-danger rounded-control focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger flex items-center justify-center"
                                    aria-label="{{ __('camera.close_camera') }}">
                                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="18" y1="6" x2="6" y2="18"/>
                                    <line x1="6" y1="6" x2="18" y2="18"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Viewfinder & Video Frame -->
                    <div class="relative bg-black flex items-center justify-center aspect-4/3 sm:aspect-16/9 overflow-hidden">
                        <video x-ref="video"
                               class="w-full h-full object-cover"
                               autoplay
                               playsinline
                               muted></video>

                        <!-- Viewfinder Reticle Overlay -->
                        <div x-show="status === 'scanning' || status === 'processing' || status === 'slow_server' || status === 'review'"
                             class="absolute inset-0 pointer-events-none flex items-center justify-center p-8">
                            <div class="relative w-64 h-40 max-w-[80%] max-h-[80%] border-2 rounded-lg transition-colors duration-200"
                                 :class="busy ? 'border-primary shadow-[0_0_15px_rgba(59,130,246,0.5)]' : 'border-white/70 shadow-[0_0_10px_rgba(0,0,0,0.5)]'">
                                <div class="absolute -top-1 -left-1 w-4 h-4 border-t-2 border-l-2 border-primary"></div>
                                <div class="absolute -top-1 -right-1 w-4 h-4 border-t-2 border-r-2 border-primary"></div>
                                <div class="absolute -bottom-1 -left-1 w-4 h-4 border-b-2 border-l-2 border-primary"></div>
                                <div class="absolute -bottom-1 -right-1 w-4 h-4 border-b-2 border-r-2 border-primary"></div>

                                <div x-show="status === 'scanning'"
                                     class="w-full h-0.5 bg-primary/80 absolute top-1/2 -translate-y-1/2 animate-pulse"></div>
                            </div>
                        </div>

                        <!-- Status Overlay Over Video (Starting / Permission / Errors) -->
                        <div x-show="status !== 'scanning' && status !== 'processing' && status !== 'slow_server' && status !== 'review'"
                             class="absolute inset-0 bg-slate-900/90 text-white flex flex-col items-center justify-center p-6 text-center">

                            <div x-show="status === 'starting'" class="flex flex-col items-center gap-3">
                                <svg class="animate-spin w-8 h-8 text-primary" viewBox="0 0 24 24" fill="none">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                </svg>
                                <p class="text-sm font-medium">{{ __('camera.starting') }}</p>
                            </div>

                            <div x-show="status === 'permission_denied'" class="flex flex-col items-center gap-2 max-w-xs">
                                <svg class="w-10 h-10 text-amber-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"/>
                                    <line x1="12" y1="8" x2="12" y2="12"/>
                                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                                </svg>
                                <p class="text-sm font-bold text-amber-200">{{ __('camera.permission_denied') }}</p>
                            </div>

                            <div x-show="status === 'no_camera'" class="flex flex-col items-center gap-2 max-w-xs">
                                <p class="text-sm font-bold text-amber-200">{{ __('camera.no_camera') }}</p>
                            </div>

                            <div x-show="status === 'unsupported'" class="flex flex-col items-center gap-2 max-w-xs">
                                <p class="text-sm font-bold text-amber-200">{{ __('camera.unsupported') }}</p>
                            </div>

                            <div x-show="status === 'offline'" class="flex flex-col items-center gap-2 max-w-xs">
                                <p class="text-sm font-bold text-amber-200">{{ __('camera.offline') }}</p>
                            </div>

                            <div x-show="status === 'camera_error' || status === 'error'" class="flex flex-col items-center gap-2 max-w-xs">
                                <p class="text-sm font-bold text-rose-300" x-text="errorMessage || '{{ __('camera.camera_error') }}'"></p>
                                <button type="button"
                                        @click="scanAgain()"
                                        class="mt-2 min-h-[44px] min-w-[44px] px-4 py-2 bg-white/20 hover:bg-white/30 rounded-control text-xs font-semibold text-white">
                                    {{ __('camera.scan_again') }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Footer & Status Bar (Distinct live status presentation) -->
                    <div class="px-4 py-3 bg-canvas border-t border-border flex flex-col sm:flex-row items-center justify-between gap-3">
                        <div class="text-xs text-text-secondary text-center sm:text-start" role="status" aria-live="polite">
                            <p x-show="status === 'review'" class="text-amber-800 font-bold" x-text="errorMessage"></p>
                            <span :id="$id('camera-modal-desc')">
                                <span x-show="status === 'scanning' && !lastScannedCode">{{ __('camera.scanning') }}</span>
                                <span x-show="status === 'processing'" class="text-primary font-bold flex items-center gap-1.5">
                                    <span class="inline-block w-2 h-2 rounded-full bg-primary animate-ping"></span>
                                    <span>{{ __('camera.processing') }}</span>
                                </span>
                                <span x-show="status === 'slow_server'" class="text-amber-600 font-bold flex items-center gap-1.5">
                                    <span>⚠️</span>
                                    <span>{{ __('camera.slow_server') }}</span>
                                </span>
                                <span x-show="status === 'scanning' && lastScannedCode" class="text-emerald-700 font-bold flex items-center gap-1.5">
                                    <span>✓</span>
                                    <span>{{ __('camera.scan_success') }}: <span dir="ltr" class="font-mono font-semibold" x-text="lastScannedCode"></span></span>
                                </span>
                            </span>
                        </div>

                        <div class="flex items-center gap-2 shrink-0">
                            <button type="button"
                                    x-show="lastScannedCode"
                                    @click="scanAgain()"
                                    class="min-h-[44px] min-w-[44px] px-3.5 py-2 border border-border bg-white text-text-primary text-xs font-semibold rounded-control hover:bg-surface-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                                {{ __('camera.scan_again') }}
                            </button>

                            <button type="button"
                                    @click="close()"
                                    class="min-h-[44px] min-w-[44px] px-4 py-2 bg-surface-soft hover:bg-border text-text-primary text-xs font-semibold rounded-control focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                                {{ __('camera.close_camera') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
