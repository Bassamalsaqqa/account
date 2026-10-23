# Accounting — Release B.1 handback

Proprietary — All Rights Reserved. The repository LICENSE and third-party notices govern this release.

## Identity and decision

- Branch: `feature/release-b1-20261023`.
- Comparison baseline: `87e1a4bae08feddd3964f91559684613612768cf` (verified origin/main at start).
- Final implementation/evidence commit: **9e0be29724fc7fddac31afb6ccaf22e551a92e1a**. The subsequent handback-only commit records this immutable source identity; its own exact SHA is available in this file's Git commit metadata and the Owner handback.
- Original checkout remained on `d27adbad93fce8bd7aaf8afa6cdfaa0e33b91bde`; A.2 and proprietary-licensing commits between it and the requested baseline were incorporated by starting the isolated branch at the requested baseline. Original local changes were preserved.
- Independent Codex verdict: **the bounded source changes are accepted for independent review; full Release B.1 acceptance remains conditional**. Merge recommendation: **review the branch now; hold merge and deployment until a clean complete QA run and dependency findings are resolved**. Deployment requires separate authorization; no merge, deployment, Hostinger access, production changes, or paid-provider activation occurred.

## Implemented and preserved

1. **Company Master AI switch:** prominent in existing Settings; existing policy `enabled`, global flag and owner authorization reused. Owner password, explicit enabling consent, company context and policy revisions enforced. Disable preserves credentials, policy contents and history, including malformed legacy setup. Ordinary pages do not mount AssistantLauncher while OFF or absent; global OFF avoids policy lookup. Disabled connection tests/health checks and stale assistant operations fail before dispatch. Detached AI handoffs retain editable invoice/purchase values and subsequent manual draft saves. Both Settings and existing setup toggles rebuild the layout.
2. **Bounded performance:** Settings avoids hydrating unused member graphs/permissions/sections; Omnibox combines fresh company-status/membership validation and removes a redundant hydration check. No authorization, inventory, balance or report caches. No dashboard cache or new navigation infrastructure. Existing server calculations remain authoritative.
3. **Assistant quality:** common English possessives/past-tense questions and Arabic `مبيعاتي` now reach existing bounded reporting/period clarification. Named-entity, negation, currency and unsupported-qualifier boundaries remain. No new orchestration. Twenty-two representative input classifications and synthetic transport/domain tests support this change; they do not certify live DeepSeek answer quality.
4. **Workflow continuity:** Product Save & Add Another with serialized submission; successful-product View/Add New actions; permission-controlled New Invoice/New Purchase actions; Back to Source Document on customer/vendor payments; product barcode-tab handoff to existing label search; null-safe stock-movement links. Existing preview/post confirmation, canonical posting/payment/stock/check actions and transaction URLs preserved.
5. **Settings refinement:** prominent AI card, existing searchable grouping retained, overview counts replace unused relationship hydration. Account/Profile, personal 2FA, Audit, company export, AR/EN and section authorization retained. No multiple-component tab rebuild.

Attribute-only accessible names were added to three existing party search inputs. Stale setup revision failures now refresh the revision for an explicit retry, and first-use setup opens the authorized assistant after the layout rebuild.

Changed surfaces: AI governance/setup/launcher/unsaved-form concern, SettingsIndex, GlobalSearch component/service, product form/labels, invoice/purchase detail actions, payment forms/details, stock-movement presentation, AR/EN strings and focused tests. No migration, financial action/service, dependency declaration or lockfile changed. LICENSE, README copyright notices, third-party licenses and proprietary metadata are unchanged.

AGY implemented three bounded packages (AI control, workflows, classifier/audit) in isolated worktrees. Codex collected and inspected actual diffs, corrected revision handling, manual-form preservation, missing imports, test fixtures, setup layout refresh and unsupported audit claims, and independently ran verification. Worker narratives were not acceptance. AGY also reviewed final source findings, baseline-test drift and the concurrency isolation correction; [final isolation review](evidence/release-b1/agy-isolation-review.md).

## Measured results

Full methodology, all ten workflows, SQL plans and raw samples: [performance evidence](evidence/release-b1/performance.md).

| Representative request | Baseline ON → final ON queries | Baseline OFF → final OFF queries |
|---|---:|---:|
| Dashboard | 422 → 421 | 343 → 329 |
| Invoice create / edit | 93 → 92 / 157 → 156 | 44 → 30 / 78 → 60 |
| Purchase create / edit | 127 → 126 / 166 → 165 | 74 → 60 / 87 → 73 |
| Settings | 132 → 129 | 53 → 40 |
| Omnibox Livewire | 43 → 39 | measured separately with OFF |

Final OFF has zero launcher mounts on all sampled workflows. Baseline OFF still mounted the component. Product-search and invoice/purchase-selection Livewire query counts were unchanged (18/31/64). Settings identity queries fell 26→23; its small payload grew 1629/10760→1652/10844 request/response bytes. Omnibox payload stayed 540/30511 bytes. Local timing samples generally improved but are noisy: no production latency guarantee or universal speed claim. The table and raw measurements explicitly document fixture/setup changes, contention and sample size.

## Verification and evidence

