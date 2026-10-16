# Mobile Release A — finalization after accepted PR #54 review

The supplementary [independent review](https://github.com/Bassamalsaqqa/account/pull/54#pullrequestreview-5538361681) accepted source head `15820ad8e1dc65e2f6225d9345dac8cc7bc67db7`. This follow-up addresses only its requested phone lookup, Escape and mixed-invalid-line feedback, plus the black iPhone preview discovered during the authorized staging test.

**Final application source:** `b5a1ec31bc560330cee28b2780da628b5991f7e5`.

- Direct source parent: `705974ac9347b51d40f3b97bea8df7e862e40ea6` (phone/Escape/total finalization).
- That parent's parent is the accepted head `15820ad8e1dc65e2f6225d9345dac8cc7bc67db7`.
- Production/main baseline remains `f9ef4bd5d179ae524c697df7cb839aa94857b5f1`.
- The following delivery commit adds documentation/evidence only; use the PR head as the exact delivery SHA.

## Narrow corrections

1. **Phone lookup:** Customer/Vendor predicates now match the existing `phone` column, within their existing company and category authorization. Full or partial stored phone strings work; LIKE metacharacters remain escaped. Phone is not selected or placed in titles/subtitles, balances or financial details. This does not introduce phone normalization, new indexes or migrations.
2. **Escape:** the scanner's window handler prevents/default-stops Escape only while that scanner is open. Hidden scanners leave other dialogs untouched. Focused JavaScript and real local browser interactions verify Omnibox and Quick Product Escape on all three forms.
3. **Truthful total feedback:** all three forms mark totals invalid before recalculation and valid only after successful completion. When another malformed line prevents recalculation, an accepted scan returns `ok=true, requiresReview=true`: the item remains added exactly once, the warning explains that other lines need correction, and no success tone/green confirmation invites a misleading retry. Camera and hardware input share that result semantics. Desktop/mobile document totals and sales line totals are withheld while invalid; correcting the input restores the calculated preview. Save/post validation and financial authority remain unchanged.
4. **Physical iPhone black preview:** the Owner reproduced a black preview despite working product lookup on iPhone Safari. Staging metadata showed a live rear-camera track, advancing playback, valid 720×1280 frames and a visible element. The plain camera page and an independent body-level video on the same stream rendered correctly. Playback/transition and same-modal fresh-element attempts alone did not fix it. The reusable scanner now owns a body-level native preview aligned to the modal viewfinder, while the decoder reads a separate component video on the **same single MediaStream**. It tracks modal/viewport resize, hides the overlaid preview during error states and removes preview, reticle, observer and tracks on teardown. ZXing uses its public scan API without rebinding the stream; explicit Play feedback handles rejected playback. No second camera acquisition or image/video upload is introduced.

The shared PHP scanner dispatch now explicitly distinguishes its ProductIndex host and the three document hosts; redundant dynamic member checks were removed so the full scoped static check covers every host. Fresh actor/company/membership, same-company document context, category permissions, exact string arithmetic, canonical selection and FIFO guards remain intact.

No migration, dependency, posting engine, immutable financial history, stock valuation or check lifecycle change. The original implementation's accepted boundaries remain in force. The camera rendering correction adds client-side lifecycle ownership inside the existing reusable component, not a separate application or hosting service.

## Focused verification

See [verification receipt](review/mobile-release-a/finalization-verification.json), [HTTPS browser receipt](review/mobile-release-a/finalization-https-browser.json), [preview simulation receipt](review/mobile-release-a/finalization-preview-simulation.json) and [six-reconciliation receipt](review/mobile-release-a/finalization-reconciliations.json).

| Scope | Actual result |
| --- | --- |
| GlobalSearchTest + SearchVisibilityTest, owned disposable MariaDB | **13 tests / 69 assertions passed** |
| DocumentTouchControlsTest, owned disposable MariaDB | **5 tests / 99 assertions passed** |
| Camera + FIFO/hardware JavaScript | **20 tests passed** |
| Scoped Pint and PHPStan, including all four scanner hosts | **Passed** |
| Vite production build | **Passed** |
| Local HTTPS desktop Edge browser, actual bundled ZXing on synthetic canvas video | **13 checks passed; 0 page/console errors** |
| Displayed preview simulation | Playback through server update, distinct fresh preview after reopen, viewport alignment after rotation, all tracks ended on close; **passed** |
| Synthetic Accounting, Inventory, Sales, Payables, Money, Phase7 | **All six healthy** |

Tests reproduce/refute missing phone lookup, hidden Escape consumption, added-item/review feedback, no stale total presentation, subsequent correction, authorization/tenant boundaries, one-stream preview ownership, resize alignment, errors, duplicate readings and teardown. The initial focused red runs were not acceptance evidence: one fixture context/index assertion needed correction before the clean runs. No broad suite, composer qa/full test cycle or GitHub CI was run. Dependencies are unchanged from the previously audited release candidate.

The final initial JS is **78.05 kB / 27.82 kB gzip**; lazy ZXing remains **454.37 kB / 117.45 kB gzip**. Source and built asset SHA-256 values are in [build identity](review/mobile-release-a/finalization-build.json) and the staging `/__staging/identity`. The final bundled decoder is fetched from this build over local HTTPS; there is no CDN. The temporary Node TLS proxy is local development infrastructure only; production remains compatible with the existing Hostinger PHP/static-asset architecture.

## Physical evidence and remaining gates

[Physical iPhone evidence record](review/mobile-release-a/finalization-physical-iphone.json) attributes observations to the Owner's actual **iPhone 17 Pro Max / Safari**. The Owner reports “latest iOS”; no exact numeric OS/Safari version was provided or inferred. The Owner separately confirmed **moving preview, product added, close/reopen and rotation** on the final asset `app-B9uUC30F.js` in Purchase. This is physical human evidence, not an agent-operated device test. [Sanitized playback metadata](review/mobile-release-a/finalization-phone-playback.json) is supplementary: advancing frames alone did not prove visible rendering. No camera images or video were collected by the component or diagnostic.

[Final camera screenshot](review/mobile-release-a/en-phone-camera-simulated-final.png) is explicitly **desktop Edge synthetic-video evidence**, not a physical screenshot of the resolved iPhone case. The Owner supplied a screenshot of the original black-preview failure; no replacement physical screenshot was supplied. A local WebKit simulation attempt was inconclusive (synthetic media did not initialize); it is not a Safari hardware pass.

**Remaining device blockers/gates:** physical Android Chrome; all-four-workflow physical coverage; permission denial/regrant; prolonged continuous/repeat scanning; autofocus across light/distance; background/resume and logout/offline on hardware; onscreen keyboard/RTL/LTR matrix; installed iOS/Android PWA; mobile certificate trust acceptance and exact version recording. These are still open. The limited iPhone pass does not accept the entire device matrix or authorize deployment.

## Isolated HTTPS staging and operations

[Practical setup and device checklist](MOBILE_RELEASE_A_DEVICE_ACCEPTANCE.md).

- App: **https://192.168.1.118:8443** on the trusted Wi-Fi; Owner phone `192.168.1.110` reached it after the narrow Private-profile firewall rule was installed through the administrator prompt.
- Certificate: `https://192.168.1.118:8443/__staging/ca.crt`; optional certificate-only HTTP bootstrap on 8444. No app data/login is served by that HTTP listener.
- Barcode fixture: `/__staging/scan-fixtures`; basic camera comparison: `/__staging/camera-check`.
- Synthetic login is stored only in ignored `.ai/mobile-https/credentials.txt`. No production credential, .env, backup or database copy is included. Separate app key, secure cookie, session directory, email sink and disabled application AI/provider flags.
- Backend binds only loopback `127.0.0.1:8193`; TLS binds the LAN address. Current fixture is a new owned disposable schema. Processes are deliberately left running for the checklist, with a 12-hour maximum lifetime. Creating `.ai/mobile-https/stop` stops the owner/proxy and drops only that owned schema. Then remove the temporary firewall rule and test CA/profile as documented.
- The temporary camera metadata/script and test buttons were disabled/removed after diagnosis. Raw local diagnostics remain ignored for provenance; public receipts contain only sanitized technical evidence.

The earlier staging fixture stopped during session transition and was not adopted/reset. Its synthetic saved draft was inspected read-only: product 1, quantity 2.000000, product unit 2, draft state, independently confirming the initial scan reached the document. It remains quarantined locally (`accounting_p8_tmp_0cbaa556cfde`), alongside previously documented interrupted disposable fixtures. The fresh staging fixture is intentionally still active; do not call it cleaned until its owning stop path finishes. All focused test schemas were cleaned by their own runners.

**No merge, production deployment, Hostinger access or production financial changes occurred.** PR #54 is updated for final independent review. Off-host backup recovery, isolated restoration and real password-reset email verification remain separately outstanding. Financial endorsement/equity remains consultation-only.
