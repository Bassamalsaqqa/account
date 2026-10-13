# A6: secure mobile/PWA source checkpoint

Baseline: accepted A4 `3846f6366e82122b5460ca485533c10457e0e830`. Source implementation complete; independent acceptance and rollout authorization pending. No migrations or dependencies.

## Behavior and safeguards

`PWA_ENABLED` defaults to false. Enabled layouts advertise a neutral manifest and register the same-origin root `/sw.js`. Installation uses browser-native controls, with no additional onboarding.

The worker precaches exactly six neutral files: `/offline.html`, `/manifest.webmanifest`, `/icons/icon.svg`, `/icons/icon-192.png`, `/icons/icon-512.png`, `/icons/icon-maskable.png`. There is no runtime caching of application pages or build assets. Install fetches omit credentials, disallow redirects/HTTP cache reuse, and validate status, content type and nonprivate cache headers. All reads use only the owned `sta-pwa-v1` cache.

POSTs, cross-origin requests, query strings, encoded paths, sensitive routes and authorization/CSRF-bearing requests bypass interception. Other navigation uses the network with `cache: no-store`, falling back only to the neutral shell on network failure. Financial records, API/Livewire responses, forms, reports, attachments and credentials never enter this worker's cache. No background sync, offline transaction queue, telemetry or model dispatch. This governs this worker; unrelated browser history/HTTP caching has not been certified.

Only owned `sta-pwa-*` caches are removed during activation/rollback. Disabling unregisters only the exact same-origin root `/sw.js` registration, preserving other scopes. Existing tabs can remain controlled until navigation. Updates show a dismissible top notice; initial registration and another tab's update never reload an unsaved form. Reload requires the current user to choose Update now. Bump the worker cache version when changing precached content; validate static MIME types on the actual host.

Offline events abort recognition and cancel quiet/grace submission timers while retaining an editable final transcript. Starting recognition or submitting while offline is refused. No new provider or paid speech integration.

## Executed evidence

- Focused Node worker/client/voice tests: **45 tests, 45 passed, zero failed/skipped/cancelled**. Finite caching; private/redirected/incorrect-type install rejection; foreign-cache refusal; sensitive bypasses; owned rollback; explicit updates; offline cancellation/refusal without an offline event.
- Disposable MariaDB `PwaShellSecurityTest`: **3 tests, 18 assertions, PASS**. Actual login responses under enabled/disabled flags, neutral assets and default-off configuration.
- Pint and targeted PHPStan for changed PHP/config/localization and the feature test: PASS.
- Vite build: **62 modules**, CSS **87.21 kB / 15.55 kB gzip**, JS **62.69 kB / 23.20 kB gzip**.
- Actual Edge neutral-shell rendering: Arabic desktop and Arabic/English at **390 x 844**, document width and scroll width both **390 px**. Ignored screenshots retained privately. Viewport reset afterward.

Node tests simulate service-worker and recognition callbacks. Actual browser installation, installed-device offline behavior, real microphone/acoustic accuracy and Hostinger headers/capacity remain **NOT VERIFIED**. Shell rendering alone is not installation proof. No paid requests, production access, merge, deployment or CI.
