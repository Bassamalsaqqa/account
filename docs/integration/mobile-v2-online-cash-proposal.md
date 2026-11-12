# Default-disabled V2 online cash preparation

Status: proposal only; financial authority and canonical execution remain OPEN.
Foundation: PR94 `17444ea77d5bc126da24ce3a6d5c248648fde37f`.
No financial adapter, routes, migrations, grants, flags, database operations or new staging
are implemented by this document. Production financial execution remains Deny and V2 defaults OFF.

## Verified prerequisite and source topology

P0 test-safety is qualified at the foundation: supervisor refusal 34 tests/180 assertions;
independent refusal 34/180 followed by fresh owned MariaDB rollback 6/55, identity 30/92,
and concurrency 4/39, with owned schemas removed. This qualifies test lifecycle safety,
not native financial authority or complete mobile posting.

Dependencies are separate branches, not one already integrated PR94 tree:

| Branch | Exact source | Relationship |
|---|---|---|
| PR94 | `17444ea77d5bc126da24ce3a6d5c248648fde37f` | P0 follow-up to `7bb6d779e52ca2f284045d66f8f3f6aee7b4e6fb` |
| PR92 | `7b8531bfb932c73c26efee7f1ace6c5ae61fceaf` | PR94 foundation |
| PR88 | `9f73ca8952da6bbfc0eabadbb01e51892ace8bc6` | PR92 foundation; native V1 identity/read |
| PR91 | `f3836ef7aa8a2437e95fedaec3166399a44158b2` | Separate pure-helper branch on PR89 |
| PR89 | `cb97c9a7b864b3a40e5dc4fe03c9f168fd5c59e8` | Revision2 mirror on PR86 |
| PR93 | `1b8a696feda350bbc2df86a26d05b69fd615ba57` | Independent currency writer branch |
| PR87 | `912aa71f557b314837e70138df22615902fc9d7b` | Independent web workflow branch |

Preserved source ref `t3/preserved-v1-read-pilot-e111f83` points to
`e111f83bc740e9590142869b30428eaa220eab6f`, with composition foundation
`f5d16b356ed9c4d895e4fd07dcb469d756936d9b`. It contains the older PR94 head;
P0 is not its ancestor. Future integration must compose P0 explicitly. Never rewrite
historical pilot evidence. PR91 is not an ancestor of PR88. PR91 helpers and the PR93
resolver are not present merely because a proposal is based on PR94.
Future composition must preserve both AppServiceProvider bindings and reconcile PR87/93
InvoiceForm, SalesDocumentRules and SalesProductSelection overlaps. The read pilot is retired;
no new cold/warm performance claim follows. PR88 catalog hashing remains O(n).

## First bounded correction: identity locks and scope errors

Source anchors: `app/Http/Controllers/Api/Mobile/V1/AuthController.php`,
`DeviceController.php`, `app/Support/Mobile/Receipt/MobileEventReceiptManager.php`,
`app/Application/Platform/TenantWriteAccess.php`, Fortify password/recovery actions,
and `app/Actions/Company/UpdateCompanyMemberRoleAction.php`.

Login locks User, active Companies, then Device and issues Token. Receipt processing
locks Company, Device, User, Token and membership. Removing only the login Company
lock leaves User -> Device versus Device -> User inversion. No global hierarchy is
qualified yet. Before editing, enumerate exact login/MFA/recovery/revoke/password,
membership and role-definition lock paths; choose a compatible order and justify fresh
credential/membership semantics. Multi-company login must not assume it already knows a
single selected tenant. Do not remove a security lock merely to make a diagram acyclic.
The next correction must include real two-process barrier tests for login/intake,
revocation/intake and membership changes; sequential action calls are insufficient.

Unify missing/inactive/nonmember tenant failures in both record and lookup, before any
receipt digest comparison. Preserve reviewed error envelopes/codes; do not invent a
new public error contract without compatibility review. Compare status, canonical message
and allowed details for valid/invalid tokens across missing and existing foreign tenants.
This proves bounded response equivalence, not a universal timing-oracle guarantee.

## Authority and canonical event boundary: prerequisites, not an adapter shortcut

| Question | Current source / proposal | Gate |
|---|---|---|
| Can a bearer execute financial work? | Identity only; production Deny adapter | CLOSED to execution |
| Which capabilities exist? | CompanyRoleService lists sales.invoice.create/post and money.receipt.create/allocate; actions apply their own checks | Source facts, not a mobile grant policy |
| Who may delegate native financial authority? | Not established by this proposal | OPEN owner/security design |
| Are grants fresh at commit? | Role assignment locks alone do not serialize role definitions or cross-process registrar caches | OPEN transactional design |
| Can separate public actions commit only one leg? | Draft/post/payment actions remain separately callable; one outer transaction alone does not close alternate entrypoints | OPEN canonical boundary design |
| May a browser proof be fabricated? | No; CommandSessionGuard remains authoritative for browser commands | Prohibited |

Source anchors: `app/Application/Commands/ImmediateSaleCommands.php`,
`SalesInvoiceCommands.php`, `app/Services/Security/CommandSessionGuard.php`,
`app/Actions/Sales/CreateSalesInvoiceDraftAction.php`, `PostSalesInvoiceAction.php`,
`PostCustomerPaymentAction.php`, and `app/Services/Sales/DocumentSequenceService.php`.
ImmediateSaleCommands provides a mature browser composition reference, not an approved
native entrypoint. Open transactions, permissions and call-site scans are defense in depth.
A future domain boundary must own all required economic legs and prevent alternative public
runtime entrypoints from committing a partial event. Its authority type, construction and
revocation protocol must be reviewed before binding a native adapter, even in a qualification
implementation. Until then stop at the existing deny boundary.

