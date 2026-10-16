# Unified Assistant V1 production release — 2026-10-12

Production is online at `https://account.palsync.net` with Unified V1 and the implemented AI/API platform switches enabled for Owner configuration. No provider credentials were added and no paid requests were made. Company policy, provider selection, dated pricing, privacy terms, budgets and explicit consent remain prerequisites to actual provider use. This is a controlled Owner-testing release, not certification of all operational readiness gates.

## Source and authorization

| Identity | Exact value |
|---|---|
| Accepted PR #28 source | `15032eadcab49f66e14a97ce87da3205da7ea308` |
| Architect review | [5488536622](https://github.com/Bassamalsaqqa/account/pull/28#pullrequestreview-5488536622) |
| Merged/deployed main | `d9d9ede3e42926f9b51084a3ed0ba224f0f3fa66` |
| Accepted and deployed tree | `296213e2af44c22e1e667d23de4d51967e6819c2` |
| Previous healthy runtime / rollback source | `db17512798af5ba6d9d5e7e90fb326ca44718f34` |
| Previous runtime tree | `a45fee80490af0c44ac7a6ed89ffd6dc87c84ba7` |
| Main immediately before merge | `c44a432afe496e6def0cffb75be2724a2adada67` |

[PR #28](https://github.com/Bassamalsaqqa/account/pull/28) was verified at the accepted head, marked ready and normally merged without rewriting history. The merged tree is identical to architect-accepted source. Migrations, Composer/npm manifests and lockfiles, canonical Accounting/Inventory service paths and public entry-point source are unchanged from the previous runtime. No implementation correction or database migration was necessary during this release.

The initial release instruction required features off. The Owner subsequently explicitly requested that all implemented features, including the API, be enabled and ready for credentials/limits to be supplied in Settings. That later authorization controls the final runtime configuration. It does not relax authentication, financial confirmation, permissions, tenant isolation, provider consent or cost controls. Actual paid provider operation was not tested or activated by the operator.

## Preservation-first execution

The healthy prior source and active permitted Owner session were checked before maintenance. Accepted assets were built locally with Vite 7.3.6: 61 transformed modules; build succeeded. The uploaded archive and each of its four files were SHA-256 verified before extraction. Unexpected archive members and links were rejected. Public build directories/files were normalized to 0755/0644.

A deployment lock, pre-rendered 503 maintenance page and request drain protected source/autoload transitions. On-host encrypted SQL and secret-bearing application-state checkpoints, a verified code bundle, exact business-file manifests and prior asset/public-entry-point rollback buffers were prepared before code mutation. Private checkpoint/evidence directories remain 0700 and files 0600. No production archive, SQL, credentials, application keys or recovery keys were downloaded or sent to a worker. These on-host checkpoints do not establish independent off-host backup or key recovery.

The accepted merge was checked out detached. Generated Laravel provider/config/route/event caches were cleared selectively; Composer optimized autoload was rebuilt before application bootstrap. Production Composer install used the unchanged lockfile and required no dependency changes. Package discovery, config/route/view/event cache generation and non-destructive public asset synchronization succeeded under Hostinger PHP 8.4. Already-applied migrations and bootstrap grants/configuration were preserved; no migrations, permission provisioning, reseeding or business writes were executed.

## Actual verification

| Check | Result and evidence boundary |
|---|---|
| Schema/migration preservation | PASS: 89 tables, 67 applied migrations, zero pending migrations; unchanged |
| Six canonical domains | PASS: Accounting, Inventory, Sales, Payables, Money and Phase 7 reconciliations healthy for all companies before source change and after activation |
| Pre-activation data fingerprints | PASS: all 86 nonvolatile tables matched exactly; cache, cache_locks and sessions were the declared volatile exclusions |
| Final data fingerprints | PASS: 85 nonvolatile tables matched exactly; all users' remaining columns independently matched the fresh checkpoint, as explained below |
| Roles, permissions, sequences and membership | PASS: exact preservation; no new grants or business numbers consumed |
| Business files and public/private assets | PASS: retained business-file hashes matched; accepted four-file build verified in both destinations; custom public PHP handler preserved |
| Authenticated AR/EN source-release checks | PASS: 26 actual HTTPS checks, 13 routes per language, using the Owner's existing session while public maintenance remained active |
| Routes checked | Dashboard; Sales/Purchase creation and lists; Products; Customers; Vendors; Inventory; Reports; Settings; AI Settings; Money |
| Language/layout/Livewire | PASS: HTTP 200, authenticated layout, Arabic RTL/English LTR, Livewire snapshots, explicit `ai.assistant-launcher` alias resolution on Linux |
| Original disabled boundaries | PASS before activation: API 404 before queries; AI/speech/provider dispatch off with zero ceilings and no outbound requests |
| Owner-authorized enabled switches | PASS: assistant, report questions, speech, direct BYOK dispatch approval and command API enabled in effective cached configuration |
| API authentication | PASS for anonymous refusal (401). Existing older session was correctly refused and invalidated; fresh-login authenticated API 200 verification remains NOT VERIFIED pending Owner sign-in |
| Fresh application errors | PASS: zero new application error entries during release verification |
| Reopening | PASS: maintenance removed; public login HTTP 200 and anonymous API HTTP 401; reopening checks completed 2026-10-12 at approximately 09:44 UTC |

During post-activation HTTP checks, the API refused the reused session because a fresh API login was required. Laravel's normal logout rotated the Owner's `remember_token` and its `updated_at`, so the strict final users-table digest correctly detected a difference. The inspector did not conceal this failure or exempt all user data. A separately reviewed on-host, memory-only comparison against this release's newly generated encrypted SQL checkpoint established that these two Owner logout fields were the only user-column differences. Passwords, identity, 2FA data and every other user column were unchanged; other users were unchanged. That proof was bound to the before/after user-table digests before final preservation acceptance. No SQL import, restore, state extraction, historical recovery archive access or secret export occurred. Authentication was not bypassed and no replacement session was manufactured.

The enabled-state page inspector reached API verification after completing its AR/EN page loop, but session invalidation prevented its final per-route report from being emitted. That interrupted run is not counted as a second completed 26-check evidence packet. The durable 26-check source-release report above remains the completed authenticated rendering proof; a clean authenticated enabled-state/API packet requires fresh sign-in.

## Enabled configuration and Owner setup

Effective flags: `COMMAND_API_ENABLED=true`, `AI_ASSISTANT_ENABLED=true`, `AI_PROVIDER_APPROVED=true`, `AI_ASSISTANT_PROVIDER=direct`, `AI_SPEECH_ENABLED=true`, `AI_QUESTIONS_ENABLED=true`; no platform-disabled company IDs. Existing unrelated environment settings and secrets were preserved byte-for-byte. Only the reviewed flag/cap keys changed, with a restricted on-host pre-activation environment copy available for rollback.

Bounded platform testing ceilings: USD 0.01 per request, USD 1 per company/user daily, USD 5 per company/user monthly. Company settings can choose lower limits; raising these platform ceilings requires a deliberate operational change. Company budgets/pricing remain unconfigured rather than being invented by the operator.

At activation verification there were zero company AI policies, zero provider connections and zero assistant requests. Open [AI Settings](https://account.palsync.net/settings/ai) after a fresh login to configure company opt-in, supported text/speech connections, allowed operations/reports, pricing, privacy and limits. Provider credential provisioning requires password confirmation; secrets remain encrypted and company-scoped. Consent is explicit for the selected primary and applicable approved fallback, independently scoped for transaction and report planning. Fallbacks must use the same credential source as the primary. Manual workflows remain available.

The existing command API is **session-authenticated**, not an API-key/Bearer-token service. It is now enabled at `/api/v1/*`; use a fresh authenticated database session, selected company and CSRF token for mutations. There is no shipped API-key issuance setting to fill in. No authentication credential, role or API token was created during release. API financial execution retains Preview/Confirm/Execute and the canonical posting workflow.

## Rollback and remaining gates

Reviewed code/assets/environment rollback guards and checksummed prior artifacts remain restricted on-host. Rollback restores the prior runtime and generated caches, preserves the 89/67 database, and does not import SQL or reverse migrations. Its preservation comparator accounts only for the independently proven normal Owner logout fields. No rollback was needed or executed. After Owner UAT creates legitimate business/configuration changes, capture a fresh current-state preservation baseline before any later rollback; do not compare legitimate new records against the release's older fingerprint or overwrite them.

NOT VERIFIED: fresh-login authenticated enabled-state API response, real BYOK delivery/billing/DNS/TLS/privacy behavior, physical microphone/transcription behavior and all-feature Owner UAT. These remain explicit evidence gaps rather than implied success from feature enablement. The Phase 10 P1 gates—independent off-host backup/key custody, both actual isolated recovery lanes, and real approved email/account recovery—remain deferred and unproven. This release does not claim customer readiness or measured RPO/RTO.

No GitHub CI, redundant PHPUnit suite, production fixture/financial transaction, paid provider call, secret rotation or production schema change occurred. Accepted source evidence remains the previously executed focused subset: 166 tests / 1,657 assertions at `15032ead`, zero failures/errors/skips; it was not rerun merely to deploy unchanged source. Protected Owner specifications, private evidence and unrelated worktrees were preserved. The deployment record is published separately from accepted runtime source.

Historical records: [Phase 12 incident/recovery](PHASE_12_PRODUCTION_DEPLOYMENT.md), [Unified implementation ledger](UNIFIED_AI_ASSISTANT_V1_IMPLEMENTATION_LEDGER.md), [Hostinger deployment guide](HOSTINGER_DEPLOYMENT_GUIDE.md).
