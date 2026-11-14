# Mobile V2 Cash Slice Authority & Canonical Boundary Resolution Packet

## 1. Baseline & Provenance Metadata

This resolution packet establishes the concrete, source-backed specification and boundary gate for the first native mobile financial slice: **Default-OFF Base-Currency Full-Cash Immediate Sale**.

- **Required Base Commit**: `c03965912d70ecc9cffe3d2ddc3438768432d824`
- **Attested Ancestor Commit**: `d5420c4f77bf9e0e3169b59c09f7e29a5053ee8f` (PR 95 exact-head qualification manifest)
- **Current Active Branch**: `t3/cash-slice-authority-research` (reset hard to `c03965912d70ecc9cffe3d2ddc3438768432d824`)
- **Explicit Base Exclusion**: Commit `8cd432220801b08b10b6f25f9ee9a215de94d44c` (web controlled release / purchase draft deletion branch) is **NOT** a base and must not be used as an ancestor for mobile financial work.
- **Referenced Verified Source SHAs**:
  - Proposal baseline: `0e39f57d1ced8e7310bc54b3bd94483fd3705855` (`docs/integration/mobile-v2-online-cash-proposal.md`)
  - PR 91 (Contract Revision 3 & Native Validator): `f3836ef7aa8a2437e95fedaec3166399a44158b2`
  - PR 93 (Currency Minor Units Resolver & Concurrency Limits): `1b8a696feda350bbc2df86a26d05b69fd615ba57`
  - PR 87 (Web Invoice/Purchase Workflow & Product Search): `912aa71f557b314837e70138df22615902fc9d7b`
  - Common ancestor / Main baseline: `f8a0cf7012c4875bec7320d42e0f8dde58593b52`

---

## 2. Canonical Business Execution & Authority Trace

### 2.1 The Five Canonical Legs of a Cash Sale Event
In Small Trader Accounting, an immediate cash sale is an atomic economic event composed of five inseparable legs:

1. **Sales Invoice Draft Creation & Valuation**:
   - Source: `app/Actions/Sales/CreateSalesInvoiceDraftAction.php`.
   - Validates customer active status, currency matching company enablement, line item pricing via `SalesDocumentRules::price`, unit conversions, and tax rate snapshots.
2. **Document Numbering Allocation**:
   - Source: `app/Services/Sales/DocumentSequenceService.php`.
   - Generates the irreversible fiscal sequence number (`DocumentSequence::TYPE_SALES_INVOICE`) under row lock for the current calendar year.
3. **FEFO Inventory Movement & Lot Allocation**:
   - Sources: `app/Services/Inventory/FefoAllocationService.php`, `app/Services/Inventory/InventoryMovementService.php`.
   - Locks products (sorted by ID to prevent deadlocks), checks stock availability, allocates lots under FEFO rules, decrements balance, creates `StockMovement` (type `sale`), and inserts `SalesInvoiceLotAllocation` records.
4. **General Ledger Double-Entry Posting**:
   - Source: `app/Services/Posting/AccountingPostingService.php`.
   - Dispatches `PostingCommand` with balanced debit/credit lines: Debit Accounts Receivable (AR), Credit Sales Revenue, Credit Tax Output, Debit Cost of Goods Sold (COGS), Credit Inventory Asset. Creates immutable `PostingBatch` and `PostingLine` entries.
5. **Customer Payment & Immediate Allocation**:
   - Source: `app/Actions/Sales/PostCustomerPaymentAction.php`.
   - Allocates payment to invoice (`CustomerPaymentAllocation`), generates customer payment sequence number (`DocumentSequence::TYPE_CUSTOMER_PAYMENT`), posts payment GL batch (Debit Cash Account, Credit Accounts Receivable), and transitions invoice status to fully paid.

### 2.2 Web Session Authority vs Native Mobile Reality
- **Browser Reference (`ImmediateSaleCommands`)**:
  - `ImmediateSaleCommands::execute` coordinates legs 1-5 inside `TenantWriteAccess::transaction`.
  - It enforces **`CommandSessionGuard::authorize($actor)`**, which requires:
    1. An active DB transaction (`DB::transactionLevel() > 0`).
    2. Connection equality between session store and canonical transaction.
    3. An internal request attribute `phase11_api_session` containing `session_id`.
    4. Row-level `lockForUpdate` on the `sessions` table matching user ID.
    5. Session age and idle timeout checks.
    6. Fresh row-lock check on `users.password` against the current session hash.