Final formatting and PHPStan checks passed. The ordered publication concurrency → Release B.1 quality → unified assistant run passed **152 tests / 977 assertions**, including strict no-economic-write checks after concurrency fixtures. The corrected AI/setup/workflow package passed **50 tests / 355 assertions**; setup compatibility **9 / 241**; Phase 1 **87 / 681**; purchasing reversal dates **15 / 87**; money boundary **2 / 11**; bilingual form accessibility **4 / 1240**; catalogue **13 / 128**; export **28 / 488**; barcode certification **9 / 300**. Runs overlap; counts are not unique coverage. [Actual results and baseline-test corrections](evidence/release-b1/verification.md) include the completed broad batches (**1564 / 26655**, one error and 23 failures before the documented corrections; **796 / 4113**, two errors and one failure before corrections) and interrupted full composer QA output. **A clean end-to-end composer QA PASS has not been established.** All observed broad-batch failures were investigated; focused corrected reruns passed. One capacity case passed independently after a long-process failure and remains a stability limitation.

- JavaScript: 127 tests passed, zero failed; [output](evidence/release-b1/javascript-final.txt).
- Six reconciliations: Accounting, Inventory, Sales, Money, Payables and Phase 7 healthy, zero discrepancies; [before](evidence/release-b1/reconcile-before.json) / [after](evidence/release-b1/reconcile-after.json). Two posting batches/six lines, debit=credit `550.000000`; economic table fingerprints unchanged and reconciliation read-only. This fixture has no posted payment/check/expense/payroll events; lifecycle regression suites provide the additional coverage reported above.
- Browser: company ON/OFF/absent-policy switching at 390/1440, re-enable/removal, product continuation, Omnibox Ctrl+K/Escape/focus, preserved unsaved invoice notes across dialog and observed Back/Forward, mobile drawer Escape/focus restoration, AR/EN PDF view/download and HTML print returned expected types/statuses; [observations](evidence/release-b1/browser-lifecycles.json).
- Responsive evidence: [30 screenshots](images/release-b1) (24 in the main matrix and six supplemental views) cover Settings, Product, Invoice and Purchase in AR RTL / EN LTR at 390, 768 and 1440px. All 200, no document overflow, launcher absent while OFF; [checks](evidence/release-b1/responsive-checks.json). Visual inspection corrected synthetic fixture names; UI defect scan returned `[]`.
- Production build: Vite 7.3.6, 310 modules; CSS 114.52KB, application JS 91.24KB, lazy scanner chunk 454.37KB; [build output](evidence/release-b1/build-final.txt). Assets built successfully from the existing lock and remain generated/ignored according to repository policy.
- Composer validate/audit: valid manifest, no advisory or abandoned-package findings. npm audit: two existing high findings, `follow-redirects` 1.16.0 and Vite ≤7.3.6; [raw audit](evidence/release-b1/npm-audit.json). GitHub reports seven open advisories across these same two packages (three high, three moderate, one low); these are advisory counts, while npm groups the two affected packages. [Read-only GitHub alert evidence](evidence/release-b1/github-dependency-alerts.json) records identifiers, affected ranges and fixed versions (Vite 7.3.7 / follow-redirects 1.16.1). No dependencies were added. Automated approval review rejected the attempted lockfile patch as outside the bounded no-dependency-change delegation scope; it was not bypassed. Resolve these findings in an approved dependency correction before deployment.

## Corrections to discovery and separate backlog

[Source-backed matrix](evidence/release-b1/feature-audit.md) evaluates all eight proposals and their accounting/security implications. Purchase posting, PDF view/print/download and contextual quick-create dialogs already exist. General-ledger opening balances already exist; identified-party subledger migration remains a separate problem. Settings search/cards/security/audit/export already exist. Duplicate policy state, another orchestrator, blanket button replacement, indiscriminate wire:navigate and unproven dashboard caching were rejected.

Priorities for separate approval: **P1** identified customer/vendor opening balances and optional atomic cash-invoice/receipt flow; **P2** read-only GL/Chart explorer and safe document duplication; **P3** purchase-to-label preselection and optional compact index dialogs/embedded PDF preview. These are proposals, not implemented or approved financial changes.

## Limits and custody

- Live provider answers, transaction extraction quality with a live model, voice recognition, provider/end-to-end latency, actual tokens and request cost remain unverified. No paid provider was enabled. Classifier/transport tests and safe failure behavior cannot substitute for that evaluation.
- Physical mobile/tablet devices, camera/USB scanners, thermal/barcode printers, production-sized datasets, Hostinger timings and host-specific filesystem/runtime checks remain unverified. Existing browser lifecycle checks do not promise durable draft recovery after every navigation or authentication transition.
- Verification-only CLI OPcache and local decoder tools were used; the application exporter cap is unchanged. The owned MariaDB server used relaxed flush durability only for regression throughput after performance measurements; crash/power-loss durability was not tested. Windows host symlink checks remain open. Reproduce the complete normal QA gate on the final branch before acceptance.
- The isolated release tracked tree is clean after commit/push. The original checkout's two Settings language edits and pre-existing untracked documents/screenshots were preserved, not incorporated. Deliberate local artifacts: ignored `.release-b1-local/` synthetic fixture/harness/logs and controller-managed AGY worktrees. None contains production data or live-provider credentials; none is part of the release artifact. Owned local browser and database services were stopped after verification; unrelated local services were not touched.
