# Final source assessment

AGY source review is evidence only; Codex independently inspected the combined diff and surrounding authorization/tenant boundaries. Corrections include the two npm lock entries and the narrowly scoped invoice/test corrections described in the handback. The AGY recommendation to remove unique failure logs was narrowed; only duplicate/superseded outputs are removed.

### B.1 Acceptance Source Review

**Verified HEAD**: `2283efec9a08471b89f8e0e8c626262f21fdc06b`  
**Baseline**: `87e1a4bae08feddd3964f91559684613612768cf`  
**Mode**: Read-only source inspection (no edits, no tests run, no provider calls).

---

### Audit Findings by Domain

#### 1. AI Policy Transitions (Revision & Authorization)
- **Status**: **No defects found**.
- `app/Application/AI/AiGovernance.php#L48-L86`: `isPresentationEligible` provides a cheap non-authorizing short-circuit for global OFF and disabled company configs; `authorizeUse` retains strict locked DB policy enforcement and rejects disabled tenants (`disabled`).
- `app/Application/AI/DeepSeekQuickConnect.php#L133-L160` & `app/Livewire/Pages/SettingsIndex.php#L569-L633`: `disableCompany` and `enableCompany` verify company ownership (`isOwner`), check `ai.settings.manage`, confirm password, enforce optimistic locking (`revision`), bump revision on transition, and record audit events. Stale revisions reject cleanly (`proposal_stale`).

#### 2. Company Isolation
- **Status**: **No defects found**.
- `app/Services/Search/GlobalSearch.php#L73-L80` & `app/Livewire/GlobalSearch.php#L137-L144`: Merged membership verification into a single query verifying active `CompanyUser` and active `Company`.
- `app/Livewire/Pages/Products/BarcodeLabels.php#L56-L95`: Locks `lockedCompanyId` from active context; queries restrict strictly to that company ID.

#### 3. Disabled-AI Unsaved Manual-Form Preservation
- **Status**: **No defects found**.
- `app/Livewire/Concerns/AiUnsavedForm.php#L355-L400`: In `hydrateAiUnsavedForm` and `dehydrateAiUnsavedForm`, when presentation eligibility fails mid-lifecycle, `detachDisabledAiInput()` clears the session handoff token and resets AI badges without purging dirty user inputs (`$lines`, amounts, etc.), allowing manual completion and save.

#### 4. Product Save & Add Another (Queue & Duplicates)
- **Status**: **No defects found**.
- `app/Livewire/Pages/Products/ProductForm.php#L244-L265` & `resources/views/livewire/pages/products/product-form.blade.php#L1-L71`: Single-entry `save` handler updates existing products if already created (`isEditing = true`), preventing duplicates on re-submission. Client-side `saving` flag and `enqueueDocumentOperation($wire, 'save', [true])` prevent concurrent double-clicks. On completion, full redirect (`navigate: false`) re-mounts clean state with flashed confirmation.

#### 5. Payment Source Navigation Tenant & Authorization
- **Status**: **No defects found**.
- `app/Livewire/Pages/Purchasing/PaymentForm.php#L77-L133`, `app/Livewire/Pages/Purchasing/PaymentDetail.php#L177-L184`, and Sales counterparts: Source IDs and numbers (`sourcePurchasePublicId`, `sourceInvoicePublicId`) are `#[Locked]`. Navigation links render only if the entity belongs to the active tenant and the user holds server-side capability (`purchasing.purchase.view` / `sales.invoice.view`).

---



AGY migration diagnosis: ### Diagnosis

The failure in [`DisposableMariaDbSchema::migrate`](file:///C:/Users/bassa/AppData/Local/Codex/google-worker-workspaces/r_5b78e9902b6de712cd2bc2e6/task_19f8f1bf6c2f45ec9ff5ad9d4038b88d/worktree/tests/Support/DisposableMariaDbSchema.php#L179-L185) was caused by running the complete migration suite cold against a freshly created MariaDB database on Windows, exceeding Symfony `Process`’s 120-second timeout limit. Windows NTFS metadata and process spawning overhead during 100+ DDL operations account for the latency. [`DisposableMariaDbSchema::__destruct`](file:///C:/Users/bassa/AppData/Local/Codex/google-worker-workspaces/r_5b78e9902b6de712cd2bc2e6/task_19f8f1bf6c2f45ec9ff5ad9d4038b88d/worktree/tests/Support/DisposableMariaDbSchema.php#L45-L52) successfully cleaned the synthetic schema without test failures.

### Local Harness Solution

Without altering source code, dependencies, or security assertions, configure the local external runner wrapper to provision the initial primary schema before invoking `composer qa`:

1. **Instantiate Schema**: Call [`DisposableMariaDbSchema::create()`](file:///C:/Users/bassa/AppData/Local/Codex/google-worker-workspaces/r_5b78e9902b6de712cd2bc2e6/task_19f8f1bf6c2f45ec9ff5ad9d4038b88d/worktree/tests/Support/DisposableMariaDbSchema.php#L54-L64) to obtain a verified, owned schema instance.
2. **Execute Initial Migration (600s)**:
   ```php
   (new Symfony\Component\Process\Process(
       [PHP_BINARY, 'artisan', 'migrate', '--database=mysql', '--force'],
       base_path(),
       $schema->environment()
   ))->setTimeout(600)->mustRun();
   ```
3. **Provision Permissions**: Run `app(CompanyRoleService::class)->ensurePermissionsExist();` via child process using `$schema->environment()`.
4. **Execute `composer qa` Unfiltered**: Run `composer qa` directly in an environment augmented with `$schema->environment()`. Cryptographic schema ownership nonces (`PHASE8_DISPOSABLE_DB_PROOF` / `NONCE`) and tenant database isolation remain fully enforced.
5. **Nested Helpers**: Retain the 120-second timeout for nested test helpers, as existing evidence indicates they do not run the full cold initial suite.

### Defect Confirmation

Confirmed: zero application, schema, or migration defects are inferred from this environmental Windows timeout.

Codex disposition: captured migration timing supports an environmental startup timeout; NTFS/process overhead is an inference, not proven causation. The local initial migration process received 600 seconds. A local private-method invocation error was corrected in the harness before the final gate. The Phase12 preservation test now targets only its two additive migrations, with current schema counts and strict fingerprints/reconciliations preserved.
