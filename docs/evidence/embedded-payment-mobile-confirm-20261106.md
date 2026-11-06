# Embedded payment and mobile Confirm correction

Baseline: `ae2458cc01bf8b6280cf79e1a15229ad99a92888`.

The previous release fixed standalone payments but missed the payment section embedded in invoice creation. Signed-in production inspection reproduced blank account, date and FX fields and unconditional conversion controls. Invoice and purchase mobile bars contained no Confirm action.

Changes:
- Embedded invoice receipts default an eligible account by invoice currency/payment method, company-local today, and full amount. Automatic full amounts track total changes until edited. Explicit account/date/amount choices remain editable; currency changes clear allocation amounts in old units.
- Base payment FX is hidden and exactly one; foreign settlement FX remains explicit. Same-currency partial receipts use a single amount. Cross-currency receipts retain explicit allocation amounts and canonical validation. Blank base FX is normalized by the canonical immediate-sale command, including incoming checks; non-one base rates remain rejected by existing domain rules.
- Invoice/purchase mobile bars share a two-row component with draft and permission-gated Confirm actions. Confirm uses the desktop document queue, pending edit flush and duplicate-submit protections. Purchase returns call the existing canonical posting service after saving the draft and retain that draft if posting fails. Sales returns already expose final confirmation; quotations retain their domain's draft action.
- Invoice notes/terms are directly visible. Invoice and quotation foreign-currency switches clear stale FX. All earlier standalone form/date improvements remain present.

Audit correction: the read-only AGY audit incorrectly claimed draft action base-rate guards were missing. Create/update sales and purchase drafts already call shared `SalesDocumentRules::header`, which enforces base rate one. Those guards are unchanged.

Verification (owned disposable MariaDB schemas cleaned):
- Fin1 suite, FormDefaults, HumanWorkflowRefinement and purchase-return UI: 69 tests / 491 assertions passed.
- Focused defaults/permissions/date/purchase-return UI follow-up: 22 tests / 127 assertions passed.
- Final embedded defaults/amount tracking/permission checks: 7 tests / 43 assertions passed.
- 152 JavaScript tests passed, including executing the actual mobile Confirm handler through the real document queue with pending quantity changes and duplicate clicks.
- Full Pint, PHPStan, production Vite build and Blade compilation passed.

No migrations or dependency changes. Production financial posting was tested only on disposable MariaDB; live release checks must stay read-only. Operational release and authenticated mobile/browser evidence are retained separately in the environment's restricted accounting-releases custody.