- **Native Mobile Reality**:
  - Native mobile clients (Android/iOS) connect over sessionless HTTP with **Bearer tokens** (`MobileAccessToken`).
  - Mobile requests have **no web session**, no row in `sessions` table, and no cookie.
  - **Synthesizing a fake browser session or dummy `phase11_api_session` attribute is strictly PROHIBITED** by project invariants.
  - Furthermore, `MobileV2Authenticate` currently rejects any financial capability (e.g. `sales.*`, `money.*`) with `FINANCIAL_AUTHORITY_UNRESOLVED` (HTTP 403), and `AppServiceProvider` binds `FinancialExecutionAdapterInterface` to `DenyFinancialExecutionAdapter`.

---

## 3. Permissions + Transaction vs Unforgeable Inseparable Authority

A critical architectural invariant from `AGENTS.md` (lines 53-63) dictates:
> "Treat permissions, an open DB transaction, and source-code call-site scans as defense in depth, not proof of canonical authority. Look for alternate public/runtime entrypoints that could commit only one leg of the business event. Canonical effects that must be inseparable should use an action/domain boundary that cannot be reproduced by merely starting another transaction."

### 3.1 Why Permissions + Transaction Fall Short
1. **Disaggregated Public Entrypoints**:
   - `CreateSalesInvoiceDraftAction`, `PostSalesInvoiceAction`, and `PostCustomerPaymentAction` are currently independent public service classes.
   - A user who holds permissions `sales.invoice.create`, `sales.invoice.post`, `money.receipt.create`, and `money.receipt.allocate` can call `PostSalesInvoiceAction` alone, leaving stock and receivables committed without payment.
   - Simply wrapping multiple public actions inside a generic `DB::transaction()` does not close alternate entrypoints or prevent partial commits if invoked from another controller or worker.
2. **Registrar Cache & Role Concurrency**:
   - Spatie team permissions are cached in-memory per worker process (`PermissionRegistrar`).
   - Although PR 95 enforces row-level locks on `CompanyUser` (membership pivot), the definitions of roles and their assigned permissions (`roles`, `role_has_permissions`) reside in tables that are not serialized during admission.
   - An out-of-band role edit or cache staleness can allow or deny operations unpredictably across concurrent workers unless authority is verified transactionally at the canonical root boundary.
3. **Bearer Token Identity vs Financial Execution Authority**:
   - A Bearer token is proof of **identity** (identifying staff member $U$ on enrolled device $D$), not proof of authority to execute irreversible financial mutations.
   - A mobile device could be stolen, or a staff member's credentials compromised. Unlike web commands where `CommandSessionGuard` revalidates live session and password hash on every command, mobile requests require an **unforgeable, inseparable execution boundary** that validates fresh membership, device status, token binding, and normative payload integrity atomically under company root lock.

---

## 4. Root-Resolvable Technical Decisions vs True Owner Business Choices

To maintain strict architectural clarity, decisions are cleanly partitioned into those resolvable from existing domain invariants vs those requiring explicit owner business selection.

### 4.1 Technical Decisions Resolved from Existing Domain Policy

| Decision Area | Technical Resolution from Domain Invariants | Source Anchor / Proof |
|---|---|---|
| **Root Transaction Invariant** | Must execute strictly under `DB::transactionLevel() === 1`. Nested transactions or non-transactional calls are rejected before any effects. | `MobileEventReceiptManager::recordOrReplay` (`c039659`) |
| **Acyclic Row Lock Order** | Strict linear DAG: `Company` $\rightarrow$ `MobileAccessToken` $\rightarrow$ `MobileDevice` $\rightarrow$ `User` $\rightarrow$ `CompanyUser` $\rightarrow$ `Warehouse` $\rightarrow$ `Customer` $\rightarrow$ `Product` (sorted by ID) $\rightarrow$ `InventoryLot` (FEFO) $\rightarrow$ `MoneyAccount` $\rightarrow$ `DocumentSequence` $\rightarrow$ `MobileEventReceipt`. | PR 95 qualification logs (`tests/Feature/Mobile/MobileReceiptLockOrderQualificationTest.php`) |
| **Atomic Numbering & Rollback** | If stock is insufficient or GL lines fail, transaction rolls back completely. `DocumentSequence` row locks roll back without sequence leakage. | `DocumentSequenceService.php`, `MobileReceiptRollbackAtomicityTest.php` |
| **Exact Decimals & Currency** | For this slice: strictly `company.base_currency_code`, exchange rate `'1.0000000000'`, minor units 0..6 resolved dynamically via `CurrencyMinorUnitsResolver`, exact full cash payment matching `grand_total`. Zero binary float arithmetic. | ADR 0002, PR 93 `CurrencyMinorUnitsResolver.php`, PR 91 `PaymentSettlementValidator.php` |
| **Zero-Value Transactions** | Rejected. Invoices must have strictly positive total amount (`BigDecimal::isPositive()`). | `AGENTS.md` Rule 13, `OnlineSaleIntentProfile.php` |
| **Idempotency & Replay** | Keyed on `(company_id, client_event_id)`: identical JCS canonical bytes return identical terminal DTO without re-executing; altered payload returns HTTP 409 Conflict (`MobileEventDuplicatePayloadException`). | `MobileEventReceiptManager.php`, `mobile_event_receipts_company_client_event_unique` |
| **Closure of Public Runtime Bypass** | Mobile V2 routes expose NO independent draft, post, or payment endpoints. The unified executor is private to the domain boundary; standalone execution without `MobileEventReceiptManager` is refused. | `MobileV2Authenticate.php`, `DenyFinancialExecutionAdapter.php` |

