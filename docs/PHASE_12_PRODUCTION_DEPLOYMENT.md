# Phase 12 release, incident and controlled recovery — 2026-10-11

**Current verified production code:** `db17512798af5ba6d9d5e7e90fb326ca44718f34`, tree `a45fee80490af0c44ac7a6ed89ffd6dc87c84ba7`; corrected Phase 12 is online. **Database:** 89 tables / 67 migrations, unchanged by the recovery release. Recovery verification completed on 2026-10-11 at 20:40:37 UTC. AI/provider/speech/command API functionality remains disabled with zero global cost ceilings. The following incident sections preserve the original release and rollback history; the final section records the subsequently authorized recovery.

## Accepted source and initial release

[PR #25](https://github.com/Bassamalsaqqa/account/pull/25) was independently accepted at `a07b86bc2103babb57bec27f922eadc202c154c3` (review 5484701647) and merged unchanged on 2026-10-11 at 19:55:26 UTC. Merge/main: `183be2689d980c293f595ffeaa9d2e0178507b88`; tree `45c14e958ba0bbf546cde4a5f4bc98c9b00ff93d`, identical to accepted source.

Encrypted on-host SQL and secret-bearing application-state checkpoints, code bundles and checksummed asset rollback buffers were prepared before mutation. Separate encryption-key files remain restricted on-host. No backups, SQL, production credentials or keys were downloaded. These checkpoints do not establish independent off-host recovery or a restore rehearsal.

An initial attempt stopped after source/assets checkout at a maintenance-response guard, before environment changes or migrations. Code/assets-only rollback restored the prior runtime. The exact initial transient/bootstrap cause was not conclusively isolated. A retry used Laravel's pre-rendered maintenance page, preserving accepted source during the code/autoloader transition.

The retry applied all three accepted forward migrations, advancing 84 tables / 64 migrations to 89 / 67. All five new AI tables were empty before reopening. Row counts and SHA-256 fingerprints matched for 78 existing nonvolatile, non-permission tables. Existing permission rows, non-owner grants, role identities, document sequences and business-file hashes matched; exactly six accepted static AI permissions were added only to Owner. Accounting, Inventory, Sales, Payables, Money and Phase 7 reconciliations passed before and after migrations.

Twenty bounded public HTTP probes passed, including CSRF-protected guest AR/EN locale switching, RTL/LTR login rendering, recovery-request page GETs, health, protected-route redirects, disabled command/audio endpoints, denied private files and released assets. Those guest probes did **not** test authenticated application layouts. They were insufficient to establish authenticated release readiness.

## Authenticated rendering incident

Owner UAT then reported HTTP 500. Sanitized production logs confirmed `Undefined variable $available` in the assistant rendering path. Source analysis found Livewire's automatic `ai` namespace conversion produces `App\Livewire\Ai\AssistantLauncher`; the actual namespace/directory is `App\Livewire\AI\AssistantLauncher`. Linux's case-sensitive autoloading can miss the class and resolve the view as a file component without the class's render data. Previous tests mounted the class directly; Windows file lookup masked the production alias path.

The focused pre-fix alias assertion reproduced the exact `AI` versus `Ai` mismatch. Its initial two-test run had two failures: that mismatch and an unrelated missing Vite manifest in the new isolated test worktree. The fixture now bypasses Vite only for PHP rendering tests; production asset build/hash evidence remains separate. The correction explicitly registers `ai.assistant-launcher` to its actual class in `Phase12ServiceProvider::boot()`; it does not change permissions, provider activation, consent, accounting logic, schema or frontend assets.

## Incident rollback and preserved data

Production code/assets were restored to Phase 11 without SQL restoration or reverse migrations. Stale Phase 12 provider/autoload caches initially blocked the prior code from booting. Clearing only generated bootstrap cache files, rebuilding Composer autoload without scripts, rediscovering packages and warming prior-code caches completed rollback; HTTP 200 and clean tracked source were verified. The private rollback procedure now includes this prerequisite.

A separate read-only after-rollback audit matched **all nonvolatile table fingerprints**, including migration and permission state, against the successful release checkpoint. The 89-table/67-migration additive schema and Owner's inert static AI grants remain. All six reconciliations remained healthy. No synthetic production business fixtures or posted-history changes occurred.

Protected configuration is preserved except explicitly disabled flags and zero cost ceilings: `AI_ASSISTANT_ENABLED=false`, `AI_PROVIDER_APPROVED=false`, `AI_ASSISTANT_PROVIDER=none`, `AI_SPEECH_ENABLED=false`, `COMMAND_API_ENABLED=false`; all five global cost ceilings are zero. Release probes verified disabled dispatch/governance and API rejection before any database query or outbound call. No real mail, paid provider call, key rotation, GitHub CI or AI/API activation occurred.

## Correction acceptance and operational limits

Focused post-fix verification passed: **20 tests / 161 assertions**, zero failures/errors/skips, using a newly owned disposable MariaDB schema; cleanup confirmed. Changed PHP files passed Pint --test and git diff --check passed. No broad suite or asset rebuild was repeated for this PHP-only correction. Regression scope includes exact alias resolution, class-based rendering with AI disabled/enabled locally, authenticated AR/EN dashboard and both create-form pages, and the existing scoped-consent suite. Production authenticated UAT must be repeated after an independently accepted redeployment; guest smoke checks cannot substitute for it.

CLI PHP 8.4.26, locked dependencies, a 61-module frontend build and private/public asset hashes were verified during release. Static directory 0755/file 0644 permissions and public/private guards were preserved. Both release attempts and incident rollback logs remain restricted in private custody; no zero-downtime claim is made.

Phase 10 off-host backup/key recovery, isolated restore lanes, real recovery email and customer-readiness/capacity gates remain open. Real provider DNS/TLS/egress, billing/privacy/retention, device microphones and dialect accuracy remain unverified activation gates. Unified V1 has an isolated unpublished worktree; its partial report-plan/authorization work is preserved and is not included in this correction or production.

## Controlled recovery release — verified 2026-10-11

[PR #26](https://github.com/Bassamalsaqqa/account/pull/26) received independent exact-head source acceptance at `e799dc3cf4ef25b12584745ba9786e00d8ffeec5`. Codex confirmed the unchanged head, then merged with preserved history at 20:32:13 UTC. Merge/deployed SHA: `db17512798af5ba6d9d5e7e90fb326ca44718f34`; the merged tree exactly matches accepted source. AGY was unavailable; Codex prepared, reviewed and operated this release directly.

The healthy Phase 11 runtime remained online until rollback tooling, accepted assets, session checks and maintenance guards were ready. The release used a deployment lock, pre-rendered HTTP 503 maintenance response and request drain. Restricted on-host encrypted SQL and secret-bearing state checkpoints, a verified Git bundle and checksummed prior private/public asset buffers were captured at 20:38:58–20:38:59 UTC. Private checkpoint identifier: `phase12-20261011-203845-db17512`. No archived production backup or key was retrieved, exported or published. Backup encryption custody remains on the same host and is **not independent off-host recovery**.

Only accepted source and accepted prebuilt assets were installed. Composer autoload was rebuilt under PHP 8.4.26 before application bootstrap; generated provider/package caches were rediscovered, and configuration/routes/views/events were rebuilt. The explicit `ai.assistant-launcher` alias resolved to `App\Livewire\AI\AssistantLauncher` on Linux. Existing Phase 12 migrations and provisioning were already applied: **no migration, schema reversal, SQL restore or bootstrap record mutation was performed**.

### Authenticated checks before reopening

Actual HTTPS requests originated on Hostinger against the live Linux/web runtime while public traffic remained in maintenance. The checker reused the Owner's existing, unexpired, permitted session, confirmed in the signed-in browser; it did not create a user/session, bypass login/2FA, change a password, or fabricate a business record. A temporary maintenance bypass was generated and used only on-host, without a secret URL, credential export or browser installation. Only the existing volatile session's locale was changed for checks and restored afterward; user/company records were unchanged.

All **24 authenticated requests returned HTTP 200**, with the expected AR/RTL or EN/LTR document, authenticated layout, rendered Livewire snapshots and absent disabled-assistant controls:

| Actual route | Arabic | English |
| --- | --- | --- |
| `/dashboard` | 200 | 200 |
| `/invoices/create` | 200 | 200 |
| `/purchases/create` | 200 | 200 |
| `/invoices` | 200 | 200 |
| `/purchases` | 200 | 200 |
| `/products` | 200 | 200 |
| `/customers` | 200 | 200 |
| `/vendors` | 200 | 200 |
| `/inventory` | 200 | 200 |
| `/reports` | 200 | 200 |
| `/settings` | 200 | 200 |
| `/money` | 200 | 200 |

Fresh application errors during these checks: **0**. No form was saved or posted. This verifies authenticated rendering and disabled-state compatibility, not new financial business-event execution or billable AI behavior.

### Preservation, disabled boundaries and online verification

- All **86 nonvolatile tables** matched exact row-count/SHA-256 fingerprints before release, after source/cache preparation, after authenticated HTTP checks and after reopening. Only `cache`, `cache_locks` and `sessions` were excluded as volatile. Users, permissions, role grants, document sequences, migrations and all financial/stock history matched exactly.
- All six reconciliations passed at each audit: Accounting, Inventory, Sales, Payables, Money and Phase 7. Database identity remained 89 tables / 67 migrations; pending migrations were empty under the released source.
- Production `.env` and business private/public file hashes matched exactly. Accepted build archive and all four released build entries passed checksums in private/public roots; static directories/files retained 0755/0644 permissions. Existing uploads and assets were preserved by non-destructive copying.
- Cached `AI_ASSISTANT_ENABLED=false`, `AI_PROVIDER_APPROVED=false`, `AI_ASSISTANT_PROVIDER=none`, `AI_SPEECH_ENABLED=false`, `COMMAND_API_ENABLED=false` and all five zero cost ceilings were verified. Disabled governance/transport/API probes performed **0 database queries and 0 outbound requests**; API returned 404. No external provider or real-mail request was made.
- After reopening, **20 bounded public HTTP checks** passed: AR/EN guest locale/login, recovery-page GET, health, protected redirects, disabled API/audio, private-file refusal and three static assets. The existing signed-in Edge session separately rendered dashboard, Sales create, Purchase create and Settings without a Server Error; its original Arabic preference and Settings location were restored.
- Live SHA/tree and clean tracked source were verified. Maintenance files and temporary SQL credentials were removed; the temporary bypass was invalidated on reopening. There were **0 fresh application errors** through the 20:40:37 UTC online audit. No rollback was needed for this recovery attempt.

Rollback remains prepared to restore `c2e39a32271b14de99e6ffda55ec5b0ea400cb7e` and its prior asset manifest, rebuilding generated caches/autoload before bootstrap. It retains all 89 tables/67 applied migrations and compares data/files before reopening. Restricted checkpoints/evidence remain on-host (directories 0700, files 0600); private operator scripts/logs are ignored locally and are not part of Git. Broad PHPUnit, frontend rebuilding and GitHub CI were not repeated for this unchanged accepted release.

**Outcome:** controlled corrected Phase 12 release verification passed. Unified Assistant V1 may resume in its separate development worktree/PR; it is not deployed or activated. Deferred Phase 10 operational gates and all real-provider/privacy/cost/device activation gates above remain open. This release is not a declaration of complete customer readiness.
