# Mobile V2 Cash Slice Authority & Canonical Boundary Resolution Packet (Corrected)

## 1. Baseline & Provenance Metadata

This resolution packet establishes the concrete, source-backed specification and boundary gate for the first native mobile financial slice: **Default-OFF Base-Currency Full-Cash Immediate Sale**.

- **Required Base Commit**: `c03965912d70ecc9cffe3d2ddc3438768432d824`
- **Attested Ancestor Commit**: `d5420c4f77bf9e0e3169b59c09f7e29a5053ee8f` (PR 95 exact-head qualification manifest)
- **Current Active Branch**: `t3/cash-slice-authority-research` (reset hard to `c03965912d70ecc9cffe3d2ddc3438768432d824`)
- **Explicit Base Exclusion**: Commit `8cd432220801b08b10b6f25f9ee9a215de94d44c` (web controlled release / purchase draft deletion branch) is **NOT** a base and is strictly excluded from mobile financial history.
- **Referenced Verified Source SHAs (Git `rev-parse` Verified)**:
  - Proposal baseline: `0e39f57d1ced8e7310bc54b3bd94483fd3705855` (`docs/integration/mobile-v2-online-cash-proposal.md`)
  - PR 91 (Contract Revision 3 & Native Validator): `f3836ef7aa8a2437e95fedaec3166399a44158b2`
  - PR 93 (Currency Minor Units Resolver & Action Closure): `1b8a696feda350bbc2df86a26d05b69fd615ba57`
  - PR 87 (Web Invoice/Purchase Workflow Domain Overlap): `912aa71f557b314837e70138df22615902fc9d7b`
  - Common ancestor / Main baseline: `f8a0cf7012c4875bec7320d42e0f8dde58593b52`

---

## 2. Qualified Identity Prefix & Canonical Lock Edge Order

### 2.1 The Qualified Identity Prefix
From the authoritative implementation in `app/Support/Mobile/Receipt/MobileEventReceiptManager.php` (lines 170–202 and 493–522), the qualified identity prefix acquired inside the write transaction is strictly:

$$\mathbf{Company} \longrightarrow \mathbf{Membership\ (company\_user)} \longrightarrow \mathbf{User\ (users)} \longrightarrow \mathbf{Device\ (mobile\_devices)} \longrightarrow \mathbf{Token\ (mobile\_access\_tokens)}$$

1. **`Company`**: `Company::whereKey($companyId)->lockForUpdate()->firstOrFail()`. Confirms active status.
2. **`Membership`**: `DB::table('company_user')->where('company_id', $companyId)->where('user_id', $tentativeToken->user_id)->where('status', 'active')->lockForUpdate()->firstOrFail()`.
3. **`User` (Principal)**: `User::whereKey($tentativeToken->user_id)->lockForUpdate()->firstOrFail()`.
4. **`Device`**: `MobileDevice::where('device_id', $tentativeToken->device_id)->lockForUpdate()->firstOrFail()`. Confirms not revoked and owned by user.
5. **`Token`**: `MobileAccessToken::where('token_hash', $tokenHash)->lockForUpdate()->firstOrFail()`.
   - Validates expiry and device/user binding.
   - Enforces password revision epoch:
     $$\text{expectedEpoch} = \text{'epoch-'} + \text{substr}(\text{hash}(\text{'sha256'}, \text{user.password}), 0, 16)$$
     $$\text{hash\_equals}(\text{expectedEpoch}, \text{token.password\_epoch}) \equiv \text{true}$$
   - Prevents ambient session conflation: rejects mismatched browser guard actors.

### 2.2 Role Permissions Definitions & Registrar Cache Linearity
Under tenant context activation (`setPermissionsTeamId($companyId)`), PR 95 acquires cooperative row locks on user model assignments:
```php
DB::table('model_has_roles')
    ->where('model_type', User::class)
    ->where('model_id', $user->id)
    ->where($teamKey, $companyId)
    ->lockForUpdate()->get();

DB::table('model_has_permissions')
    ->where('model_type', User::class)
    ->where('model_id', $user->id)
    ->where($teamKey, $companyId)
    ->lockForUpdate()->get();
```
**Architectural Invariant**:
- Locking pivot rows (`model_has_roles`, `model_has_permissions`) serializes concurrent assignment mutations for this actor.
- However, Spatie registrar caches in other worker processes and table-level definition mutations on `roles` and `role_has_permissions` are **not** serialized by pivot locks alone.
- Therefore, model pivot locks are defense-in-depth; **ProductionDeny remains mandatory** until transactional capability verification is qualified.