### 4.2 True Owner Business Choices (Requiring Explicit Owner Decision)

1. **Receipt Original Bytes Retention & Cryptographic Lifecycle**:
   - *Current Schema*: `mobile_event_receipts.original_canonical_bytes` is stored as plaintext `longText` (`binary` charset).
   - *Owner Decisions Needed*:
     - (a) Retention horizon: Should original payload bytes be retained indefinitely for audit/replay, or pruned after a tax retention period (e.g. 5 or 7 years)?
     - (b) Encryption at rest: Should `original_canonical_bytes` be stored plaintext or encrypted via `Crypt::encryptString`? (Note: payload hash `sha256` remains plaintext for tamper-proof index lookup).
   - *Bounded Recommendation*: Retain indefinitely in database as plaintext binary for the first qualification slice, since payload contains only IDs and economic numbers (no customer PII/card numbers).
2. **Status-Lost ACK / Client Reconnection Protocol**:
   - *Scenario*: Mobile client submits sale; server successfully commits all 5 legs and inserts receipt; network drops before HTTP 201 reaches client.
   - *Owner Decisions Needed*:
     - Does the Android client simply retry the exact same `POST` request with identical `client_event_id` (triggering idempotent replay of `terminal_canonical_result`), OR does client require a dedicated `GET /api/mobile/v2/receipts/{client_event_id}` query route?
   - *Bounded Recommendation*: Standardize on `POST` replay returning `outcome: 'replayed'` with identical terminal DTO. A dedicated `GET` lookup route can be frozen as an optional read endpoint if needed.
3. **Stock Insufficiency Behavior**:
   - *Owner Decision Needed*: Confirm that when inventory is insufficient for any tracked line, the sale is strictly refused (HTTP 422/409, complete rollback), with zero allowance for negative inventory, backorders, or provisional offline receipts.
   - *Bounded Recommendation*: Confirm strict refusal as required by core Small Trader accounting invariants.
4. **Initial Cash Slice Boundary Scope**:
   - *Owner Decisions Needed*: Confirm that the initial slice strictly excludes:
     - Cross-currency sales (invoice currency $\neq$ base currency).
     - Partial payments / unpaid credit sales.
     - Customer overpayments / change returned.
     - Multiple tender methods / checks / bank transfers.
   - *Bounded Recommendation*: Strict base-currency, exact full cash only. All other variations fail closed.
5. **Authority Grant / Delegation Model**:
   - *Owner Decision Needed*: What qualifies a user/device to execute financial posting on Mobile V2?
     - Option A (Role-based): Any active user with `sales.invoice.post` + `money.receipt.create` permissions on the company.
     - Option B (Explicit Device Enrollment): The company owner must explicitly designate the mobile device as an authorized POS terminal (`mobile_devices.is_pos_terminal = true`).
   - *Bounded Recommendation*: Option B (Explicit POS capability flag on device combined with active user permissions), ensuring that a compromised personal phone cannot post sales even if the user has web permissions.

---

## 5. Concrete Composition Strategy: PR 91, PR 93, and PR 87

Dependencies exist across separate branches originating from common base `f8a0cf7012c4875bec7320d42e0f8dde58593b52`. We compose them cleanly on top of `c03965912d70ecc9cffe3d2ddc3438768432d824` **without** merge commits, rebases, or bringing in unrelated web code.

