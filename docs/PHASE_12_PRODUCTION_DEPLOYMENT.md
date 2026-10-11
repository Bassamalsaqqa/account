# Phase 12 release incident and rollback — 2026-10-11

**Current production code:** Phase 11 `c2e39a32271b14de99e6ffda55ec5b0ea400cb7e`, restored and online. **Current database:** 89 tables / 67 migrations, retaining the additive Phase 12 schema. Phase 12 redeployment and Unified V1 development are paused pending the rendering correction's independent exact-head acceptance.

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
