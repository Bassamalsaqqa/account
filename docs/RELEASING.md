# Backend Release & Orchestration Guide

**Target Application:** Small Trader Accounting (`account.palsync.net`)
**Repository:** `https://github.com/Bassamalsaqqa/account`
**Authoritative Operational SOPs:** [docs/CONTROLLED_RELEASE_CHECKLIST.md](CONTROLLED_RELEASE_CHECKLIST.md) and [docs/HOSTINGER_DEPLOYMENT_GUIDE.md](HOSTINGER_DEPLOYMENT_GUIDE.md)
**Companion Mobile Repository:** [small-trader-android](https://github.com/Bassamalsaqqa/small-trader-android) ([Draft Release Playbook](https://github.com/Bassamalsaqqa/small-trader-android/blob/t3/android-release-playbook/docs/RELEASING.md))

> [!IMPORTANT]
> **Operational Authority & Safety:** This guide governs release coordination and note templates. It does not authorize production execution. Production deployments, schema migrations, and reopening require explicit owner or approved operator approval. Authoritative server layouts, deployment commands, and backup procedures reside in the SOPs linked above.

---

## 1. Versioning & Tagging Policy

- **SemVer Format:** `vMAJOR.MINOR.PATCH[-PRERELEASE]` (e.g., illustrative examples `v0.1.0-pilot.1` or `v0.1.0`; no production release tags currently exist).
- **Independent Cadence:** Backend and Android repositories maintain independent cadences without forced lockstep.
- **Tag Custody:** Annotated git tags are created only after explicit owner or approved operator authorization. Never auto-tag.
- **Companion Mobile Boundary:** The parent Android repository governs its own `applicationId`, release signing keys, monotonic integer `versionCode`, and in-place upgrade policy (preserving local Room database). See the companion [Android draft release playbook](https://github.com/Bassamalsaqqa/small-trader-android/blob/t3/android-release-playbook/docs/RELEASING.md).

---

## 2. Release Integrity & Build Invariants

All releases require exact commit tracking and locked artifact generation:
1. **Source & Locked Builds:** Verify a clean working tree (`git status --short`) and record the source SHA (`git rev-parse HEAD`), install locked dependencies (`composer install --no-dev`, `npm ci`), and run `npm run build`. The repository has no CI automation baseline; operators execute qualification manually.
2. **Build Checksums & Assets:** Record SHA256 for `public/build/manifest.json` and entrypoint assets. Verify source maps are absent (`0 .map files`). Enforce permissions: directories `0755`, files `0644` (never `0777`). Atomic replacement is required; never overlay newly hashed chunks onto old build directories.
3. **Off-Host Backup Custody:** Create coherent SQL transaction and file archives before deployment, transferring to secure off-host custody per [HOSTINGER_DEPLOYMENT_GUIDE.md](HOSTINGER_DEPLOYMENT_GUIDE.md).
4. **Forward-Schema Rollback Policy:** Database migrations are strictly forward-only. No `migrate:rollback` or `migrate:fresh`. Never overwrite production with an old SQL snapshot (destroys active double-entry journals, vouchers, and audit logs). Rollbacks use audited forward migrations or code reverts against a matching rollback commit artifact.
5. **API & Mobile Compatibility:** Dependent backend APIs must be deployed and qualified before distributing dependent APKs. Mobile authentication and sync contracts remain unreleased DRAFT/IN REVIEW; offline sync is strictly POLICY OFF; cross-repo financial protocols remain PENDING with zero activation.

---

## 3. Pre-Release Verification Protocol

Qualify the candidate locally before requesting deployment approval:
- Install development dependencies from the lockfile for Pint, PHPStan and tests; keep the production `--no-dev` artifact separate.
- Run relevant static checks and focused tests through the disposable MariaDB runner in [SETUP_AND_OPERATIONS_GUIDE.md](SETUP_AND_OPERATIONS_GUIDE.md). Never run the default application test command against a persistent or production database.
- Record actual results, limitations and compatibility evidence for the current and previous pilot APK. An untested APK remains unqualified.

After concrete owner approval, use [CONTROLLED_RELEASE_CHECKLIST.md](CONTROLLED_RELEASE_CHECKLIST.md) for maintenance, recovery custody, deployment, six canonical reconciliations, authenticated AR/EN and asset checks, and separately authorized reopening. Do not improvise maintenance or deployment commands from this coordination guide.

---

## 4. Release Note Template

Record in `docs/evidence/` or the release PR. Placeholders indicate required verification evidence; no approvals or gates are pre-granted.

```markdown
# Release Note: Small Trader Accounting [vX.Y.Z-pilot.N / vX.Y.Z]

- **Release Date:** [YYYY-MM-DD]
- **Target Commit SHA:** [40-character SHA] | **Rollback Target SHA:** [40-character SHA]
- **Release PR:** https://github.com/Bassamalsaqqa/account/pull/[PR-NUMBER]

### 1. Build & Artifact Checksums
- **Commit SHA:** [Recorded from git rev-parse HEAD]
- **composer.lock SHA256:** [Recorded checksum] | **package-lock.json SHA256:** [Recorded checksum]
- **Vite Manifest SHA256:** [Recorded checksum]
- **Source Maps:** [NOT RUN / PENDING / Record verified 0 .map files]
- **Permissions:** [NOT RUN / PENDING / Record verified dirs 0755, files 0644]

### 2. Database & Schema State
- **Forward Migrations:** [None / List executed forward migrations]
- **Schema Safety:** [PENDING / Confirm forward-schema invariant preserved, no rollback executed]

### 3. API & Mobile Compatibility
- **API Contract Spec:** [e.g. Contract Rev 1 (Current) / Rev 2 (Draft)]
- **Current and Previous Pilot APKs:** [Each versionName/versionCode, source SHA and artifact SHA256]
- **Compatibility Evidence:** [Each APK: API version, contract revision/manifest, tested endpoints, results or NOT RUN]
- **Offline Sync Status:** Strictly OFF

### 4. Verification & Economic Reconciliations
- **Lint & Static Analysis:** [NOT RUN / PENDING / Record Pint and PHPStan results]
- **Unit & Feature Tests:** [NOT RUN / PENDING / Record test suite results]
- **Economic Reconciliations (6 domains):** [NOT RUN / PENDING / Record 0 discrepancy evidence]
- **Authenticated Sessions (AR/EN) & Logs:** [NOT RUN / PENDING / Record HTTP 200, clean log status]

### 5. Custody & Operational Authorization
- **Off-Host Backup Path:** [Documented path according to SOP]
- **Deployment Script:** [NOT RUN / PENDING / bin/deploy.sh execution under PHP 8.4]
- **Owner / Operator Authorization:** [PENDING / Written approval required prior to deployment and reopening]
- **Reopened via `artisan up`:** [PENDING / Timestamp and operator name]
```