```
f8a0cf7 (main)
  ├── PR 95: c039659 (Base: Attested exact-head receipt identity & lock ordering)
  ├── PR 91: f3836ef (Contract Revision 3: Intent Profile & Settlement Validator)
  ├── PR 93: 1b8a696 (Money: Currency Minor Units Resolver & DB locks)
  └── PR 87: 912aa71 (Web: Bounded product picker & search workflow)
```

### 5.1 Cherry-Pick / Source Inclusion Plan

1. **Incorporate PR 91 (`f3836ef7aa8a2437e95fedaec3166399a44158b2`)**:
   - Files to bring in:
     - `app/Support/Mobile/Contract/Revision3/OnlineSaleIntentProfile.php`
     - `app/Support/Mobile/Contract/Revision3/PaymentSettlementValidator.php`
     - `app/Support/Mobile/Contract/Revision3/Exceptions/IntentProfileValidationException.php`
     - `tests/Unit/Mobile/Contract/Revision3/OnlineSaleIntentProfileTest.php`
     - `tests/Unit/Mobile/Contract/Revision3/PaymentSettlementValidatorTest.php`
     - `tests/Unit/Mobile/Contract/Revision3/CrossLanguageParityTest.php`
   - *Note*: These are pure PHP domain and contract classes with zero DB side-effects or web dependencies.
2. **Incorporate PR 93 (`1b8a696feda350bbc2df86a26d05b69fd615ba57`)**:
   - Files to bring in:
     - `app/Services/Money/CurrencyMinorUnitsResolver.php`
     - `app/Domain/Accounting/Exceptions/BaseCurrencyLockedException.php`
     - `tests/Feature/Money/CurrencyMinorUnitQualificationTest.php`
   - Bind `CurrencyMinorUnitsResolver` as singleton in `app/Providers/AppServiceProvider.php` (preserving existing `FinancialExecutionAdapterInterface` binding).
3. **Incorporate PR 87 Domain Overlap (Excluding Web Migration)**:
   - **EXCLUDE**:
     - Migration: `2026_11_11_100000_add_product_search_prefix_indexes.php`
     - Livewire pages & Blade views: `resources/views/livewire/pages/sales/invoice-form.blade.php`, `purchase-form.blade.php`, `product-search-picker.blade.php`, etc.
     - Frontend JS: `resources/js/product-search-picker.js`, `document-operations.js`.
     - Service: `BoundedProductSearchService.php` (web-only search indexing).
   - **INCLUDE ONLY**:
     - The refactored `suggestedBasePrice` method in `app/Services/Sales/SalesProductSelection.php`.
4. **Reconcile Overlaps in `SalesDocumentRules.php` and `SalesProductSelection.php`**:
   - PR 87 added `SalesProductSelection::suggestedBasePrice(Product $product, ProductUnit $unit): ?BigDecimal`.
   - PR 93 added dynamic minor units resolution via `CurrencyMinorUnitsResolver::resolveForCompany`.
   - Reconciled code integrates both: `price()` uses `suggestedBasePrice()` for default pricing while using `CurrencyMinorUnitsResolver` for exact decimal scaling.

---

## 6. Minimal Default-OFF Implementation Architecture

To ensure zero production risk, the new cash execution slice is configured **Default-OFF** via feature flags and bound to a dedicated execution adapter.

### 6.1 Configuration & Flag Barrier
In `config/mobile.php`:
```php
'v2_enabled' => env('MOBILE_V2_ENABLED', false),
'v2_cash_slice_enabled' => env('MOBILE_V2_CASH_SLICE_ENABLED', false),
```
Unless **both** flags are explicitly `true`, any incoming request to the cash slice endpoint returns HTTP 403 / 404 immediately.

### 6.2 New Inseparable Action Boundary
Create `app/Actions/Sales/ExecuteImmediateOnlineCashSaleAction.php`:
- Implements the complete 5-leg atomic execution internally.
- Enforces:
  1. `DB::transactionLevel() === 1` (must be root transaction).
  2. Caller must provide a verified `MobileEventReceiptContext` (cannot be invoked as a generic public action).
  3. Revalidates fresh locked models for Company, Actor, Device, Customer, Warehouse, Products, Lots, and Cash Account.
  4. Returns typed `TerminalCanonicalSaleResult` DTO.