### 2.3 Currency and Canonical Writer Edge Order
Derived directly from PR 93 (`CurrencyMinorUnitsResolver::resolveCurrenciesForCompanyWrite`) and surrounding canonical sales writers:

1. **Company Root**: `Company` exclusive row lock (`lockForUpdate`).
2. **Identity Prefix**: `Company` $\rightarrow$ `Membership` $\rightarrow$ `User` $\rightarrow$ `Device` $\rightarrow$ `Token`.
3. **Multi-Currency Write Resolution (PR 93)**:
   - For all distinct currencies involved (document currency and company base currency), sort alphabetically by ISO code (`SORT_STRING`).
   - Acquire **Global `Currency` shared locks** in alphabetical order (`Currency::where('code', $code)->sharedLock()->firstOrFail()`). Shared locks prevent out-of-band metadata/scale mutation while allowing concurrent reads across independent tenants.
   - Acquire **Tenant `CompanyCurrency` exclusive locks** in alphabetical order (`CompanyCurrency::where('company_id', $companyId)->where('currency_code', $code)->lockForUpdate()->firstOrFail()`).
4. **Canonical Entity Locks**:
   - `Warehouse`: `Warehouse::where('company_id', $companyId)->whereKey($warehouseId)->lockForUpdate()->firstOrFail()`.
   - `Customer`: `Customer::where('company_id', $companyId)->whereKey($customerId)->lockForUpdate()->firstOrFail()`.
   - `Products`: Locked in ascending integer `id` order (`Product::where('company_id', $companyId)->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()`) to prevent deadlock cycles across concurrent sales.
   - `Inventory Lots`: Locked in FEFO chronological order during lot allocation (`FefoAllocationService`).
   - `MoneyAccount`: `MoneyAccount::where('company_id', $companyId)->whereKey($cashAccountId)->lockForUpdate()->firstOrFail()`.
   - `DocumentSequence`: Row lock on sequence counters for `sales_invoice` and `customer_payment`.
   - `MobileEventReceipt`: `(company_id, client_event_id)` locked/inserted.

### 2.4 Bounded Deadlock Proof & Mitigation
- A theoretical "guarantee of zero deadlocks" is **not accepted**. In MySQL/MariaDB InnoDB, concurrent transactions acquiring multi-table row locks, secondary index locks, and gap locks can produce deadlocks (SQLSTATE `40001`, Error Code `1213`).
- **Mitigation & Proof Required**:
  1. `MobileEventReceiptManager::isDeadlockError` catches SQLSTATE `40001` / Error `1213` and implements bounded exponential backoff retries (maximum 5 attempts).
  2. Test qualification must include real two-process barrier contention tests verifying both winner orders converge without unhandled deadlock exceptions or orphan sequences.

---

## 3. Concrete Structural Mobile Authority Design

### 3.1 The Problem: Permissions and Transactions Are Not Inseparable Authority
1. **Disaggregated Public Entrypoints**: Public service actions (`CreateSalesInvoiceDraftAction`, `PostSalesInvoiceAction`, `PostCustomerPaymentAction`) are independently committable. A caller with permissions can execute one leg without the others.
2. **Web Session Guard Prohibited on Mobile**: Browser commands use `CommandSessionGuard`, which validates a web session row (`sessions` table). Mobile requests have no web session. Synthesizing dummy session rows or forging `phase11_api_session` request attributes is strictly prohibited.
3. **Mere `DB::transactionLevel() === 1` Is Insufficient**: Checking transaction depth proves an outer transaction exists, but does not prove *which* code opened it or guarantee all five legs will execute.

### 3.2 The Solution: Scoped Single-Use Native Execution Capability
Only the fresh, locked native admission inside `MobileEventReceiptManager::recordOrReplay()` can issue a **`NativeFinancialExecutionCapability`**:

```php
namespace App\Support\Mobile\Receipt;

final class NativeFinancialExecutionCapability
{
    private bool $consumed = false;

    /**
     * Internal constructor: can only be instantiated by MobileEventReceiptManager.
     */
    public function __construct(
        public readonly int $companyId,
        public readonly string $companyPublicId,
        public readonly int $actorId,
        public readonly string $actorPublicId,
        public readonly string $deviceId,
        public readonly string $tokenPasswordEpoch,
        public readonly string $clientEventId,
        public readonly string $eventHash,
        public readonly int $currencyMinorUnits,
        private readonly string $singleUseSecret
    ) {}

    public function isConsumed(): bool
    {
        return $this->consumed;
    }

    public function consume(string $expectedSecret): void
    {
        if ($this->consumed) {
            throw new \SecurityException('Native financial capability has already been consumed.');
        }
        if (! hash_equals($this->singleUseSecret, $expectedSecret)) {
            throw new \SecurityException('Invalid single-use capability secret.');
        }
        $this->consumed = true;
    }
}
```

### 3.3 Binding the Capability to Inseparable All-Legs Execution
1. **Mandatory Parameter**: The canonical cash sale action (`ExecuteImmediateOnlineCashSaleAction`) requires `NativeFinancialExecutionCapability` as a required parameter. It **cannot** be called without it.
2. **Immediate Single-Use Consumption**: Upon entry, the action calls `$capability->consume($secret)`. Replay or reuse within the same or another request fails immediately.
3. **Inseparable Execution of All Five Legs**:
   The action executes inside root transaction (`DB::transactionLevel() === 1`):
   - Leg 1: Invoice Draft Creation
   - Leg 2: Sequence Number Reservation (`sales_invoice`)
   - Leg 3: FEFO Stock Allocation & Movements
   - Leg 4: GL Double-Entry Posting Batch
   - Leg 5: Customer Payment & Sequence Number (`customer_payment`)
   - Terminal: `MobileEventReceipt` persistence with `terminal_canonical_result`.
4. **Failure Behavior**: If any leg fails, the entire transaction rolls back, sequence counters roll back, the capability remains consumed, and zero partial records persist.

### 3.4 Retaining Legitimate Web AR Flows
- Existing web actions (`CreateSalesInvoiceDraftAction`, `PostSalesInvoiceAction`, `PostCustomerPaymentAction`, `SalesInvoiceCommands`, `ImmediateSaleCommands`) remain completely intact on web routes, continuing to use `CommandSessionGuard` and Livewire web forms.
- They are **not** globally disabled or modified in a way that breaks web sales or AR.
- Mobile V2 routes simply do not expose individual draft, post, or payment endpoints. The unified cash sale route is the **sole** native mobile entrypoint.

---

## 4. Revision 3 Wire vs. Server Context Mapping (From Source)

No new routes, event versions, or ID types are invented. Revision 3 maps existing wire inputs to authoritative server context using pure PHP helpers from PR 91.

### 4.1 Raw Client Wire Request
Client sends JSON matching `v2_online_sale_intent` (Event Version 2):
```json
{
  "client_event_id": "3fa85f64-5717-4562-b3fc-2c963f66afa6",
  "device_id": "8e2b8bc6-f80c-4034-8c88-59368d1f2e10",
  "customer_id": "01JCV8X9M4K1P2Q3R4S5T6V7W8",
  "warehouse_id": "01JCV8X9M9A2B3C4D5E6F7G8H9",
  "currency": "ILS",
  "lines": [
    {
      "product_id": "01JCV8X9M6A1B2C3D4E5F6G7H8",
      "product_unit_id": "01JCV8X9MBV4W5X6Y7Z8012345",
      "conversion_to_base": "1.000000",
      "quantity": "2.000000",
      "unit_price": "25.000000",
      "tax_rate_id": "01J9Z5K3MN0000000000000030",
      "tax_rate": "16.000000",
      "tax_inclusive": true
    }
  ],
  "payment": {
    "settlement_mode": "full",
    "payment_method": "cash",
    "cash_account_id": "01JCV8X9MAJ3K4M5N6P7Q8R9S0",
    "amount": "50.000000"
  }
}
```

### 4.2 Authoritative Server Context
Resolved by server under lock (never supplied by client on wire):
- `company_id`: `company.public_id` (from authenticated route context)
- `actor_id`: `user.public_id` (from authenticated Bearer token)
- `currency_minor_units`: `2` (resolved from `CurrencyMinorUnitsResolver::resolveForCompany`)