After those decisions, the proposed sequence is: authenticate and lock fresh identity/scope
and approved authority; claim/replay company-event identity; validate a typed normative intent;
resolve owned customer/warehouse/products/units/tax/cash-account and currency under canonical
locks; perform draft/post/inventory/ledger/payment allocations through the reviewed event
boundary; derive terminal IDs from canonical rows; insert terminal receipt; root COMMIT;
only then ACK. Numbering and allocation remain inside their canonical actions. Do not add a
second external number increment or direct CustomerPaymentAllocation insert. Original bytes,
identity and all economic legs must roll back together. No committed processing placeholder.

## Contract, decimals and terminal result

Explicitly compose PR91 and PR93 before reusing them. PR91 OnlineSaleIntentProfile constructs
actor_id, company_id and currency_minor_units from authoritative server context; its raw
request and canonical profile are different shapes. Do not require clients to send server
context or mutate the frozen request schema. Bind required client event/device/customer/
warehouse/line/payment fields at their specified layer and compare them with authenticated
scope. Missing required bindings must fail; presence-only comparisons are insufficient.
Contract revision3, API v2 and offline event version2 are distinct. Do not invent event v3,
UUID-v4-only validation, new routes, response statuses or admission representations here.

Preserve original validated decimal lexemes and ordered lines for JCS/digest. Server totals
use exact BigDecimal and canonical rounding policies. Proposed first cash slice is base
currency, FX 1.0000000000, strictly positive total and exact full cash; reject partial,
unpaid, overpayment credit and change for this slice. This is stricter mobile policy,
not a global canonical unpaid/zero-value accounting rule. Explicit NoTax requires the frozen
null/0.000000/false triplet; a real tax ULID may identify zero OR a nonzero rate and must
resolve to a same-company reviewed snapshot. Catalog null/null tax is not implicit NoTax.

A future immutable terminal DTO needs actual enum/ULID/decimal/count/range validation and
canonical entity correspondence, not merely PHP scalar types or keyword filtering. Keep
internal integer IDs separate from any public wire DTO and align exact keys/status/lexemes
with frozen schemas. PR91 helpers are not runtime DTO, authorization or transaction proof.

PR93 uses shared sorted global master Currency locks and exclusive sorted tenant
CompanyCurrency locks, resolving the full currency union before normalization. Historical
amounts do NOT store a minor-unit snapshot: historical readers still use current master
scale. Out-of-band master precision mutation is unsupported; retirement compatibility is
qualified, precision-mutation protection is not. Base policy remains ILS/USD/JOD; no blanket
purchasing or ledger 0..6 qualification.

## Required proof before an implementation can be qualified

Every future DB suite uses the P0 admission/refusal guard before mutation and teardown
revalidation, then a fresh owned MariaDB3342 schema. No persistent-schema cleanup.

| Proof | Required observation |
|---|---|
| Grant/role/device/password revocation races | Transactional approved authority revalidated at the canonical commit boundary; no stale-cache bypass |
| Login/intake lock races | Two real processes, barriers, measured lock outcomes and bounded retry semantics; no source-only deadlock-free claim |
| Same company/event absent-row race | Two canonical native action workers converge to one invoice/payment/receipt; original actor/device checked before digest; global cross-version identity |
| Existing event / altered payload | Identical lost-ACK replay returns identical terminal result without new numbers/effects; altered digest conflicts after scope checks |
| Independent tenants | Same event ID allowed per tenant without leaking other tenant scope/results |
| Faults after numbering/draft/stock/GL/payment/receipt | Database fingerprint restored relative to pre-event fixtures, not an empty DB claim; no leaked sequences/provenance/economic legs |
| Reconciliations | Accounting, Inventory, Sales, Payables, Money and Phase7 healthy on populated success/rollback fixtures, with read-only fingerprints |
| Typed context/results | Missing/foreign bindings, malformed lexemes, non-finite/float values, invalid enums/IDs and secret-bearing unknown properties rejected |
| Alternate public entrypoints | Cannot reproduce only part of the protected event by opening another transaction or calling a public action |

Company-root serialization may let a second worker read the committed receipt without
triggering duplicate-key failure; do not require a 1062 to call the test valid. Unique
(company,event) is the DB backstop. Duplicate/deadlock handling must remain tightly scoped,
root-only and bounded. Wrapper/test-adapter atomicity is not native financial authority proof.

## Review and implementation gates

1. Source-only proposal review; resolve authority/canonical boundary decisions above.
2. Separate narrow identity-lock and uniform-scope correction with real worker tests.
3. Separately reviewed contract-context/terminal validation and composed dependency candidate.
4. Only after approved authority/boundary design: disabled native cash qualification code,
   explicit test injection, no environment-wide automatic adapter switch, production Deny.
5. Adversarial canonical runtime proof and independent exact-SHA review before any activation request.

Plaintext receipt retention, encryption/key lifecycle and data minimization remain OPEN.
Do not invent a 30-day retention period or delete original bytes needed for immutable replay.
The actual online profile is mostly identifiers and economics; do not assert that it contains
customer names/phones without inspecting the admitted representation. Android outbox encryption,
backup/device-transfer exclusions, key-loss/upgrade preservation, offline stock/provisional
receipt policy, AUTH_BLOCKED recovery and scheduling remain separate gates. Physical-device
Keystore/UI/instrumentation acceptance is blocked. None is waived by this proposal.

This document records source inspection only. No new DB tests, runtime financial effects,
network pilot, migrations, deployments, source pushes or main merges occurred in preparing it.
