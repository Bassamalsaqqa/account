# Role A review handoff

Base: PR87 912aa71f557b314837e70138df22615902fc9d7b.
Prepared checkout began at f8a0cf7012c4875bec7320d42e0f8dde58593b52; ordinary checkout created t3/purchase-draft-safety directly at authorized foundation.

Scope: Owner-only draft deletion follows the mature invoice-delete authority plus purchasing.cost.view. Fresh tenant/actor authorization, company-first transaction and purchase row lock precede lifecycle/dependency checks. Lines/lots, header and audit commit atomically. Linked retry/correction receipts, AI requests, landed plans, payments and returns block deletion; no receipt/provenance is removed. Already-deleted retry returns not found and creates no second audit. No migration or canonical posting/math change.

Incident: supplied image inspected. It shows a generic Livewire 500 overlay above an Arabic purchase form. Triggering click/request, timestamp, company identifier and exception trace remain unknown. No client/private lookup or production access. Exact incident NOT REPRODUCED; permissions remain a hypothesis. Existing authorizeForm and quick-create paths throw authorization failures; no catch-all success or broad grants added. PurchaseForm.php unchanged because no source cause was proven.

Browser: T3 preview_status returned available:false, no tabs. Visual/RTL geometry, browser workflow and latency NOT VERIFIED. No browser sandbox bypass, hooks or live fallback.

Merge gates: independent exact-head review required. No real two-connection delete/post race qualification yet; source shares company/purchase lock order with posting. PR93 currency commit 1b8a696feda350bbc2df86a26d05b69fd615ba57 is not merged/reimplemented. PR95 auth/receipt/mobile files and tests untouched. Worker B must preserve the added purchase-row-actions delete control; no create form/picker layout changes. Worker C has no owned-file overlap. No e111f83/old receipt features used.

No merge, deployment, Hostinger access, production transactions or financial activation. Dependencies installed from tracked lockfiles; ignored vendor/node_modules/build artifacts are reproducible. No historical/private artifacts removed.
