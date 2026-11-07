# AGY Visual and Interface Verification Supplement — 2026-11-07

**Scope**: Qualification of previously omitted subsystem workflows:
1. Sales Return Correction Dialog
2. Purchase Return Correction Dialog
3. Expense Standalone Review
4. Payroll Standalone Reviews (Employee Advance, Salary Entry, Salary Payment)
5. Money Transfer Standalone Review

**Environment Context**:
- **Application Endpoint**: `http://127.0.0.1:8088` (PHP 8.4 dev server supervised by `tests/Support/ReleaseUi/serve.php`)
- **MariaDB Host**: `127.0.0.1:3342` (Huda MariaDB instance)
- **Active Owned Schema**: `accounting_p8_tmp_0b8dec967b82` (strictly owned and tracked via `DisposableMariaDbSchema`)
- **Dev User**: `dev_ui_tester_bae2ec82@example.test` (credentials custodied in private 0600 file)
- **Preview Browser**: T3 Preview browser via MCP (`t3-code`), verified closed on handback.
- **Safety Policy**: Strictly read-only qualification. **Zero final financial mutations executed through the browser**.

---

## 1. Subsystem Verification Matrix (Observed vs Unverified)

| Area | Route & Component | Viewport & Locale | Observed Behavioral Evidence | Verification Status |
|---|---|---|---|---|
| **Sales Return Correction Dialog** | `/returns/{publicId}?correction=edit`<br>`components/correction-preview.blade.php` | Desktop (1280×800) & Mobile (iPhone SE: 375×667)<br>AR (RTL) & EN (LTR) | - Opened via URL query param `?correction=edit`.<br>- Initial focus deterministically landed on `#correction-reason`.<br>- Empty submission rendered error paragraph `#correction-reason-error[role="alert"]`, with `aria-invalid="true"` and `aria-describedby="correction-reason-error"`.<br>- Valid reason submitted: focus advanced to `<h4 wire:key="correction-plan-heading" tabindex="-1">` without falling to body.<br>- Numeric amounts displayed in `<bdi dir="ltr">` with tabular alignment (`80.00 ILS`).<br>- Mobile width 343px inside 375px viewport; zero horizontal overflow (`scrollWidth: 375`, `clientWidth: 375`).<br>- Touch targets compute to 44px on mobile.<br>- Dialog dismiss/close verified.<br>- **Final mutation not executed**. | **VERIFIED (PASS)** |
| **Purchase Return Correction Dialog** | `/purchase-returns/{publicId}?correction=edit`<br>`components/correction-preview.blade.php` | Desktop (1280×800) & Mobile (375×667)<br>AR & EN | - Initial focus placed on `#correction-reason`.<br>- Valid reason submitted: focus advanced to `<h4 wire:key="correction-plan-heading" tabindex="-1">` (`Edit · PRT-2026-0001`).<br>- Tabular quantities and amounts strictly isolated in `<bdi dir="ltr" class="tabular-nums">2</bdi>` and `<bdi dir="ltr">100.00 ILS</bdi>`.<br>- Action button `#executeCorrection` present with 44px touch target (`min-h-11`).<br>- Zero horizontal overflow on mobile 375px.<br>- **Final mutation not executed**. | **VERIFIED (PASS)** |
| **Expense Standalone Review** | `/expenses/{publicId}`<br>`components/standalone-reversal-review.blade.php` | Desktop (1280×800) & Mobile (375×667)<br>AR (RTL) & EN (LTR) | - Triggered via `إلغاء المصروف` / `openReversalReview`.<br>- Dialog opened in `max-w-2xl` shell with description: `عكس المصروف وحركة حسابه...`.<br>- Reversal rows rendered with two-column `<dl>` layout: `EXP-2026-0001`, `75.00 ILS`.<br>- Amounts formatted inside `<bdi dir="ltr" class="tabular-nums">75.00</bdi>`.<br>- Empty reason submission produced `role="alert"` (`حقل reversal reason مطلوب.`); error focus automatically trapped to `#review-reason`.<br>- Mobile touch targets compute to `44px` on `#review-date`, `#review-reason`, and submit button.<br>- Dismiss via close button verified.<br>- **Final mutation not executed**. | **VERIFIED (PASS)** |
| **Payroll Review 1: Employee Advance (Blocked Dependency)** | `/employees/{publicId}`<br>`components/standalone-reversal-review.blade.php` | Desktop & Mobile<br>AR & EN | - Triggered on Employee Advances tab for `ADV-2026-0001` (allocated to `SAL-2026-0001`).<br>- Subsystem correctly detected active allocation: button disabled in table when remaining balance is 0.00.<br>- **Observed**: Advance reversal safely guarded against premature reversal when tied to downstream payroll. | **VERIFIED (PASS)** |
| **Payroll Review 2: Salary Entry (Blocked by Active Payment)** | `/employees/{publicId}`<br>`components/standalone-reversal-review.blade.php` | Desktop (1280×800)<br>EN & AR | - Triggered on Salary Entries tab via `Reverse Salary Entry` (`openReversalReview('salary', 1)`).<br>- Dialog rendered with description: `Reverse this salary and release its allocated advances. Active salary payments must be reversed first.`.<br>- Dependent allocations listed: advance `ADV-2026-0001` (`300.00 ILS`), payment `SLP-2026-0001` (`2,700.00 ILS`).<br>- Subsystem evaluated `reversalReview.blocked === true`; submit form suppressed and alert displayed: `This reversal is blocked by a check or an active dependent allocation. Resolve the related record first.`.<br>- Tabular numerals rendered in `<bdi dir="ltr" class="tabular-nums">`.<br>- Dismiss via close button verified.<br>- **Final mutation not executed**. | **VERIFIED (PASS)** |
| **Payroll Review 3: Salary Payment (Leaf Reversal Review)** | `/employees/{publicId}`<br>`components/standalone-reversal-review.blade.php` | Desktop (1280×800) & Mobile (375×667)<br>EN & AR | - Triggered on Employee Statement tab via `Reverse Payment` (`openReversalReview('salary_payment', 1)`).<br>- Dialog opened with description: `Reverse this salary payment and all its allocations. The linked salaries become payable again.`.<br>- Reversal rows detailed cash account (`Cash - ILS`), employee (`خالد عبد الله`), salary allocation `SAL-2026-0001` (`2,700.00 ILS`).<br>- Subsystem evaluated unblocked state: form rendered with `#review-date`, `#review-reason`, and 44px confirm button.<br>- Zero horizontal overflow on mobile.<br>- Dismiss via close button verified.<br>- **Final mutation not executed**. | **VERIFIED (PASS)** |
| **Money Transfer Standalone Review** | `/money/transfers/{publicId}`<br>`components/standalone-reversal-review.blade.php` | Desktop (1280×800) & Mobile (375×667)<br>EN & AR | - Triggered on Transfer Detail via `Reverse transfer` (`openReversalReview`).<br>- Dialog opened with description: `Return the source amount to its account and remove the destination amount from its account...`.<br>- Reversal rows display source account (`Cash - ILS`), destination account (`Horizon Commercial Bank`), and currency amounts (`100.00 ILS`).<br>- Numerics isolated in `<bdi dir="ltr" class="tabular-nums">100.00</bdi>`.<br>- Mobile layout verified: `reasonHeight: 44px`, `submitHeight: 44px`, `isOverflowing: false`.<br>- Dismiss via close button verified.<br>- **Final mutation not executed**. | **VERIFIED (PASS)** |