### 6.3 Concrete Financial Execution Adapter
Replace `DenyFinancialExecutionAdapter` in testing/qualification environments with `QualifiedCashFinancialExecutionAdapter` (bound conditionally or injected in test harness):
```php
final class QualifiedCashFinancialExecutionAdapter implements FinancialExecutionAdapterInterface
{
    public function __construct(
        private readonly ExecuteImmediateOnlineCashSaleAction $action
    ) {}

    public function authorize(Company $company, User $actor, MobileDevice $device, string $operation, ?array $payload = null): void
    {
        // Revalidates permissions and device POS enablement under row locks
    }

    public function execute(Company $company, User $actor, MobileDevice $device, array $payload, string $idempotencySuffix): array
    {
        return $this->action->execute($company, $actor, $device, $payload, $idempotencySuffix);
    }
}
```

---

## 7. Exact Wire Contracts (To Freeze for Android POS)

### 7.1 Route Definition
```http
POST /api/mobile/v2/companies/{companyId}/sales/online-cash
Authorization: Bearer <mobile_access_token>
Content-Type: application/json
Accept: application/json
```

### 7.2 Request Schema (`v2_online_sale_intent`)
```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "https://contracts.smalltrader.app/mobile/v2/online-cash-sale-intent.json",
  "title": "OnlineCashSaleIntentRequest",
  "type": "object",
  "required": [
    "client_event_id",
    "device_id",
    "customer_id",
    "warehouse_id",
    "currency",
    "lines",
    "payment"
  ],
  "properties": {
    "client_event_id": {
      "type": "string",
      "pattern": "^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$"
    },
    "device_id": {
      "type": "string",
      "pattern": "^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$"
    },
    "customer_id": {
      "type": "string",
      "pattern": "^[0-7][0-9A-HJKMNP-TV-Z]{25}$"
    },
    "warehouse_id": {
      "type": "string",
      "pattern": "^[0-7][0-9A-HJKMNP-TV-Z]{25}$"
    },
    "currency": {
      "type": "string",
      "pattern": "^[A-Z]{3}$"
    },
    "lines": {
      "type": "array",
      "minItems": 1,
      "maxItems": 100,
      "items": {
        "type": "object",
        "required": [
          "product_id",
          "product_unit_id",
          "conversion_to_base",
          "quantity",
          "unit_price",
          "tax_rate_id",
          "tax_rate",
          "tax_inclusive"
        ],
        "properties": {
          "product_id": { "type": "string", "pattern": "^[0-7][0-9A-HJKMNP-TV-Z]{25}$" },
          "product_unit_id": { "type": "string", "pattern": "^[0-7][0-9A-HJKMNP-TV-Z]{25}$" },
          "conversion_to_base": { "type": "string", "pattern": "^(?:0\\.(?=[0-9]{1,6}$)[0-9]*[1-9][0-9]*|[1-9][0-9]{0,13}(?:\\.[0-9]{1,6})?)$" },
          "quantity": { "type": "string", "pattern": "^(?:0\\.(?=[0-9]{1,6}$)[0-9]*[1-9][0-9]*|[1-9][0-9]{0,13}(?:\\.[0-9]{1,6})?)$" },
          "unit_price": { "type": "string", "pattern": "^(?:0|[1-9][0-9]{0,13})(?:\\.[0-9]{1,6})?$" },
          "tax_rate_id": { "type": ["string", "null"], "pattern": "^[0-7][0-9A-HJKMNP-TV-Z]{25}$" },
          "tax_rate": { "type": "string", "pattern": "^(?:100(?:\\.0{1,6})?|(?:0|[1-9][0-9]?)(?:\\.[0-9]{1,6})?)$" },
          "tax_inclusive": { "type": "boolean" }
        },
        "additionalProperties": false
      }
    },
    "payment": {
      "type": "object",
      "required": [
        "settlement_mode",
        "payment_method",
        "cash_account_id",
        "amount"
      ],
      "properties": {
        "settlement_mode": { "type": "string", "enum": ["full"] },
        "payment_method": { "type": "string", "enum": ["cash"] },
        "cash_account_id": { "type": "string", "pattern": "^[0-7][0-9A-HJKMNP-TV-Z]{25}$" },
        "amount": { "type": "string", "pattern": "^(?:0\\.(?=[0-9]{1,6}$)[0-9]*[1-9][0-9]*|[1-9][0-9]{0,13}(?:\\.[0-9]{1,6})?)$" }
      },
      "additionalProperties": false
    }
  },
  "additionalProperties": false
}
```

