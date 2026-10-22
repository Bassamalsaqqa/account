# Phase 10 source deployment record

Status: **ACCEPTED SOURCE DEPLOYED AND BOUNDED VERIFICATION COMPLETE; THREE P1 OPERATIONAL PROOFS NOT VERIFIED; FULL P10-G INDEPENDENT ACCEPTANCE AND CUSTOMER READINESS NOT ACCEPTED.**

Controlling documents: [AGENTS](../AGENTS.md), [Source acceptance matrix](PHASE_10_SOURCE_ACCEPTANCE_MATRIX.md), [Architect handoff](PHASE_10_ARCHITECT_HANDOFF.md), [Hostinger deployment guide](HOSTINGER_DEPLOYMENT_GUIDE.md), [Backup and restore runbook](PHASE_10_BACKUP_RESTORE_RUNBOOK.md), [Operational monitoring runbook](PHASE_10_OPERATIONAL_MONITORING_RUNBOOK.md), and [P1 operational readiness](PHASE_10_P1_OPERATIONAL_READINESS.md).

AGY prepared the public-source record structure and bounded operator tooling; Codex independently reviewed the work, made finite safety corrections, performed the authorized production operation and filled operational evidence locally. A detailed production-metadata brief was rejected by automatic approval review and was not sent to AGY; the record-template delegation used public source and placeholders only.