---

## 2. Detailed Technical Observations

### Focus Trapping & Error Management
- In all correction dialogs (`correction-preview.blade.php`), submission with missing required input updates the input with `aria-invalid="true"` and `aria-describedby="{id}-error"`. The accompanying error tag uses `role="alert"`.
- When advancing from reason entry to the preview plan, focus moves via `x-init="$nextTick(() => $el.focus({ preventScroll: true }))"` directly to `<h4 wire:key="correction-plan-heading" tabindex="-1">`. At no point in the transition does focus drop to `document.body`.
- In standalone reversal reviews (`standalone-reversal-review.blade.php`), submitting an invalid form triggers `x-init="$nextTick(() => document.getElementById('review-reason')?.focus())"`, deterministically redirecting user focus to the invalid field.

### Dependency Chain Guarding in Multi-Layer Reversals
- The qualification observed the payroll dependency hierarchy:
  $$\text{Employee Advance } (300) \longrightarrow \text{Salary Entry } (3000 - 300 = 2700) \longrightarrow \text{Salary Payment } (2700)$$
- **Salary Entry Review**: When opening reversal for `SAL-2026-0001`, the system detected `SLP-2026-0001` is active, automatically evaluated `blocked: true`, and displayed a descriptive resolution blocker alert without rendering the confirmation action.
- **Salary Payment Review**: When opening reversal for `SLP-2026-0001`, the leaf position was recognized as unblocked, presenting the date and reason inputs to reverse the payment and free the salary entry.

### Responsive Touch Targets & Tabular Numeric Alignment
- All form inputs (`#review-date`, `#review-reason`, `#correction-reason`, `#correction-date`) and submission buttons compute to `44px` height (`min-h-11`) on mobile viewports (375px width).
- Bounding boxes for modal shells on mobile (375px) measure `343px` width with zero horizontal window scrolling (`scrollWidth == clientWidth == 375`).
- Currency amounts and quantities across Arabic RTL and English LTR layouts are rendered within `<bdi dir="ltr" class="tabular-nums">`, preventing negative signs or currency codes from flipping or scrambling in bidirectional text rendering.

---

## 3. Retained Sandbox & Cleanup Execution

The verified environment has been left active on loopback for parent inspection:
- **Application URL**: `http://127.0.0.1:8088`
- **Supervisor Owner PID**: `15648` (`tests/Support/ReleaseUi/serve.php`)
- **Supervised Web Server PID**: `15663` (`php -S 127.0.0.1:8088`)
- **MariaDB Database**: `accounting_p8_tmp_0b8dec967b82` on `127.0.0.1:3342`
- **Private State Directory**: `/home/t3dev/.t3/userdata/antigravity-tmp/ae3fd7ec8360/run-BEs1qZ/release_ui_879d66b176a18739` (`0700` mode)
- **Dev User**: `dev_ui_tester_bae2ec82@example.test`

### Execution of Hardened Cleanup
To terminate the test web server, drop only the owned schema, and erase private temporary state, execute:
```bash
php tests/Support/ReleaseUi/cleanup.php
```
`cleanup.php` verifies the owner process identity via `/proc/15648/cmdline`, presents the session capability secret via `stop.signal`, triggers natural destruction via `$schema->drop()`, and safely unlinks only confirmed owned files.
