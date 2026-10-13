# AI V2.2 A4 cancellable native voice checkpoint

Branch: `feature/ai-v22-cancellable-voice`. Source-only dependency: A3 head `103fff459e7469753671796ad0021671ef72f419` (draft PR #40), containing A2 consent correction `12812ff3e8f364ffbaba9d2bd33505ea9128dc53` (draft PR #39). A normal development-branch merge preserves ancestry. No independent source acceptance or production activation is claimed.

AGY implemented the bounded voice state machine; Codex reviewed and corrected it. This uses the existing native browser SpeechRecognition path and existing Livewire prepare action. No commercial speech provider, transport, financial writer, database schema, dependency or microphone popup was added. Native recognition may use browser-managed remote processing; on-device recognition is not claimed.

A user starts recording. Only a nonempty final transcript with no interim speech starts the 1,000ms quiet timer. New speech resets it. A visible 1,250ms grace then permits Edit, Discard or immediate Send. Typing/Stop/Edit/Discard, recognition errors, page hiding and navigation cancel pending automatic dispatch. Generation checks reject stale callbacks. Changing text without an input event also prevents submission. Failed dispatch is never automatically retried. Typed text remains manually submitted. The feature flag `AI_VOICE_AUTO_SUBMIT_ENABLED` defaults to false; quiet/grace values are bounded to 200–10,000ms. These defaults were not activated in production.

Codex corrections beyond the worker handoff: abort errors clear timers; speech-start interrupts grace before an interim callback; planned text is checked again before dispatch; Edit/Send invalidate old callbacks; activation requires a boolean; recording remains indicated during grace; Edit has a visible keyboard focus outline and a 44px minimum target.

Verification actually executed:

- Node voice subset: 30 tests passed, zero failures/skips/cancellations. Covers flag-off behavior, stable-final completion, interim-only refusal, resumed speech, repeated recordings, permission denial, timeout/error/abort, Stop/Edit/Discard, stale callbacks, hidden/navigation cleanup, double Send and no automatic retries.
- Production Vite build passed: 61 modules, JavaScript 59.35 kB (gzip 22.03 kB), CSS 86.94 kB. Existing dependencies reused.
- Scoped Pint and PHPStan passed for changed configuration/translations. Blade views rendered successfully in the actual local browser. Mechanical UI detector returned no findings.
- Local authenticated Edge English/Arabic desktop and 390px Arabic layout inspection used a new owned disposable MariaDB schema and synthetic native-recognition callbacks injected only by the ignored QA router. The test-only grace was 10,000ms to inspect/cancel through browser automation; the production default remains 1,250ms. Edit preserved the transcript and stopped recording; Discard restored the original input; repeated recording remained available. No assistant_requests row or financial record was created. HTTP fakes refused stray external calls.
- Private ignored screenshot artifacts: `.ai/v22/a4-en-desktop-grace.jpg`, `.ai/v22/a4-ar-desktop-grace.jpg` and `.ai/v22/a4-ar-mobile-grace.jpg`. Mobile capture scaling was inconsistent in the browser backend; the 390px DOM/layout inspection is evidence, not a pixel-perfect mobile screenshot certification. Full-size Arabic desktop grace was captured and inspected.

Actual microphone hardware, dialect acoustic accuracy and real browser-provider audio latency remain NOT VERIFIED. Synthetic callbacks establish lifecycle/UI behavior only. Native unsupported/denial cases are tested with mocks, not claimed as actual device coverage. No paid model call or provider activation occurred. The local QA schema is cleaned after capture; ignored harnesses/evidence are retained locally and not published.

Eight changed files relative to A3: config/ai_assistant.php, resources/js/assistant-browser-voice.js, resources/lang/ar/ai_product.php, resources/lang/en/ai_product.php, resources/views/livewire/ai/assistant-launcher.blade.php, resources/views/livewire/ai/partials/composer.blade.php, tests/Frontend/assistant-browser-voice.test.mjs, this document.

Preserved: encrypted company key, consent/RBAC/tenancy, request budgets and historical reservations, canonical calculations, existing Sales/Purchase UNSAVED handoff and human Save/Post authority. No production access, financial mutations, PR/main merge, deployment, GitHub CI or protected Owner artifact changes. Independent review and separate rollout authorization remain mandatory.