### 4.3 Normative Intent Profile (`OnlineSaleIntentProfile::construct`)
Combines wire request and server context, validates AST, rejects floats and surrogate UTF-8, and verifies payment settlement consistency via `PaymentSettlementValidator`.
- Serialized to restricted Canonical JSON (RFC 8785) via `CanonicalJsonSerializer::serialize($profile)`.
- Payload digest: `hash('sha256', $canonicalJson)`.

### 4.4 Canonical Entity Execution to `terminal_canonical_result`
Maps canonical models to existing `ALLOWED_TERMINAL_RESULT_KEYS` in `MobileEventReceiptManager`:
```php
[
    'status' => 'completed',
    'sales_invoice_id' => (int) $invoice->id,
    'sales_invoice_public_id' => (string) $invoice->public_id,
    'invoice_public_id' => (string) $invoice->public_id,
    'invoice_number' => (string) $invoice->invoice_number,
    'customer_payment_id' => (int) $payment->id,
    'customer_payment_public_id' => (string) $payment->public_id,
    'payment_public_id' => (string) $payment->public_id,
    'command_receipt_id' => null,
    'customer_id' => (int) $invoice->customer_id,
    'customer_public_id' => (string) $customer->public_id,
    'currency' => (string) $invoice->currency_code,
    'total_amount' => (string) $invoice->grand_total_currency,
    'grand_total' => (string) $invoice->grand_total_currency,
    'paid_amount' => (string) $payment->amount,
    'amount' => (string) $payment->amount,
    'lines_count' => count($invoice->lines),
]
```

### 4.5 Wire Response & Status Mapping
- **Fresh Creation (HTTP 201)**:
  `{ "outcome": "created", ...terminal_canonical_result }`
- **Lost-ACK Replay (HTTP 200)**:
  `{ "outcome": "replayed", ...terminal_canonical_result }`
- **Payload Tampering / Modification with Same Event ID (HTTP 409)**:
  `{ "error": { "code": "IDEMPOTENCY_CONFLICT", "message": "Sale retry differs from original payload." } }`

---

## 5. Composition Strategy: Full Action Dependency Closure

Dependencies are composed cleanly on `c03965912d70ecc9cffe3d2ddc3438768432d824` without merge commits or rebases:

```
f8a0cf7 (main)
  ├── PR 95: c039659 (Base: Attested receipt identity & lock ordering)
  ├── PR 91: f3836ef (Contract Rev3: Intent Profile & Settlement Validator)
  ├── PR 93: 1b8a696 (Money: Currency Minor Units Resolver & Action Closure)
  └── PR 87: 912aa71 (Web: Bounded product picker & search workflow)
```

1. **PR 91 (`f3836ef`) Closure**:
   - `app/Support/Mobile/Contract/Revision3/OnlineSaleIntentProfile.php`
   - `app/Support/Mobile/Contract/Revision3/PaymentSettlementValidator.php`
   - `app/Support/Mobile/Contract/Revision3/Exceptions/IntentProfileValidationException.php`
   - Unit test suites: `OnlineSaleIntentProfileTest.php`, `PaymentSettlementValidatorTest.php`, `CrossLanguageParityTest.php`.
2. **PR 93 (`1b8a696`) Full Action Closure**:
   - `app/Services/Money/CurrencyMinorUnitsResolver.php`
   - `app/Domain/Accounting/Exceptions/BaseCurrencyLockedException.php`
   - Integration into sales actions: `CreateSalesInvoiceDraftAction`, `UpdateSalesInvoiceDraftAction`, `PostSalesInvoiceAction`, `PostCustomerPaymentAction`, `CreateQuotationAction`, `UpdateQuotationAction`, `CreateSalesReturnDraftAction`.
   - Integration into sales rules/helpers: `DraftInvoiceIntegrity`, `ReceiptPreview`, `SalesCorrectionAudit`, `SalesReturnAmounts`, `SalesDocumentRules`, `SalesProductSelection`, `MoneyValues`.
   - Integration into commands: `ImmediateSaleCommands`, `SalesInvoiceCommands`.
   - `AppServiceProvider` scoped registration: `$this->app->singleton(CurrencyMinorUnitsResolver::class, fn () => new CurrencyMinorUnitsResolver);`.
   - Test suite: `tests/Feature/Money/CurrencyMinorUnitQualificationTest.php`.