### 7.3 Terminal Response / Replay Schema (`terminal_canonical_result`)
```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "https://contracts.smalltrader.app/mobile/v2/online-cash-sale-response.json",
  "title": "OnlineCashSaleResponse",
  "type": "object",
  "required": [
    "outcome",
    "status",
    "client_event_id",
    "sales_invoice_public_id",
    "invoice_number",
    "customer_payment_public_id",
    "currency",
    "grand_total",
    "paid_amount",
    "lines_count"
  ],
  "properties": {
    "outcome": { "type": "string", "enum": ["created", "replayed"] },
    "status": { "type": "string", "enum": ["completed"] },
    "client_event_id": { "type": "string", "pattern": "^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$" },
    "sales_invoice_public_id": { "type": "string", "pattern": "^[0-7][0-9A-HJKMNP-TV-Z]{25}$" },
    "invoice_number": { "type": "string", "minLength": 1, "maxLength": 64 },
    "customer_payment_public_id": { "type": "string", "pattern": "^[0-7][0-9A-HJKMNP-TV-Z]{25}$" },
    "currency": { "type": "string", "pattern": "^[A-Z]{3}$" },
    "grand_total": { "type": "string", "pattern": "^(?:0\\.(?=[0-9]{1,6}$)[0-9]*[1-9][0-9]*|[1-9][0-9]{0,13}(?:\\.[0-9]{1,6})?)$" },
    "paid_amount": { "type": "string", "pattern": "^(?:0\\.(?=[0-9]{1,6}$)[0-9]*[1-9][0-9]*|[1-9][0-9]{0,13}(?:\\.[0-9]{1,6})?)$" },
    "lines_count": { "type": "integer", "minimum": 1 }
  },
  "additionalProperties": false
}
```

---

## 8. Guarded Concurrency & Validation Test Matrix

Every subsequent test qualification run on disposable MariaDB must verify:

| Test Case | Scenario / Mutation | Expected Outcome |
|---|---|---|
| **P0-1: Root Level Check** | Attempt execution inside existing transaction (`DB::transactionLevel() > 1`) or without transaction. | `IllegalStateException` before any row lock or sequence mutation. |
| **P0-2: Two-Process Contention** | Two concurrent workers submit identical `(company_id, client_event_id)`. | One process creates; second process converges via row lock and returns identical `outcome: 'replayed'` without duplicate numbering. |
| **P0-3: Lost-ACK Replay** | Same client resubmits after simulated network failure. | Returns exact original `terminal_canonical_result` with status `completed`. Zero new DB mutations. |
| **P0-4: Altered Payload Conflict** | Client resubmits same `client_event_id` with modified quantity or price. | HTTP 409 Conflict (`MobileEventDuplicatePayloadException`). |
| **P0-5: Fault Injection Rollback** | Inject exception after numbering, stock deduction, or GL lines before receipt insert. | Full rollback: pre-event database fingerprint verified identical. Zero sequence gaps. |
| **P0-6: Stock Insufficiency** | Requested quantity exceeds available FEFO lot balance. | HTTP 422/409, complete rollback, zero partial invoice or payment recorded. |
| **P0-7: Non-Base Currency Refusal** | Request specifies USD when company base is ILS. | HTTP 422 refusal; cross-currency cash slice is disabled. |
| **P0-8: Non-Matching Payment Amount** | Payment amount differs from calculated invoice grand total by even 0.000001. | HTTP 422 refusal; partial and overpayments rejected. |
| **P0-9: Six-Service Reconciliations** | Run Accounting, Inventory, Sales, Payables, Money, and Phase 7 reconciliation commands. | All reconciliations pass with zero unallocated amounts or ledger discrepancies. |

---

## 9. Next Steps & Gate Criteria

1. **Root Independent Review**:
   - Review this resolution packet against Android POS team requirements and core architectural invariants.
2. **Owner Explicit Sign-Off**:
   - Confirm recommendations on receipt bytes retention, status-lost ACK protocol, and POS device capability flag.
3. **Draft Implementation Phase**:
   - Compose PR 91, PR 93, and PR 87 domain overlap onto `t3/cash-slice-authority-research`.
   - Implement `ExecuteImmediateOnlineCashSaleAction` and qualified test harness.
   - Run disposable MariaDB test suite and capture exact logs.
   - **No activation in production** until full gate review.