[PR #21](https://github.com/Bassamalsaqqa/account/pull/21) merged first as `d97a63fb0e3536abcccf229eb42909e848a63f57`, exactly matching the [accepted source tree](https://github.com/Bassamalsaqqa/account/pull/21#pullrequestreview-5480577247). [PR #22](https://github.com/Bassamalsaqqa/account/pull/22) then targeted main with only three P1 documents, received the five-line status correction `dd6c37a7e72543c0e8fc6d08c4649d26e642b753`, and merged as the deployed commit below. Both merges preserve history. The source review is COMMENT, not GitHub APPROVE; Owner authorization separately permitted merge and deployment.

## Exact identities and scope

| Identity | Verified value / Digest | Evidence limit / Notes |
|---|---|---|
| Architect-accepted PR #21 head | `1f1fa5874eaffe749d901ee14a21850e327c9687` | Accepted implementation candidate; full operational release acceptance separate |
| PR #21 merge tree | `cd3bef3d321acfb7093ff4a5d3b8831d28e91fcd` | Exact match to the accepted source tree |
| Deployed source commit | `3a77afef5c79dc947d306845a725241989acd33a` | Verified Git commit SHA deployed to target |
| Deployed source tree | `3c0f0f799f895bdb53d9a6d9f9b116876b893a4d` | Tree digest corresponding to deployed commit |
| Independent verification timestamp | `2026-10-11T03:48:01Z` | Post-deployment runtime observation; not a measured recovery RTO |
| Pre-deployment baseline commit | `8d8428261cd2a10690ab77a5c7271e46b5ff5217` | Verified preceding production commit |
| Pre-deployment baseline tree | `6a81c5795790a978eec82fce12b65cae42ddf20f` | Tree digest of baseline commit |
| Production target host | `account.palsync.net` | Hostinger shared hosting environment |
| Deployment branch / ref | `origin/main pinned; production detached checkout` | Source ref deployed to production |

## Actual check results

| Verification check / Area | Actual result | Evidence boundary / Limits |
|---|---|---|
| Live health route (`/up`) | `PASS: HTTP 200` | Framework HTTP bootstrap response only |
| Document bootstrap | PASS: purchasing and documents provisioners completed; existing roles/grants/sequences unchanged | Synthetic/production documents bootstrap status |
| Frontend asset manifest | PASS: private/public manifests and delivered CSS/JS match the accepted-source build; runtime directories 0755 / files 0644 | Vite build manifest presence and asset integrity |
| Live migration status | 83 tables / 63 applied migrations / 0 pending; deployment used --skip-migrate; no schema changes | Database schema forward-migration status |
| Read-only domain checks | PASS: Accounting, Inventory, Sales, Payables, Money and Phase 7 healthy; 80 full-column business-table fingerprints unchanged, including a frozen maintenance-window baseline | Read-only audits; ordinary cache/cache_locks/session metadata excluded from business fingerprints |
| Exact tests executed | 8/8 synthetic deployment scenarios; 10/10 bounded public HTTP checks; 6/6 reconciliation domains healthy. Bash/HTTP checks do not emit PHPUnit assertion totals | Post-deployment smoke test suite and assertion count |

## Rollback preservation and status

- **Pre-deployment backup findings:** Fresh encrypted on-host SQL and secret-bearing application-state checkpoint; matching source/dependency-lock bundle verified. In-host fresh-ciphertext integrity checks passed without extraction. Restricted directories 0700 / files 0600; separately named on-host key file 0600; temporary credential options file removed. No ciphertext or keys retrieved locally
- **Preservation invariants:** Business-source contents and prior backup archives remain preserved. Ordinary session/cache metadata may change. No `migrate:fresh`, `migrate:refresh`, `db:wipe`, or destructive schema rollbacks permitted on persistent data per [AGENTS](../AGENTS.md).
- **Rollback posture:** Pinned prior-code/build rollback preserves the current database; actual isolated data recovery remains a separate authorization and proof under [Backup and restore runbook](PHASE_10_BACKUP_RESTORE_RUNBOOK.md). Rollback status: PREPARED AND HASH/SYNTAX VERIFIED; NOT EXECUTED. Prior pinned code and both matching compiled builds plus original public entry/configuration files retained. Rollback requires no SQL restore or reverse migration.

## Authorization and operational limits

- **Authorization scope:** Owner explicitly authorized deployment of the accepted source candidate. This authorization constitutes source deployment authorization only.
- **Independent acceptance boundary:** Source deployment does NOT constitute full P10-G acceptance, operational risk acceptance, or customer launch readiness.
- **Operational restrictions:** No application keys or credentials logged; no live database exports or customer data published in tracked records. Shared-hosting constraints strictly maintained (no required application daemons, Docker, Redis or WebSockets introduced).

## Carried vs freshly executed QA

- **Carried source QA:** Comprehensive test suites (unit, feature, economic invariants, golden journeys, static analysis) were carried from accepted [Source acceptance matrix](PHASE_10_SOURCE_ACCEPTANCE_MATRIX.md) and [Architect handoff](PHASE_10_ARCHITECT_HANDOFF.md). Carried presence is not live operational execution.
- **Freshly executed deployment QA:** Production frontend build (58 modules), 8 synthetic deploy success/failure cases, both operator Bash syntax checks and uploaded audit PHP syntax check passed. HTTP login, forgot-password, robots and framework health returned 200; private .env/.git paths returned 403 without secret bytes; forged share/catalog returned 404; CSS/JS returned 200 with matching hashes. Existing configuration/key history, roles/grants, 12 sequences, 69 reports and private/public business-file hashes preserved; no new logged error headers.

## Remaining three P1 operational gates

All three P1 actual operational gates remain independent of source deployment and are unclosed:

| Gate | Status | Tracked minimum next proof |
|---|---|---|
| RSK-12 off-host backup & key recovery | NOT VERIFIED / BLOCKED EXTERNAL AUTHORIZATION | Independent off-host ciphertext survival and independent Laravel key custody ([Backup runbook](PHASE_10_BACKUP_RESTORE_RUNBOOK.md)). |
| RSK-01 isolated disaster recovery | NOT VERIFIED / BLOCKED EXTERNAL AUTHORIZATION | Rehearsal of historical (80/61→83/63) and current recovery in a verified isolated, access-controlled nonpublic target with 6 healthy reconciliations; no specific platform is assumed accepted. |
| RSK-02 account recovery delivery | NOT VERIFIED / BLOCKED EXTERNAL AUTHORIZATION | Real delivered password reset via approved provider to isolated recipient proving single-use token lifecycle ([Account recovery preparation](PHASE_10_P1_ACCOUNT_RECOVERY_PREPARATION.md)). |

Full P10-G customer readiness remains: **NOT ACCEPTED**.

## Local evidence privacy and custody

All raw operational evidence (SSH execution traces, live panel captures, deployment logs, sanitized command outputs) is maintained exclusively in local, ignored storage (`.ai/` or local run directories). No private paths, secret values, recipient addresses, or credentials are published in this tracked document.

## Unresolved operational risks

- The three P1 proofs listed above remain unverified. Carried P2 large-workload CSV refusal at the existing 30-second cap remains; no new production load test or SLA certification. Actual restoration RPO/RTO, hardware/assistive technology and authenticated AR/EN browser/PDF checks were NOT RUN for this deployment. No account impersonation, new production fixtures, real mail, DNS/cron/configuration changes, schema operations or data restore occurred

## Preservation and verification limitations

All 14 protected Owner/private artifact hashes remained unchanged. The four quarantined older synthetic schemas stayed preserved in their stopped scratch server. No broad PHPUnit run or CI was repeated. The worker controller collected a clean-scope review but its automated acceptance/commit step failed on state serialization; Codex published the independently reviewed correction using normal Git without changing controller state. Earlier test errors remain in the carried source records.

The on-host deployment checkpoint is a rollback aid; it does not prove independent off-host survival, restoration, mail delivery or customer readiness. Archived configuration remains secret-bearing ciphertext and must never be activated during future recovery without the accepted isolation sequence. Operational proof still needs the destination, separate secrets custodian, verified isolated targets and approved mail scope specified in the P1 preparation records.