3. **PR 87 (`912aa7`) Domain Mapping (Avoiding UI Changes)**:
   - Extract **only** `SalesProductSelection::suggestedBasePrice(Product $product, ProductUnit $unit): ?BigDecimal`.
   - Consume in `SalesDocumentRules::price()`.
   - **Strictly exclude** migration `2026_11_11_100000_add_product_search_prefix_indexes.php`, Blade views, and frontend picker JS.
4. **Reconciled Overlap**:
   - In `SalesDocumentRules.php`: combine `suggestedBasePrice()` with `CurrencyMinorUnitsResolver` dynamic minor units resolution.

---

## 6. Retention Technical Preservation & Policy Hold

- **Current Technical Preservation**:
  - `mobile_event_receipts.original_canonical_bytes` preserves the exact immutable binary bytes submitted by the client (`longText` with `binary` charset).
  - No automatic pruning, truncation, or key modifications are introduced.
  - Payload digest verification (`sha256:<hex>`) verifies raw byte fidelity.
- **Production Policy Hold**:
  - Operational production data retention policies, off-host archival, and cryptographic envelope encryption remain on **HOLD**.
  - This hold does **not** block the disabled default-OFF qualification implementation.
  - This is documented as technical preservation of the existing schema, not a new approved production policy.

---

## 7. Minimal Default-OFF Implementation Files & Test Plan

### 7.1 Minimal Files for Implementation Slice
1. **Configuration**:
   `config/mobile.php`: `v2_enabled => env('MOBILE_V2_ENABLED', false)`, `v2_cash_slice_enabled => env('MOBILE_V2_CASH_SLICE_ENABLED', false)`.
2. **Capability Token**:
   `app/Support/Mobile/Receipt/NativeFinancialExecutionCapability.php`.
3. **Inseparable Action Boundary**:
   `app/Actions/Sales/ExecuteImmediateOnlineCashSaleAction.php`.
4. **Testing Adapter**:
   `tests/Support/QualifiedCashFinancialExecutionAdapter.php` (used strictly in test harness to qualify execution; production remains bound to `DenyFinancialExecutionAdapter`).
5. **Route Registration**:
   `routes/mobile-v2.php`: `POST /companies/{companyId}/sales/online-cash` bound to `MobileEventReceiptManager`.

### 7.2 Actionable Concurrency & Rollback Test Matrix (Disposable MariaDB)
- **T-01 Non-Root Reject**: Invocation outside `DB::transactionLevel() === 1` throws `IllegalStateException`.
- **T-02 Missing Capability Reject**: Invocation of `ExecuteImmediateOnlineCashSaleAction` without valid capability throws `SecurityException`.
- **T-03 Replay Capability Reject**: Attempting to consume an already-consumed capability throws `SecurityException`.
- **T-04 Two-Process Contention**: Concurrent workers with identical `(company_id, client_event_id)` converge under row lock: winner gets `outcome: 'created'`, contender gets `outcome: 'replayed'` with identical invoice/payment numbers and zero duplicate sequence leakage.
- **T-05 Lost-ACK Replay**: Re-submitting identical payload after simulated client disconnect returns exact cached result without re-executing.
- **T-06 Tampered Payload Conflict**: Submitting altered payload with same `client_event_id` returns HTTP 409 Conflict.
- **T-07 Full Rollback Atomicity**: Injected failure after FEFO allocation or sequence number reservation restores pre-event database fingerprint completely; zero leaked sequences or orphan stock movements.
- **T-08 Reconciliations**: All 6 core accounting reconciliations pass cleanly on post-event database.

---

## 8. Verification & Next Steps

- **Branch**: `t3/cash-slice-authority-research`
- **Working Tree**: Clean (`git status` clean).
- **Exact Commit**: `348749ee3d67d9871c6e5fc18f9b52ad81d02734`
- **Scope Discipline**: Source research and concrete structural authority design only. Zero DB mutations, zero financial runtime changes, zero children spawned.
- **Ready for Root Technical Boundary Review**.
