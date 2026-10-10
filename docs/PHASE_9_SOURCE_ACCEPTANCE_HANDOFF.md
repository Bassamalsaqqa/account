# Phase 9 source acceptance handoff

Current status: **Source Accepted / Merged / Deployed / Production Verified**.
See the [2026-10-10 production acceptance](PHASE_9_PRODUCTION_ACCEPTANCE.md) for
release `8d8428261cd2a10690ab77a5c7271e46b5ff5217` and independent live evidence.
The identities, authority limits and QA below are the historical implementation handoff.

Historical status: **IMPLEMENTED — READY FOR INDEPENDENT ARCHITECT REVIEW**.
All package and integration gates below were verified. This document does not
authorize merge or deployment.

The focused [Correction 01 handoff](PHASE_9_CORRECTION_01_HANDOFF.md) supersedes
the original catalog revoke and expiry behavior reviewed at
`0b1ddaf479622dc33bdd9b99f2a93728eccabdde`. The historical broad QA counts below
remain prior integration evidence; Correction 01 has its own focused gates.

## Exact baseline and authority

- Repository: `Bassamalsaqqa/account`.
- Implementation branch: `phase/9-documents-catalog-sharing`.
- Accepted main baseline: `3f82b947d319d519cea6d25cecd7724bfc909672`.
- Accepted planning tree: `145f8e270a01c1fe16752da1c40ca0433213b732`.
- Planning source: `92e83a1ff1912bc25ba2c664ba51f38c6289e22b`, merged through
  [PR #17](https://github.com/Bassamalsaqqa/account/pull/17).
- Preserved planning ancestor: `098b17bf0559a8b8da2308f12b73cee52ea78cd9`.
- Production runtime remains `96c310f30a07e97ab8e04d5afbf0b2bb805f4317`.
- Accepted production runtime tree remains `a71f567ba54b16b30d4d1d4643a14a960e0ff98f`.

The Product Owner explicitly authorized all P9-A–F implementation, additive schema,
delegation, local QA, commits and publication of one implementation PR. Merge,
deployment, production changes and Phase 10–13 implementation remain unauthorized.
The [P9-0 execution lock](PHASE_9_P0_DISCOVERY_AND_EXECUTION_LOCK.md) remains the
scope and policy authority. C1–C5 and catalog-media Option A were implemented
without reopening their decisions. [ADR 0009](adr/0009-documents-secure-sharing-and-catalogs.md)
records the resulting architecture.

## Package outcomes

| Package | Result | Main implementation boundaries |
|---|---|---|
| P9-A | Internally accepted | Compatible Sales DTOs; exact currency/Unit/lifecycle presentation; authorized document settings; local-font, bounded private mPDF/print rendering |
| P9-B | Internally accepted | Four private Purchase, Purchase Return, Vendor Payment and Vendor Statement outputs; canonical read-model authority and cost redaction |
| P9-C | Internally accepted | Deliberate first view; fresh source authorization; encrypted issuance; original receipt disclosure; expiry/password/revocation; management and intentional sharing |
| P9-D | Internally accepted | Same-company catalog composition, approved publication receipts, frozen media/text, prices OFF, stable managed link, public responsive output |
| P9-E | Internally accepted | Stored Unit-specific barcode symbols, bounded A4 sheets, decoded output, copy/QR/native/WhatsApp/email composition |
| P9-F | Internally accepted | Final guarded broad QA, inherited test reconciliation, static/build/Blade gates, expanded browser evidence and exact-source PR |

Historical company/party identity, prices, tax, stored FX, quantities, conversion,
allocation and valuation facts come from immutable snapshots/canonical reads.
Presentation adapters do not calculate independent payable balances or write
posting batches, allocations or stock movements. Current decorative branding is
separate from posted identity and historical commercial terms. Private receipts
show later applications as a distinct section; new public receipts contain only
issued facts and original allocation legs.

## Security and permission acceptance

| Contract | Implemented behavior and evidence |
|---|---|
| C1 first view | Initial unauthenticated GET/HEAD and alternate formats are neutral. Real CSRF-protected POST grants a 15-minute exact token/share/revision/password-bound unlock. Fresh validity is enforced on every delivery, including after rendering. |
| C2 receipt scope | New receipt issuance freezes original allocation/currency/base settlement legs. Later application cannot expand a token. Reversal/source retirement denies access; private later applications remain separate. |
| C3 delegation | Owner receives Phase 9 capabilities. Existing non-Owner grants remain unchanged. New Administrator does not automatically receive them. Protected capability grants and protected role assignment require current same-company Owner authority. |
| C4 catalog images | Approved same-company ProductImage identity, path containment, bytes/hash and thumbnail are frozen per publication. Primary image B cannot replace approved A; missing/deleted/changed approved bytes become a placeholder. |
| C5 Statements | Versioned allowlisted canonical JSON, deterministic SHA-256, encrypted MEDIUMTEXT, atomic share/content/audit, payload-aware retries, strict byte/entry/transport limits, key rotation and fail-closed recovery. No live recomputation on missing/corrupt content. |
| Public marketing media | Managed catalog HTML/JSON/print/PDF/QR destinations deny paused/expired/revoked/disabled-company access. Previously distributed static photographs may remain accessible or cached, as administration explicitly explains. |
| Private Purchasing | All four outputs require authenticated active Company/membership, source view and output permission. Vendor Payment additionally uses the accepted VendorFinancialRead authority intersection. They have no public-sharing subjects. |
| Public prices OFF | Price/currency/tax fields are absent from public DTO, JSON, HTML and PDF when OFF. Priced publication and link management require fresh disclosure authority. No cost/profit/vendor/stock/warehouse/private-note fields are published. |
| Defensive delivery | Token-format validation, IP/password/PDF rate limits, local assets, no-store/noindex/no-referrer, CSP, bounded rendering, private temporary cleanup and safe filenames. No third-party financial previews, fonts, analytics or automatic messages. |

Real MariaDB two-process tests exercise issuance retry/conflicting payload,
publication retry/stale revision and lifecycle races. Rollback tests reject partial
grants when encryption, storage budget or audit fails. Restricted-role and
cross-company tests exercise server redaction and fresh authority rather than
CSS visibility. Genuine legacy grants retain their historical behavior, are
visibly classified, and can be revoked/reissued; no retrospective snapshot is
fabricated.

## Additive migrations and operating limits

1. `2026_10_09_000001_add_issued_content_to_public_shares.php`: nullable issuance
   metadata, encrypted MEDIUMTEXT and Company/request-key uniqueness. Legacy
   token values and economic data are preserved.
2. `2026_10_09_000002_create_managed_product_catalogs.php`: catalogs, selected
   catalog items and immutable publication receipts, with composite same-company
   Product/Unit/catalog constraints. Receipts preserve old request-key results
   after newer publications rather than silently republishing a delayed retry.

Both migrations are forward-only: `down()` retains issuance and approved revisions.
Disposable MariaDB forward/down/forward tests check retained rows, content,
grants and original Product records. Forward reruns verify retained structures and
reject detected partial schemas. Existing applied migrations were not edited.
Code rollback is separate from data downgrade. No persistent reset/reseed was used.

Application preparation ceilings: 500 document lines, 1,000 Statement entries,
256 KiB aggregate text, 4 MiB assets, 2 MiB issuance plaintext and 4 MiB ciphertext.
Issuance checks actual `max_allowed_packet` with a 64 KiB reserve before granting
an active token. Local MariaDB measured 1,048,576 bytes: an 873,104-byte stored
envelope passed a strict-mode roundtrip; a 3,728,244-byte synthetic envelope was
rejected before granting. No database global limit was raised.

Catalogs allow 250 selected Products and 24 public cards/page, with paginated
selection beyond Product 100. Labels allow 100 distinct symbols/500 labels;
unreadably wide symbols fail. Private PDF delivery caps 50 pages/15 MiB/15 measured
seconds, with smaller guest bounds (100 lines/250 entries, 20 pages/4 MiB/10 seconds).
Time checks occur after rendering and are not CPU-preemption or Hostinger SLAs.
See [issuance key recovery](PHASE_9_ISSUANCE_KEY_RECOVERY.md) for key escrow,
APP_PREVIOUS_KEYS, retention, encrypted backup and fail-closed recovery procedures.

## Independent QA evidence

Evidence is retained locally under `.ai/delegations/phase9-implementation/`.
Disposable schema proof/cleanup protects persistent databases. Worker results
are identified separately from lead-executed integrated acceptance.

| Gate | Actual verified result |
|---|---|
| Lead integrated Phase 9 checkpoint | 91 tests / 1,387 assertions passed; `phase9-integrated.log` |
| Lead Sales rendering checkpoint | 18 / 132 passed; `a-final-tests.log` |
| Lead Purchasing checkpoint | 8 / 117 passed; `b-acceptance.log` |
| Lead sharing/regression checkpoint | 29 / 479 passed; `c-acceptance.log` |
| Strict-mode issuance/recovery worker gate, source reviewed | 2 / 44 passed; `c5-storage-recovery-03.log` |
| Lead catalog domain checkpoint | 7 / 56 passed; `d-domain-final.log` |
| Public catalog negative worker gate, also integrated | 8 / 509 passed; `public-catalog-tests.log` |
| Composer worker gate, also integrated | 12 / 55 passed; `d-composer-tests-final.log`; beyond-100 selection separately passed |
| Lead access-settings domain gate | 1 / 16 passed; `catalog-access-tests.log` |
| Lead formatting/error/PDF correction gate | 3 / 38 passed; `f-final-corrections-verified.log` |
| Inherited Phase 4 corrected tests, worker verified | 58 / 238 passed; `phase4-inherited-corrected-final.log` |
| Inherited Phase 1 protected-default fixture, worker verified | 11 / 270 passed; `inherited-domain-corrected.log` |
| Lead inherited generic ledger fixture | 2 / 11 passed; `derived-ledger-corrected.log` |
| Responsive browser checkpoint | 125 matrix + 33 interaction checks; 30 AR/EN desktop/tablet/mobile states, 37 screenshots; zero final JS exceptions or HTTP 500s |
| Actual barcode decoding | 24/24 Arabic, 14/14 English, 49/49 UPC-E; 300-dpi per-label decoding of actual PDFs |
| Final Phase 9 | **103 tests / 1,512 assertions passed**, within final remaining-domain gate |
| Final inherited corrections | **119 / 713 passed** |
| Final Phase 8 / Phase 9 / unit gate | **362 / 13,931 passed** |
| Final previously uncovered profile/schema-safety gate | **22 / 86 passed** |
| Guarded broad coverage | **2,000 / 2,000 unique listed cases passed across segments**; all ten earlier failures superseded by passing correction runs |
| Final nine-document browser matrix, lead executed | **511 checks**, AR/EN × 1440/768/390, 108 screenshots, actual View/Print/Download responses |
| Final settings/catalog/labels/sharing browser gate, AGY executed and lead evidence reviewed | **198 checks**, 41 screenshots; zero JS errors or HTTP 5xx |
| QR decoding | **2 / 2 actual share/catalog QR symbols decoded** |
| Final Pint / Larastan Level 6 / Blade / Vite | **All passed**; Blade recompiled after final menu correction |

Counts above are separate checkpoints, not an additive unique-test total. The
initial broad run was interrupted and is not reported as a passing full suite.
The guarded long run records actual failures and narrow correction reruns rather
than hiding inherited fixture errors. Broad coverage combines 1,649 passed cases
from the interrupted long run with 119 corrected-class, 362 remaining-domain and
22 tail cases. These overlap; the coverage manifest deduplicates them to 2,000.
This is not one uninterrupted or uniform-SHA suite execution. The final changed
domains passed on integrated source. Assertion totals from the interrupted segment
are unavailable, so no fabricated overall assertion total is reported. Composer QA
gates were executed through the owned-schema harness plus Pint/Larastan, rather
than the unguarded default Composer test invocation. Generic ledger fixtures use manual-journal
provenance rather than impersonating a Phase 7 Expense action. New-share tests
exercise real web/session/CSRF confirmation and preserve their prior economic,
identity and redaction assertions.

Actual PDFs for all five Sales and four Purchasing/Vendor types were rendered in
Arabic and English and raster-inspected. Fixtures include ILS/USD/JOD, stored FX,
tax/discount, piece/carton, mixed descriptions, receipt allocation values and
changed masters. Multi-page Sales tables, repeated headings, continuation identity,
long terms and page numbering were inspected. Catalogs have actual AR/EN PDFs.
Barcode inspection caught blank-page pagination that HTML tests missed; the final
simple mPDF label sheet then passed real decoder checks, including UPC-E.

Browser evidence covers actual authenticated actions, live catalog selection/save/
preview/publish/share, barcode preview, financial issuance and deliberate guest
first view. Actual browser testing caught and corrected Alpine native-share
binding errors. No WhatsApp/email/SMS dispatch occurred.

## Delegation and integration record

| Work | Worker evidence | Lead acceptance |
|---|---|---|
| Sales presentation/settings | AGY conversation `6577f6…`, baseline `66259683…` | Reviewed surrounding canonical DTO/permissions, corrected and independently tested before `91a32942…` |
| Purchasing adapters/templates | AGY conversation `525d1447…`, baseline `91a32942…` | Reviewed exact reads/FX/cost and independently tested before `b71ffc57…` |
| Catalog UI | AGY conversation `812fb882-2536-41c9-b9c0-a4b9271b4445`, baseline `91a32942…` | Corrected fresh guards, disclosure acknowledgement, revisions, media and accessibility; independently integrated tests/browser |
| Barcode presentation | AGY relay interrupted without completion receipt; seven allowlisted files collected | Lead finished implementation, pagination/validation/security and actual decoding; no claim of worker completion |
| Catalog access-edit UI | AGY `7a38ca48-8737-48d0-9399-1a51cbbe9a77`, baseline `49c1d5d2744f8e0cb7d722efb273b263f0c3324d` | Collected allowlisted diff; removed duplicate state aliases; verified stale-state/password/expiry/stable-link semantics with source, MariaDB and actual browser |
| Final accessibility/localized UI | AGY `ccefbb2c-4e97-4f50-8267-e24059319ff6`, same baseline | Seven presentation files collected; lead corrected preview refusal, Alpine focus and clipped mobile document menu; final static/browser gates passed |
| Expanded browser and access QA | AGY `94d27b08-33c8-451b-9a95-de702b7573fb`, integrated root source | Worker executed 457 document checks and 198 access checks; lead reviewed JSON/screenshots, found menu clipping, added actual element-bounds checks and independently reran the corrected document matrix |
| Focused QA assistance | Codex workers for security/storage, concurrency, public catalogs and browser fixtures | Source/diffs inspected; lead integrated acceptance executed; worker-only scoped checks labelled above |

Workers have no merge/deployment authority. Canonical DTOs, security, schema,
financial correctness, lifecycle/idempotency and final integration are lead-owned.
The latest Google Worker controller preparation stalled; its partial state was
preserved and the installed safe AGY relay used with an isolated linked worktree.
No permission-bypass flag was used. Worker commits/history rewrites were prohibited.

## Preserved evidence and future work

All nine protected Owner files and the original 708-line roadmap are preserved.
Root and repository roadmap SHA-256 remains
`63c97eeef744a54e9cbbfe991c91f2e057397a755d8d5f1a79317c8f19843f35`.
The preservation manifest records original identities independently of tracked
implementation files. Deliberate untracked Owner documents/screenshots are not
added to the implementation commit; ignored worker/check/fixture/PDF/browser
artifacts are retained locally for review.

[Phase 7–8 production acceptance](PHASE_7_8_PRODUCTION_ACCEPTANCE.md) is carried
forward without rerunning production: six healthy reconciliations, no pending
migrations, verified private backups, 69 reports, 87 Owner permissions and
unchanged economic data. Its qualified limits remain: incomplete English
responsive production coverage, intentionally unperformed restricted-role
production tests, no production backup restoration rehearsal, and three browser
message-channel observations without corresponding Laravel errors. These are
Phase 10 verification/hardening items, not invented production defects.

Phase 10 remains production hardening; Phase 11 authenticated tenant-scoped,
versioned/idempotent canonical command readiness; Phase 12 contextual voice/text
interpretation with explicit authenticated financial confirmation; Phase 13 an
optional PWA/native decision based on use and cost. No AI/provider/microphone,
native app, second backend, public Purchasing/Payroll, checkout, general report
PDF parity or automatic messaging was implemented.

## Final source and release boundary

Final runtime/source integration commit: `6656b4908bc286b86023fbb6b3aa4ccb9577485b`. A subsequent documentation-only
commit supplies this handoff and evidence inventory. The implementation PR records
its exact final reviewed head; neither commit is a merge or deployment.

Remote main was fetched and still equals the exact accepted baseline. The
implementation branch is published with ordinary fast-forward push and no history
rewrite. The primary tracked tree and all six Phase 9 worker tracked trees are
clean after handoff. Worker bytes/diffs were SHA-verified into local ZIP archives
before restoring their tracked copies; untracked worker outputs remain deliberately
retained. Historical Phase 5–8 worktrees and planning branches were not modified.
Owner documents and `.playwright-mcp/` remain deliberate untracked artifacts.

See the [changed-file inventory](PHASE_9_CHANGED_FILES.md) and
[QA metadata and local artifact hashes](PHASE_9_QA_EVIDENCE.json). Main and production
remain unchanged. No
merge, deployment, Hostinger access or production migration/data modification
occurred. Independent architect acceptance is the next checkpoint; Owner retains
separate merge/deployment authority.

## Practical limitations

Physical printer margins were not exercised on hardware. Post-render PDF timing
checks cannot interrupt mPDF CPU use. Local measured budgets must be revalidated
during an independently authorized Hostinger release. No production restore or
restricted-role production test was performed. Previously copied public Product
photos can survive managed catalog revocation by the accepted media policy.
Development PDF/browser artifacts contain canonical disposable fixtures and remain
local; the published manifest includes metadata/hashes, not financial DTOs or tokens.
