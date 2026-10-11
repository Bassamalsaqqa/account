# Phase 12 final-spec implementation ledger

Governing protected Owner specification: ACCOUNTING_PHASE12_FINAL_SPEC_ARCHITECT_REVIEWED_2026-10-11.md.
SHA-256: B080FC50222DC3F8E0AE6E8A3701BD4151E0CC020AD9FEEF14DE205521A5FB3F.
The original remains private and unchanged.

Starting PR #25 head: fb37565cd6a85d9baa2d7fdebc59d359c750f032.
Accepted main/deployed baseline: c2e39a32271b14de99e6ffda55ec5b0ea400cb7e.
This contract supersedes the earlier Sales-only gateway design. Earlier tests are carried evidence, not proof of the expanded contract.

| Package | Source work | State | Acceptance |
| --- | --- | --- | --- |
| P12-M1 | Curated capabilities; direct DeepSeek/Qwen/OpenAI text and Qwen/Groq/OpenAI STT; protocol mocks | FOUNDATION IMPLEMENTED; orchestration integration pending | PASS — 7 mock tests / 77 assertions |
| P12-M2 | Company policy, protected delegation, encrypted write-only company/personal BYOK, consent and budgets | GOVERNANCE FOUNDATION IMPLEMENTED; Settings/budget integration pending | PASS — 6 governance tests / 29 assertions; migration round trip / 26 assertions |
| P12-M3 | Deterministic fast path, discriminated intents, bounded clarification, fallback and patch orchestration | PARSER/SCHEMA IMPLEMENTED; orchestrator pending | PASS — 7 parser/schema tests / 49 assertions |
| P12-M4 | Unsaved Sales/Purchase handoff, current revision corrections, canonical Purchase draft idempotency | NOT RUN | NOT RUN |
| P12-M5 | Seamless/Guided foreground speech lifecycle and genuine installed local recognition | NOT RUN | NOT RUN |
| P12-M6 | Focused MariaDB/security/economic/concurrency/UI evidence and architect handoff | NOT RUN | NOT RUN |

Codex implements and independently verifies; AGY is unavailable. No paid provider calls, real keys, production operations, merge, deployment or GitHub CI are authorized. Provider activation remains off. Platform billing/subscriptions and AI Purchase posting remain deferred.

## Foundation checkpoint evidence

Focused unit tests: 14 tests / 126 assertions, PASS. Guarded MariaDB governance: 6 tests / 29 assertions, PASS. Forward extension round trip: 1 test / 26 assertions, PASS; 89 tables / 66 migrations forward to 85 / 65 and back, unchanged business fingerprint and all six reconciliations. The existing 120000 migration remains untouched.

An initial mock-isolation/multipart-assertion failure and empty-policy validation failure were corrected and rerun. An older isolated MariaDB instance stalled concurrent DDL; that run is INCOMPLETE, not PASS. A fresh owned loopback instance on port 33312 passed sequential runs; private ownership/cleanup records are retained outside Git. Source tests neither verify providers nor establish final UI acceptance. No entire application suite was repeated.
