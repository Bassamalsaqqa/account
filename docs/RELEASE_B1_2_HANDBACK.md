# Accounting B.1.2 Corrective Release Candidate Handback

**Baseline Commit:** `aa6ee432251b78fedf3affb4d049b3d611bf816e`  
**Reviewed Source HEAD:** `261ede072d8c1d3de9f7b13e47b2a5b88304bcc7`  
**Source Tree Hash:** `466713719e94af39e7b67e898b54ed5777242f3b`  
**Branch:** `fix/release-b1-2-product-names-omnibox`  
**Pull Request:** [Draft PR #63](https://github.com/Bassamalsaqqa/account/pull/63)  
**Status:** Verification complete; conditional recommendation for merge and production deployment by Codex. Not a deployed-release claim.

---

## 1. Corrections and Independent Code Inspection

### Product Names Flexibility & Contract Enforcement
- **Flexible Bounded Contract:** Arabic-only, English-only, and bilingual product names are accepted; both blank names are rejected after trimming. Valid nonblank zero names (`"0"`) are preserved across models, services, search, labels, and catalogs.
- **Runtime Path Enforcement:**
  - Main create/edit form (`app/Livewire/Pages/Products/ProductForm.php`) enforces `required_without:name_en` and `required_without:name_ar`, trims inputs, converts empty strings to `null`, and authorizes with company context.
  - Quick product creation (`app/Services/Inventory/QuickProductCreation.php`) normalizes blank strings to `null`, validates nonblank requirements, preserves tenant permissions, and maintains deterministic idempotency hashing.
  - Service layer (`app/Services/Inventory/ProductCatalogService.php`) validates creation input before starting its transaction and validates partial updates against locked persisted state in `updateProduct`. Crucially, partial updates validate resulting names against locked persisted state, ensuring a product cannot be stripped of its last remaining name via partial payload.
  - Both supplied names remain separate and distinct in storage; the system never fabricates synthetic translations or copies English into Arabic.
- **Display, Search, and Catalog Fallbacks:**
  - `Product::name(?string $locale = null)` uses locale-aware fallback (in English preferring English, falling back to Arabic; in Arabic preferring Arabic, falling back to English).
  - Presentation views across inventory, expiry center, stock movements, product details, catalog composer, and barcode labels use nonblank-aware fallback via `DocumentDescription::choose()`.
  - Global search (`app/Services/Search/GlobalSearch.php`) searches both Arabic and English names, ordering by `COALESCE(name_ar, name_en) ASC`.
- **Document Snapshot Immutability:**
  - Historical snapshots in `purchase_lines` and `sales_invoice_lines` capture the product name state at document creation/posting time. Posted lines remain strictly immutable when master product names are later updated.

### Omnibox Lifecycle Stabilization (Issue #62)
- **Defect Root Cause:** Late server responses from `this.$wire.close()` fired Alpine's `isOpen` watcher while a subsequent `openModal()` was awaiting authorization, incrementing `this.generation` and aborting the in-flight open. Furthermore, checking `$wire.isOpen` inside `isModalVisible()` caused a locator contradiction where `<dialog open>` was open natively but inner content was hidden via CSS (`display: none`).
- **Correction:** Serialized server lifecycle calls through an instance-local promise in `resources/js/global-search.js`. A new open is dispatched only after prior close calls settle, and receives fresh server authorization; cancelled queued opens never dispatch. Local dismissal remains immediate. The watcher still dismisses fresh server resets after opening. Inner content visibility is tied directly to `clientOpen`, eliminating locator mismatches. Server authorization, tenant binding, and accessible focus restoration remain intact.

---

## 2. Migration and Schema Review

- **Forward Migration ([database/migrations/2026_10_26_100000_allow_nullable_product_name_ar.php](../database/migrations/2026_10_26_100000_allow_nullable_product_name_ar.php)):**
  - `up()` makes `products.name_ar`, `purchase_lines.product_name_ar`, and `purchase_return_lines.product_name_ar` nullable.
  - Sales snapshots (`quotation_lines.product_name_ar`, `sales_invoice_lines.product_name_ar`, `sales_return_lines.product_name_ar`) were already nullable in baseline schema.
  - No existing data or immutable historical transaction lines are rewritten.
- **Rollback Safety & Data Integrity:**
  - MariaDB roundtrip tests verify that rollback checks every affected table directly via query builder (including soft-deleted products).
  - If any row with `name_ar IS NULL` exists in `products`, `purchase_lines`, or `purchase_return_lines`, `down()` throws a descriptive `RuntimeException` and halts DDL execution.
  - The system refuses to fabricate Arabic translations or corrupt historical records to force a rollback. If rollback is required after English-only records exist, the compatible B.1.2 application code must be retained alongside an explicitly reviewed recovery plan.

---

## 3. Authoritative Verification Summary

| Verification Gate | Command / Target | Result | Notes |
|---|---|---|---|
| **Authoritative Full QA** | `PHP 8.4 composer.phar qa` (`qa-20`) | **PASS** (exit 0) | **3,471 passed, 41,812 assertions**, 4 warnings. Duration: 6,403.40s. |
| **PHPStan Static Analysis** | `vendor/bin/phpstan analyse` | **PASS** (exit 0) | 651 files analyzed, 0 errors. |
| **Pint Code Style** | `vendor/bin/pint --test` | **PASS** (exit 0) | 1,195 files checked, 0 style violations. |
| **JavaScript Test Suite** | `node --test tests/JavaScript/*.test.mjs` | **PASS** (exit 0) | 44 tests passed, 0 failures. Includes 12 Omnibox lifecycle tests, 5 added in B.1.2. |
| **Production Frontend Build** | `npx vite build` | **PASS** (exit 0) | 310 modules transformed cleanly. Assets bundled in `public/build/`. |
| **Security Audits** | `composer.phar audit` & `npm audit` | **PASS** (exit 0) | 0 advisories, 0 abandoned packages, 0 npm vulnerabilities. |
| **Reconciliations (Pre/Post Full QA)** | 6 Canonical Reconciliations | **PASS** (exit 0) | Accounting, Inventory, Sales, Money, Payables, Phase7 healthy with 0 violations. |
| **Economic Fingerprints** | SHA256 of 14 economic tables | **IDENTICAL** | All recorded economic table fingerprints unchanged. |
| **Schema Hygiene** | Test runner cleanup | **CLEAN** | Primary test row counts unchanged; temporary disposable schemas removed in `finally`. |

### Warning Audit
The 4 warnings recorded during full QA occurred in `Tests\Unit\ProductExperience\DeploymentScriptContractTest` (`! refuses unsafe symlink... → symlink(): Permission denied`). These are expected operating system permission warnings emitted when unprivileged PHP attempts symbolic link creation on Windows; the contract tests caught the PHP warnings and asserted fail-closed behavior cleanly.

### Local Browser & Accessibility Receipt
- Validated across Arabic RTL and English LTR: 5 rapid open/escape cycles each, outside click dismissal, Tab key navigation contained within native `<dialog>`, and accessible focus return to trigger without inert/hidden ancestors.
- Verified creation of English-only product through main form, ensuring Arabic name remained blank without errors and displayed correctly in Arabic search and detail views.
- Mobile viewport verified at 390px requested width (measured 417 CSS px due to browser scaling) with 0 horizontal overflow.

---

## 4. Verification Archive & Custody

- **Public Evidence Package:** `docs/evidence/release-b1-2/verification.zip` (92,407 bytes, SHA256: `40e0f39b2b8987350c5bf3bff9833e5aea65000d40f6898eda8ef74ceed4091d`).
- **Sanitization Verified:** Scanned all members for absolute filesystem paths, developer identities, local paths, API keys, credentials, and customer data. All raw logs and unredacted receipts remain strictly in private storage.
- **Repository Integrity:** All 40 pre-existing private untracked documentation and specification files remain untouched with original hashes intact. The original translation stash remains in verified private local custody.

---

## 5. Deployment Recommendation & Operator Constraints

- **Local Recommendation:** Codex independently reviewed the source and corrected this handback against the actual implementation. The recorded automated and local browser gates pass. Production deployment is authorized by the Owner, conditional on the remaining recovery, runtime, migration and preservation gates below.
- **Remaining Production Gates for Codex:**
  1. Verify the final documentation-only PR head, then execute the Owner-authorized merge of PR #63 to `main`.
  2. Perform pre-deployment backup, off-host verification, and isolated recovery test.
  3. Verify production MariaDB version compatibility before applying migration.
  4. Acquire deployment lock and place application into fail-closed maintenance mode.
  5. Apply strictly allowlisted single forward migration `2026_10_26_100000_allow_nullable_product_name_ar`.
  6. Deploy pre-compiled frontend assets and warm caches.
  7. Run post-deployment reconciliations and smoke probes before releasing maintenance mode.
  8. Coordinate Owner visual acceptance / UAT after deployment.

Historical attempts remain in the evidence archive: the preflight on `50a9c9f` was deliberately stopped before PHP tests to correct the zero-name fallback gap (exit 1); a focused attempt omitted required catalog fixture flags (exit 2), corrected before the passing 40-test rerun. Neither is a passing release gate. The authoritative full gate is `qa-20` on `261ede0`.

The local browser receipt does not certify physical devices, screen readers or real network-offline behavior. Owner UAT follows deployment. No production test products/transactions or provider requests are permitted.
