# PR #56 — two-issue correction handback

Reviewed parent: `fbaa4e0ac431a77557d1d47848936f407a1c90c3`. Main / Owner-reported production baseline remains `d27adbad93fce8bd7aaf8afa6cdfaa0e33b91bde`. The PR head identifies the exact new correction commit; no merge, deployment, Hostinger operation or production-data access was performed.

Both current reviews and both inline threads were read, including [supplementary review 5573175032](https://github.com/Bassamalsaqqa/account/pull/56#pullrequestreview-5573175032). These changes address the two P2 findings only; independent acceptance is still required.

## Corrections

1. `company-data-download.js` now sends **`Accept: application/json, application/zip`**. Laravel therefore negotiates its existing password-confirmation middleware as JSON/423 instead of redirecting fetch to HTML. `ExportGuard::ensurePasswordConfirmed()` also returns 423. Permission/membership revocation remains forbidden, not a password-confirmation challenge. The client presents the existing confirmation action and requires a deliberate explicit download retry; no ZIP/prepared audit is created before authorization.
2. Mutating Fortify HTTP 5xx responses are now **uncertain**, including JSON, malformed JSON and HTML errors. Existing lifecycle cleanup clears stale QR/key/recovery-code material and invalidates pending responses. The existing uncertainty state blocks blind mutation retries until the user reloads authoritative state. Known pre-mutation 422/423 responses and read-only GET failures remain retryable. Malformed successful mutation responses retain the prior uncertainty protection; they are not silently accepted.

Only three runtime files changed: the two JS modules and `ExportGuard.php` (eight added lines / three removed lines, including comments). No new endpoint, schema, dependency, posting, inventory or quantity-stepper change was made.

## Independent verification actually run

| Check | Actual result |
|---|---|
| Baseline reproduction | ZIP-first header yields Laravel `expectsJson=false`; two simulated post-commit 503 regeneration attempts produce two writes, `uncertain=false`, stale codes still visible. |
| New JS regressions against reviewed source | **16 expected failures / 4 passing controls**; confirms the tests detect the reviewed defects. |
| Corrected JS regressions + existing security/download checks | **32 tests passed**. Covers all five applicable mutation methods with 500/502/503, malformed/HTML responses, stale-secret clearing, retry blocking, 422 retry, 423 reauthentication, GET retry, malformed success JSON and lifecycle/download protections. |
| Selected existing export regressions | **8 tests / 146 assertions passed**: complete ZIP, non-Owner Admin, revoked/inactive membership, missing/expired confirmation, changed password, revoked delivery and private cleanup. |
| New reauthentication tests + actual Fortify-route regression file | **14 tests / 125 assertions passed**. Exact browser Accept/XHR headers are used with real application POST routes, not a `postJson` shortcut. Includes the real named Fortify confirmation route and explicit ZIP retry. |
| Scoped PHPStan | ExportGuard: **no errors**. |
| Scoped Pint / syntax / diff checks | **Passed** for changed PHP and JS modules/tests. |
| Vite build | **310 modules passed**; `app-D6NVJO4y.js`, unchanged CSS `app-YMISaSSE.css` and lazy ZXing `index-B2ZkNDM-.js`, with valid manifest references. |

Accepted database runs used newly owned disposable MariaDB schemas on loopback port 33312; both runner schemas were cleaned. No broad/full suite, GitHub CI, paid application-provider call or repeated financial reconciliation cycle was run. The unchanged quantity-stepper PHPStan warning remains outside this correction.

AGY supplied an isolated implementation/regression draft and the PHP test file. Codex independently reproduced the defects, reviewed the draft, retained the minimal patch, tightened test cleanup/authorization assertions and ran the accepted gates. AGY's initial print timeout was treated as partial output despite the relay's completion label. A draft shortcut that swallowed malformed successful JSON was rejected; a regression preserves that uncertainty protection. No worker narrative is treated as acceptance.

## Actual local HTTP delivery evidence

[Sanitized wire evidence](evidence/PR56_LOCAL_HTTP_CONTRACT_20261019.json) comes from an actual local PHP HTTP/1.1 server, curl cookie session, normal CSRF tokens and a fresh synthetic company:

- JSON-first export POST before confirmation: **423 JSON**, no redirect or export file.
- Real configured Fortify password-confirmation route: **201**.
- Deliberate subsequent export POST: **200 application/zip**; declared and received length both **19,829 bytes**; no content encoding.
- ZIP contains **40 CSV datasets + manifest**; every CSV row count matches the manifest; generated private archive is removed after delivery.
- Server, sessions, cookies, ZIP and owned database were cleaned. This is local HTTP evidence, not a physical browser/device or Hostinger measurement.

The fixed 40-dataset registry, export service/controller, CSV formatter, consistent-read strategy, lock/limits, delivery revalidation, temporary-file cleanup and financial/stock writers are unchanged from the reviewed parent. Existing company isolation and immutable-history protections remain. The original [A.2 handback](PRODUCT_TRUST_A2_REVIEW_HANDBACK_20261019.md) remains the broader feature evidence; this document records only the new corrections.

## Remaining release/device gates

The application emits an ordinary private no-store ZIP with a known byte length; the checked repository `.htaccess` does not rewrite or compress this response. The frontend intentionally still requires a positive accurate `Content-Length` and exact received bytes before initiating the file save. This retains its truncation/revocation protection.

**Actual Hostinger delivery remains unverified under this source-only authorization.** At an authorized staging/release smoke check, PHP/LiteSpeed/proxies must preserve the ZIP bytes and accurate Content-Length, avoid content-encoding transformations or cache reuse of private downloads, and allow the bounded preparation/transfer to complete. Live ext-zip, MariaDB `max_statement_time` and protected writable storage checks also remain target-host prerequisites, not claims established by local tests. No hosting setting was changed.

No new physical iPhone/Android or authenticator-app acceptance was performed. Mobile ZIP persistence and proxy-specific behavior are not proven by JS fixtures or the local HTTP probe. Return this exact corrected head for independent acceptance; **do not merge or deploy without separate Owner authorization**.

Ignored local probe scripts/logs, AGY briefs/results and the archived worker draft are deliberate local artifacts; they are outside the PR and contain no production export or credentials. The separate root checkout's earlier translation drafts and Owner documents remain untouched.
