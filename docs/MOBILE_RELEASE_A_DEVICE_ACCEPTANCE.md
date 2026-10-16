# Mobile Release A — local HTTPS device acceptance

This is an isolated synthetic LAN staging environment, not production. Production remains `f9ef4bd5d179ae524c697df7cb839aa94857b5f1`. Do not enter real company data, production passwords, provider keys or financial records.

## Connect from a physical phone

1. Put the phone and PC on the same trusted Wi-Fi. Keep the PC awake. Staging URL: **https://192.168.1.118:8443**. This address may change after DHCP renewal; the current certificate is tied to this IP.
2. The phone-specific firewall rule was installed through an administrator prompt after the initial unprivileged attempt returned OS error 5. The Owner subsequently reached the site. If the rule is missing after cleanup, In an **administrator PowerShell**, run the narrow temporary rule below. Do not disable the firewall or forward router ports.

```powershell
New-NetFirewallRule -DisplayName 'Accounting Mobile A HTTPS LAN 20261016' -Direction Inbound -Action Allow -Protocol TCP -LocalAddress 192.168.1.118 -LocalPort 8443,8444 -RemoteAddress 192.168.1.110 -Profile Private -Program 'C:\Program Files\nodejs\node.exe'
```

3. Download the public test CA from **https://192.168.1.118:8443/__staging/ca.crt** (reachable application listener), or **http://192.168.1.118:8444/accounting-mobile-a-ca.crt**. This HTTP listener serves only that public certificate, never application/login data. Alternatively transfer the local `.ai/mobile-https/accounting-mobile-a-ca.crt` public certificate. Never transfer `ca.key` or `server.key`.
4. Verify CA SHA-256 fingerprint: `D1:AC:01:4F:0E:A1:CB:EB:C8:5A:AA:DF:F5:F6:22:58:45:60:95:87:83:B3:B6:B1:EA:AC:A8:23:9C:C2:59:FD`. The CA expires 23 October 2026 UTC; leaf expires 22 October. Use it only for this temporary test, then remove it.
5. iPhone: install the downloaded certificate profile in Settings, then Settings > General > About > Certificate Trust Settings > enable full trust for this specific test CA. See [Apple's certificate trust instructions](https://support.apple.com/en-ae/102390). Android: install it as a **CA certificate** under Security / Encryption & credentials / Install a certificate (labels vary by manufacturer). Reopen Chrome. The certificate warning must be gone; do not treat clicking through a TLS warning as acceptance. Do not disable browser security.
6. On the PC read the ignored local file `.ai/mobile-https/credentials.txt` for the randomly generated **synthetic-only** staging login. Use this login on the phone. It is not a production credential and is deliberately excluded from Git/PR.
7. Open `/__staging/identity` and record `source_sha` and asset hashes in the evidence form below. The initial preparation value may say finalization-in-progress; acceptance is only for the final committed identity. Display `/__staging/scan-fixtures` on the PC, or print it, so the phone can scan real Code128 and QR symbols for `PREVIEW-CARTON`. One carton is 24 base pieces; scan quantity should start at **1 carton**.

Camera capture requires a secure context; see [MDN getUserMedia](https://developer.mozilla.org/en-US/docs/Web/API/MediaDevices/getUserMedia). BarcodeDetector support varies, so test the locally bundled fallback too; see [MDN BarcodeDetector](https://developer.mozilla.org/en-US/docs/Web/API/BarcodeDetector).

## Evidence rules

Record device model, OS version, browser version, date/time, exact staging source/build identity, tester name and each result as PASS / FAIL / NOT TESTED. A desktop viewport, fake camera, canvas video, injected permission denial or automated decoder is **simulation**, never physical-device evidence. Save screenshots or short notes locally with no real business data; do not record camera video containing private surroundings. External human observations must be attributed to the named tester, rather than described as agent-reproduced hardware checks.

Use one iPhone with Safari and one Android phone with Chrome. Repeat camera/entry cases in **Sales Invoice, Sales Quotation, Purchase Invoice and Product Catalog**. Use only test documents. Saving test drafts is fine; posting is unnecessary for camera acceptance. Never share staging links outside the trusted LAN.

## Practical physical-device checklist

| Case | Action and required observation | iPhone Safari | Android Chrome |
| --- | --- | --- | --- |
| Identity/TLS | Correct final SHA and asset hashes, trusted HTTPS, no certificate warning; login and authenticated AR/EN pages work | NOT TESTED | NOT TESTED |
| Explicit permission | Load each scan page: no camera indicator/prompt. Tap Scan: browser prompts. Deny: localized error, no stream. Allow via site settings and retry: camera opens | NOT TESTED | NOT TESTED |
| Rear-camera focus | Rear camera selected where supported; scan printed Code128 at near/far distances and different lighting. Record focus/decoder time and any manual camera choice needed | NOT TESTED | NOT TESTED |
| Continuous/FIFO | Scan three different fixture codes/SKUs rapidly, then quantity/add/save draft; correct order and unit/quantities, no lost update or duplicate save. Existing edited lines unchanged | NOT TESTED | NOT TESTED |
| Repeat reads | Hold PREVIEW-CARTON for 3 seconds: one add only. Remove for >700 ms and re-present: exactly one further add. Scan Again deliberately re-arms. One scan = one carton, not 24 cartons | NOT TESTED | NOT TESTED |
| Other invalid line | Make another line's quantity invalid, then scan. “Item added; fix other lines” warning, no success beep/green confirmation, totals unavailable. Correct invalid input: totals return. Do not rescan to retry an already-added item | NOT TESTED | NOT TESTED |
| Close/navigation/Escape | Close removes camera indicator. Navigate away while scanning; indicator stops. With camera hidden, Escape on an attached keyboard closes Omnibox/Quick Product without camera interference | Owner confirms close/reopen preview; navigation/Escape hardware pending | NOT TESTED |
| Background/resume | Lock phone, switch apps and background Safari/Chrome while scanning: camera stops. Resume does not silently reopen; explicit Scan reopens successfully | NOT TESTED | NOT TESTED |
| Rotation | Rotate portrait/landscape while scanning and editing: controls remain reachable, fields/lines preserved, no duplicate add; close releases camera | Owner confirms preview positioning in Purchase; broader checks pending | NOT TESTED |
| Keyboard/safe areas | Focus quantity/price/search fields; numeric keypads appropriate; active field/save controls reachable; bottom bar/toolbars never cover keyboard or home indicator. Dismiss keyboard and verify bar restoration | NOT TESTED | NOT TESTED |
| AR/EN layouts | Arabic RTL primary and English LTR, phone portrait/landscape; names, numbers, currency and units readable. Search keyboard/focus and touch steppers work without losing edited fields | NOT TESTED | NOT TESTED |
| Phone search | Search `0590000000` / `59000`: Customer match, result contains name/code, not raw phone/balance. Test a restricted actor separately under the same category permissions | NOT TESTED | NOT TESTED |
| Lazy ZXing | Record whether native BarcodeDetector supports required formats. On a browser without it, first explicit Scan downloads the local hashed ZXing chunk and decodes Code128 + QR. An artificial forced fallback is supplemental evidence only; record how it was forced | NOT TESTED | NOT TESTED |
| Offline/slow | Go offline before scan: clear offline state, no camera/automatic replay. Interrupt network after capture: visible lookup error, no duplicate retry. Reconnect explicitly and verify current line quantities | NOT TESTED | NOT TESTED |
| Installed PWA | Install/Add to Home Screen, open standalone, login, AR/EN navigation, keyboard/safe areas, Scan/repeat/background/rotation and logout. Record OS-specific install steps | NOT TESTED | NOT TESTED |
| Logout/privacy | Logout; protected pages unavailable online. Offline after logout does not reveal private pages/data. No camera survives logout/navigation | NOT TESTED | NOT TESTED |
| Optional feedback | Opt in; supported tone/vibration only after accepted scan, no success feedback on review warning/error. Unsupported vibration/audio must not prevent visual operation | NOT TESTED | NOT TESTED |

Only `PREVIEW-CARTON` and product SKU `OIL-01` are initially seeded; scanning the SKU may select a different effective unit from its carton barcode. For three distinct codes, print existing fixture SKUs or create additional **synthetic** products/barcodes using authorized Product UI. Do not assume different symbols with the same payload are different items.

## Test record (complete per device)

- Tester / timestamp:
- Physical model / OS / browser version:
- Source SHA / manifest & lazy chunk hash:
- Certificate trusted without bypass:
- Browser tab or installed PWA:
- Native detector or bundled ZXing; observed evidence:
- Per-case results / screenshots / remaining failures:
- Sign-off: pending / accepted for this device only:

Current physical-device status: **PARTIALLY VERIFIED ? Owner iPhone 17 Pro Max / Safari**. The Owner reports latest iOS, but the exact numeric OS/browser version is unverified. After the camera correction, the Owner independently confirmed a moving preview, product addition, close/reopen and rotation in Purchase. See `review/mobile-release-a/finalization-physical-iphone.json`. Other cases and Android remain NOT TESTED. Owner reports the phone at `192.168.1.110` reaches port 8443; use that port's certificate endpoint if the separate 8444 bootstrap is unavailable. The agent verified the phone-specific Private-profile firewall rule is now enabled. Reachability is an Owner report, not camera acceptance. Agent browser receipts are desktop simulations only. LAN reachability, mobile CA trust, actual rear-camera/autofocus, keyboards and installed PWA remain unverified until a tester completes this record.

## Operation, isolation and cleanup

The PHP backend listens only on `127.0.0.1:8193`; Node TLS binds only `192.168.1.118:8443`. Only the public CA bootstrap is HTTP on `192.168.1.118:8444`. Fixtures are in a newly owned disposable MariaDB schema on local port 33312, never a production copy. Debug is off; email is an in-memory sink and AI/provider flags are off. Separate app key, synthetic random login, secure session cookie and session directory; no credentials/private keys in the PR.

The staging processes have a maximum 12-hour lifetime from startup. Keep the launching agent process/PC running during testing. Stop gracefully by creating `.ai/mobile-https/stop` in the Release A worktree; the fixture owner stops PHP, writes preservation/reconciliation results and drops only its owned schema, and the proxy closes. Remove the temporary firewall rule afterward:

```powershell
Remove-NetFirewallRule -DisplayName 'Accounting Mobile A HTTPS LAN 20261016'
```

Remove the test CA/profile from each phone, uninstall staging PWA/site data and remove the local CA/server private keys when no longer needed. Never publish this staging certificate/key or forward its ports to the Internet. Staging preparation does not authorize a Hostinger/production deployment or completion of off-host recovery, isolated restoration or real password-reset email verification.


## Final source and tested build

Application source: `b5a1ec31bc560330cee28b2780da628b5991f7e5`. Asset identities are in `review/mobile-release-a/finalization-build.json` and the live `/__staging/identity`. The final delivery commit may add documentation only. Owner-verified physical cases are limited to Purchase preview/product scan/reopen/rotation. This does not mark the NOT TESTED rows above as completed. The temporary diagnostic script was disabled after the investigation; it never captured or transmitted camera images/video.
