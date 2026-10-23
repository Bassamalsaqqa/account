# AGY review and independent Codex disposition

AGY reviewed source without tests, providers or Git mutations. The initial review timed out; its resumed conclusion returned four findings. This report is evidence, not acceptance. Codex independently checked each claim against source.

| Finding | Codex disposition |
|---|---|
| First-time setup loses open event because launcher is absent | Confirmed. Fixed Start Using with a full settings redirect plus bounded open flag; fresh launcher mount calls existing authorization. Rejected removing the layout gate: OFF must not mount. |
| Stale policy revision prevents setup retry | Confirmed. Failure refreshes displayed revisions; only a separate explicit owner retry can mutate. Added both-direction stale retry regression. |
| Disabled bookmarked AI handoff returns 403 | Deliberate fail-closed behavior for an unauthorized AI token URL, not an ordinary manual URL. Direct manual form routes remain available. Existing adopted forms detach only AI state and preserve user values. Retained denial and regression. |
| Restricted AI card branch unreachable | Redundant presentation branch, no access-control defect. Non-managers intentionally receive no AI controls or prompts. Rejected expanding visibility merely to show a locked card. |

Additional Codex corrections: secondary setup toggles rebuild layout; print-label link uses the existing print permission; empty worker smoke assertion replaced by guest workspace denial; obsolete latest-invoice test supplies required named customer; old connection test now forbids all dispatch while OFF and preserves validation for malformed legacy policy.

The original AGY report follows verbatim for review provenance; its proposed corrections are not all accepted.

### Release B.1 Bounded Final Independent Review Findings

Inspection of the worktree diff and surrounding authorization/lifecycle code (`AiGovernance`, `DeepSeekQuickConnect`, `DeepSeekSetup`, `SettingsIndex`, `AiUnsavedForm`, `GlobalSearch`, and layout templates) identified the following defects:

---

#### 1. Layout Mount State & Event Loss on Dynamic AI Connection
* **File & Line:** `resources/views/layouts/app.blade.php:447-449` and `app/Livewire/AI/DeepSeekSetup.php:168`
* **Severity:** High (Lifecycle / Event loss)
* **Finding:** In `layouts/app.blade.php`, `<livewire:ai.assistant-launcher>` was wrapped in `@if (\App\Application\AI\AiGovernance::isPresentationEligible($activeCompany))`. If a company starts with AI unconfigured or disabled, the launcher is omitted from initial layout DOM. When an owner completes quick connect via `testConnection()` on `settings.ai` (which updates state in-place without redirecting) and clicks "Start using assistant" (`startUsing()`), Livewire dispatches `ai:open-assistant` to the window. Because `assistant-launcher` was never mounted into the DOM, the `@ai-open-assistant.window` listener does not exist, the event is silently lost, and the assistant drawer fails to open until the user manually triggers a full browser reload. In addition, `AssistantLauncher::render()` already internally queries `isPresentationEligible` and renders an inert, hidden shell when ineligible.
* **Correction:** Remove the `@if (\App\Application\AI\AiGovernance::isPresentationEligible($activeCompany))` conditional wrapper around `<livewire:ai.assistant-launcher>` in `layouts/app.blade.php`, allowing the component's internal lifecycle and `render()` method to control visibility.

---

#### 2. Stale Policy Revision Lock in DeepSeek Setup
* **File & Line:** `app/Livewire/AI/DeepSeekSetup.php:125-158`
* **Severity:** Medium (Lifecycle / Concurrency lock)
* **Finding:** In `DeepSeekSetup::enableCompany` and `disableCompany`, `$this->refreshRevisions()` is placed only at the end of the `try` block before redirecting. If `DeepSeekQuickConnect::disableCompany` or `enableCompany` fails (such as throwing `AssistantUnavailable('proposal_stale')` or failing consent validation), the `catch` block catches the exception and sets `$this->message` without refreshing `$this->policyRevision`. Because `render()` does not update `$this->policyRevision`, the locked property remains stale in component state. Any subsequent retry by the user in the same session is guaranteed to fail with `proposal_stale`.
* **Correction:** Move `$this->refreshRevisions()` into a `finally` block or call it inside the `catch (AssistantUnavailable $e)` blocks in both `enableCompany` and `disableCompany`.

---

#### 3. Hard 403 Abort on Form URL Handoff when AI Disabled
* **File & Line:** `app/Livewire/Concerns/AiUnsavedForm.php:326-328`
* **Severity:** Medium (Lifecycle / Error handling)
* **Finding:** While `hydrateAiUnsavedForm` and `dehydrateAiUnsavedForm` gracefully handle turned-off AI via `$this->detachDisabledAiInput()` to preserve manual invoice/purchase form values, `mountAiInput` executes `abort(403, __('assistant_v2.error_disabled'))` if `! AiGovernance::isPresentationEligible($company)`. If a user navigates to an invoice or purchase creation page with a stale or bookmarked `?assistant={token}` query parameter after AI is disabled, the entire page crashes with HTTP 403 Forbidden rather than gracefully detaching the AI proposal and loading the standard manual form.
* **Correction:** Replace `abort(403, ...)` in `mountAiInput` with `return;` when `! AiGovernance::isPresentationEligible($company)` so the form proceeds with standard manual entry.

---

#### 4. Settings Overview AI Card Dead Condition & Missing Restricted State
* **File & Line:** `resources/views/livewire/pages/settings-index.blade.php:60-62, 164-181`
* **Severity:** Low (UI / Authorization consistency)
* **Finding:** The AI control card in `settings-index.blade.php` wraps the section with `@if ($hasCompany && $canManageAi)`, followed immediately by a nested `@if ($canManageAi)`. The corresponding `@else` at line 164 (which renders the locked card notice for unauthorized members, matching Taxes, Purchases, and Banking cards) is dead code because the outer `@if` already requires `$canManageAi`. Non-managing members see no card at all rather than the expected restricted status notice.
* **Correction:** Change the outer check at line 60 from `@if ($hasCompany && $canManageAi)` to `@if ($hasCompany)`.

---

### Scope & Review Limits
* **Inspection Scope:** Read-only inspection of worktree diff and surrounding code in `app/Application/AI`, `app/Livewire/AI`, `app/Livewire/Pages`, `app/Livewire/Concerns`, `app/Services/Search`, and layout Blade views.
* **Limits:** No tests were executed, no runtime services or providers were called, and no Git state or working tree files were modified during this review. Proprietary licensing constraints remained preserved.
