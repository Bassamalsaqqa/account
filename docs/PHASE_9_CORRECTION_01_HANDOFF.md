# Phase 9 Correction 01 — final source acceptance gate

Status: **focused source verification complete; independent architect acceptance pending**. This focused correction addresses G1/G2 from
[independent architect review 5475471444](https://github.com/Bassamalsaqqa/account/pull/18#pullrequestreview-5475471444).
It does not authorize merge, deployment or production access.

## Source and authority

- Repository and existing PR: [Bassamalsaqqa/account #18](https://github.com/Bassamalsaqqa/account/pull/18).
- Branch: `phase/9-documents-catalog-sharing`.
- Previous reviewed head: `0b1ddaf479622dc33bdd9b99f2a93728eccabdde`.
- Unchanged accepted main: `3f82b947d319d519cea6d25cecd7724bfc909672`.
- Preserved planning ancestor: `098b17bf0559a8b8da2308f12b73cee52ea78cd9`.
- Production runtime remains `96c310f30a07e97ab8e04d5afbf0b2bb805f4317`.

The final commit identity is reported in the PR correction disposition and final
engineering handback. The [original integration handoff](PHASE_9_SOURCE_ACCEPTANCE_HANDOFF.md)
retains its historical P9-A–F evidence. Its broad-suite counts are not new
Correction 01 runs. C1–C5, catalog-media Option A, protected Owner delegation,
immutable issuance and canonical accounting/stock writers remain unchanged.

## G1 — terminal catalog revoke

`CatalogService::state` serializes lifecycle transitions under fresh Company-first
authorization and catalog locks. Pause suspends managed output without retiring
the grant; authorized resume restores the same valid URL. Revoke atomically sets
all active catalog grants inactive with revocation provenance and minimal audit
events. Previously revoked catalog rows from the reviewed implementation, whose
grants could still be active, also retire those grants before a deliberate state
transition away from revoked.

Resume, republication and access edits never revive a retired grant. Recovery
selects the latest active, unrevoked grant and refuses to fabricate one when
retired history exists. `PublicShare::isValid` rejects a revocation marker even
if an inconsistent row still has `is_active=true`.

Explicit New Link requires fresh Company/membership, catalog view/share/publish
and applicable price authority. It generates an independent random 40-character
token, unique hash, grant ULID and Company-scoped request identity. Issuance
atomically retires previous active grants. Exact request-key/payload retries
converge; changed passwords, publication intent, expired/retired request results
and reused retired requests fail. Historical grants, revocation audits and
publication receipts are retained. Access-edit state includes the exact grant
identity; stale edits cannot target a replacement, even with identical settings.

The localized composer distinguishes Pause, Resume, terminal Revoke and explicit
New Link. Pause/revoke clear link/QR delivery controls. QR presentation rechecks
the current grant and refuses a stale URL. Approved static Product photographs
may remain independently available after managed revocation, as the unchanged
marketing-media notice explains.

## G2 — expiration instants

`ShareExpiry` centralizes interpretation, validation, recovery labels and local
instant display. A catalog date is valid through that entire Company-local day.
The exclusive boundary is the start of the following local day converted to UTC;
today is valid until that boundary. Creation and access editing use the same
conversion, including DST days shorter or longer than 24 hours. New grants carry
`catalog_v2`; `catalog_v1` keeps its actual recorded instant. Reads and recovery
never write expiry. Password-only edits and unchanged displayed dates preserve
the original timestamp and profile. Explicitly choosing a different date adopts
the new valid-through policy. UI shows the actual Company-local instant/offset
and clearly describes legacy preservation. If Company timezone changes and the stored
instant no longer falls at a local midnight, recovery presents that actual instant
without implying full-day validity or renewing it.

Financial N-day lifetime means exactly N × 86,400 elapsed seconds from the actual
issuance instant captured inside the transaction. Defaults/maxima remain 7/30
days for passworded Statements and 30/365 for the other four financial subjects.
The manager passes a duration, never a midnight-derived absolute timestamp.
Duration is part of stable retry intent; later retries retain the issued expiry.
Absolute-instant callers remain compatible, with the same finite-lifetime caps.
Legacy stored timestamps are not migrated, reinterpreted or renewed on recovery.

## Schema and integrity

No migration, index change, dependency or role-seeding change is required.
Existing unique token-hash and Company/request-key constraints permit multiple
historical grants per catalog; subject lookup is not unique. Disposable MariaDB
tests exercise those actual constraints and retained publication receipts.
Company-first locks serialize revoke/read/new-link and simultaneous retry paths.
The public controller's existing post-render fresh lifecycle check prevents a
response prepared before revocation from disclosing content after that committed
revocation. Economic-table fingerprints verify that management/output actions
do not write financial, stock, allocation or numbering history.

## Delegation and independent verification

AGY implemented bounded UI and regression packages in detached linked worktrees
from the exact reviewed head. Codex collected allowlisted files, reviewed source,
corrected stale-grant/legacy-expiry/test gaps, and independently ran relevant
gates. Worker success alone was not acceptance.

- UI handback: `e7ac42f9-06ba-4bf9-a886-0797a6094ca0`.
- Regression handback/refinement: `8b5e63aa-a933-4e2a-a461-8760cffde484`.
- Browser delegation: `eb64e303-7c4b-489c-aeb0-54065922657f`. Both bounded
  AGY browser attempts timed out while troubleshooting local transport. Codex
  corrected and executed the ignored harness independently, using loopback TLS
  and the real application login/CSRF/Livewire paths; no security override was
  added to application code. Worker browser success is not claimed.

Original worker bytes and diffs are verified in local ZIP archives under
`.ai/delegations/phase9-correction01/worker-archives/`; tracked worker trees are
clean and new untracked worker tests remain preserved. The local correction
directory retains relay receipts, JUnit/logs and browser evidence. These ignored
development artifacts contain no production fixtures and are not public runtime
dependencies.

## Focused acceptance evidence

The independent ordered MariaDB gate passed **96 tests / 4,704 assertions**.
After adding the Company-timezone-change display guard, the two affected suites
passed **24 tests / 224 assertions** (23 repeated cases plus one new regression).
Deduplicated coverage is **97 cases / 4,710 assertions** across these runs; it is
not claimed as one uniform-source 97-case invocation. The final helper and
publication display change were covered by the second run. Logs/JUnit are
`focused-final.*` and `timezone-display-final.*` in the local correction directory.

| Focused suite | Cases | Assertions | Evidence |
|---|---:|---:|---|
| CatalogComposer | 20 | 122 | Independent ordered gate |
| CatalogLifecycleExpiry | 14 | 526 | Independent ordered gate |
| CatalogPublication | 13 | 128 | Final affected-suite rerun |
| PublicCatalog | 10 | 3,269 | Independent ordered gate |
| ShareExpiry | 11 | 96 | Both independent gates |
| FinancialShares | 12 | 168 | Independent ordered gate |
| FinancialShareRegressions | 11 | 313 | Independent ordered gate |
| PublicationConcurrency | 6 | 88 | Independent ordered gate |

Concurrency fixtures intentionally commit to test independent connections, so
that suite runs last; an earlier ordering contaminated global-count assertions.
The isolated financial suite and corrected ordered gate passed. This was a test
execution-order correction, not a relaxation of orphan-grant rejection.

| Requirement | Verified regression |
|---|---|
| Pause/resume | Managed routes denied during pause; original valid URL restored on resume |
| Terminal revoke | Reactivation, republication and access edits cannot revive old token; revoked marker defeats inconsistent active flag |
| Explicit New Link | Different 40-character token/hash/grant; same-key retries converge; changed/retired keys and stale access states rejected |
| Authorization/lifecycle | Fresh view/share/publish and price intersections; passworded/unpassworded, priced/unpriced, foreign Company, disabled Company and expired grants |
| Managed output | AR/EN GET/HEAD, HTML, JSON, print, PDF and QR destinations denied after revoke; approved static marketing asset remains independent |
| Concurrent operations | Same-key issuance, conflicting intent, revoke/new-link races, committed revoke during actual public render; stale PDF bytes discarded |
| Publication/storage | Existing unique constraints, historical grants/audits and publication receipts retained; forward/down/forward preservation on disposable MariaDB |
| Catalog date | Asia/Hebron and contrasting timezone, near midnight, full selected day and exclusive next-day boundary, DST, create/edit/recover |
| Legacy date | Genuine v1 and null-profile records retain timestamps; password-only recovery/edit and Company-timezone changes do not renew |
| Financial duration | Exact elapsed 24-hour days from issuance; default/max Statement 7/30 and other 30/365; retries, invalid/max lifetimes and expired grants |
| Security/integrity | Existing C1–C5 issuance/gating, ciphertext limits, zero economic writes and approved-media immutability regressions retained |

Full Pint, Larastan Level 6 and Blade compilation passed independently. Production
Vite build passed. No dependency, financial writer or migration changed. No
2,000-case suite or 709-check browser matrix was repeated. No production
reconciliation was required or executed.

Final independent browser gate: **179 assertions passed, 0 failed**, with
**29 fresh screenshots**, Arabic/English at 1440/390 pixels. The actual application
login, CSRF, Livewire and password flow ran over owned loopback TLS. It verified
Pause/Resume, terminal Revoke, distinct New Link, date validation (yesterday/today),
passworded issuance/unlock, unchanged URL on access edits, local timestamp display,
reactive exact-duration hints and neutral financial first-view disclosure.
Language/direction, horizontal overflow and actual Tab focus movement passed.
No JavaScript exceptions/console errors or HTTP 5xx were observed.

The newly issued catalog QR PNG was decoded with ZXing-C++ and matched the exact
new HTTPS destination; the retired destination remained denied. No token is
published in this document. Codex visually inspected Arabic/English New Link and
English expiry mobile screens plus the terminal-state desktop screen. This is
focused visual evidence, not another full Phase 9 browser acceptance procedure.

Local artifacts are under `.ai/delegations/phase9-correction01/browser/evidence/`:
`browser-evidence.json`, `new-catalog-qr.png`, and the 29 screenshot names recorded
in the JSON. The final gate ran 2026-10-10 04:34:09–04:35:57 UTC; its owned schema
was cleaned. Backend schemas, PHP server, TLS helper and owned Chrome session were
also cleaned. Private fixture credentials/token destinations remain ignored local
artifacts and are excluded from the PR.

## Complete correction file inventory

- `app/Livewire/FinancialShareManager.php`
- `app/Livewire/Pages/Catalogs/CatalogComposer.php`
- `app/Models/PublicShare.php`
- `app/Services/Catalogs/CatalogService.php`
- `app/Services/Sales/IssuedFinancialShares.php`
- `app/Services/Sales/PublicShareService.php`
- `app/Services/Sales/ShareExpiry.php`
- `docs/PHASE_9_CORRECTION_01_HANDOFF.md`
- `docs/PHASE_9_ENGINEERING_PROPOSAL.md`
- `docs/PHASE_9_P0_DISCOVERY_AND_EXECUTION_LOCK.md`
- `docs/PHASE_9_SOURCE_ACCEPTANCE_HANDOFF.md`
- `docs/adr/0009-documents-secure-sharing-and-catalogs.md`
- `resources/lang/ar/catalogs.php`
- `resources/lang/ar/sharing.php`
- `resources/lang/en/catalogs.php`
- `resources/lang/en/sharing.php`
- `resources/views/livewire/financial-share-manager.blade.php`
- `resources/views/livewire/pages/catalogs/catalog-composer.blade.php`
- `tests/Feature/Phase9/CatalogComposerTest.php`
- `tests/Feature/Phase9/CatalogLifecycleExpiryTest.php`
- `tests/Feature/Phase9/CatalogPublicationTest.php`
- `tests/Feature/Phase9/FinancialSharesTest.php`
- `tests/Feature/Phase9/PublicCatalogTest.php`
- `tests/Feature/Phase9/PublicationConcurrencyTest.php`
- `tests/Feature/Phase9/ShareExpiryTest.php`
- `tests/Support/phase9-publication-worker.php`

## Preservation and release boundary

All nine protected Owner files and both original 708-line roadmap copies are
byte-for-byte preserved. Roadmap SHA-256:
`63c97eeef744a54e9cbbfe991c91f2e057397a755d8d5f1a79317c8f19843f35`.
The previous documentation/planning branch and published history remain intact.
No reset, force push, rebase, merge, deployment, Hostinger access or production
changes occurred. Phase 10–13 implementation remains out of scope.

G1 thread `PRRT_kwDOUxMIV86q8s-M` and G2 thread `PRRT_kwDOUxMIV86q8s-U`
are addressed by this correction. Both remain open for
independent architect acceptance; the correction disposition and exact source
are included in the existing PR description. No additional bot review is requested.
