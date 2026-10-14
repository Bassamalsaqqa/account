# Separate financial-integrity and public-documentation findings

## Confirmed stock/GL integrity gap — OPEN

Tracked separately in [issue #45](https://github.com/Bassamalsaqqa/account/issues/45), owned by the lead engineer with independent architect acceptance required. This predates the PR #44 UI changes and remains a financial-integrity release concern; it is not dismissed as a hypothetical enhancement.

At PR #44 baseline `b5d8e1f532f206a6f8d6a6573c667c1bdaf056c4`, a focused local diagnostic used a newly owned disposable MariaDB schema and synthetic data. One diagnostic test, nine assertions, reproduced standalone opening-stock and sale effects without corresponding GL/source documents, while inventory reconciliation returned healthy. The schema was cleaned by the guarded harness. These assertions confirmed a defect; they do **not** mean the financial invariant passed.

The public movement primitive lacks the action-owned company/source/actor/connection/transaction capability already used for Purchasing. The inventory GL comparison can skip unmatched economic stock movements when no relevant GL lines or recognized opening/adjustment/disposal source types exist. Current authorized call sites and a surrounding transaction are insufficient proof of canonical authority.

The separate correction must bind economic movement effects to canonical actions and detect missing source/GL/control-account states; prove rollback, retries, fresh authorization and concurrency; and preserve exact calculations and immutable history. Do not rebuild the accounting engine or silently alter historical transactions. No public HTTP exploit or actual production imbalance has been established. No production inspection or repair occurred in this work.

Until that correction is reviewed, successful current reconciliations must not be described as complete protection against orphan stock/GL states. Issue #45 defines the bounded acceptance cases and the remaining release gate.

## Older PR #32 public-documentation privacy finding — OPEN, separate

The independent review of [PR #32](https://github.com/Bassamalsaqqa/account/pull/32), head `86e1e2d14e5c493c6cfb06ed2edffe0aa5faa191`, identified unnecessary public disclosure in `docs/AI_NATIVE_RELEASE_AND_FINAL_SMOKE_2026-10-12.md`. It combines an identifiable live company with precise financial test-record identifiers and amounts, master-data identifiers and operational evidence. Owner authorization to test in live books did not itself authorize publication of those details. This assessment does not reproduce them or assert an API-key leak.

Appropriate remediation is a normal, additive correction **on that separate documentation PR**: replace the company identity with “Owner-authorized production test company”; describe only the general test and debit/credit behavior; remove precise record/master-data identifiers, private fingerprints and unnecessary operational timestamps. Keep exact evidence in restricted, authorized custody. Retain truthful outcomes, including unknown-cost reservations, without treating reservations as proven provider bills.

An additive edit sanitizes the current document but cannot erase previously public commits, PR diffs or copies. The Owner must decide whether public historical retention is acceptable or request GitHub-assisted sensitive-data removal and an explicitly authorized history-remediation procedure. This task authorizes neither rewriting published history nor making that disclosure decision on the Owner's behalf. No PR #32 source/history change was made here.

No production records should be deleted, reversed, recreated or modified to address a documentation disclosure. No production data or credentials were accessed, and no financial history was changed by this assessment.

## Operational limitations retained

Independent off-host backup/key recovery, both isolated restore lanes and approved real account-recovery delivery remain separate outstanding operational proofs. PR #44's deployment safeguards do not establish them. No merge, deployment or paid model request is part of these corrections.
