# Bounded Source Diagnosis: B.1 Verification Failures

Both reported failures are **Obsolete Test Expectations** resulting from subsequent canonical feature merges, not accounting regressions or tenant leaks.

---

### Finding 1: Unupdated Administrator Default Permission Set
- **File & Line**: `tests/Feature/Phase1/CompanyDomainActionsTest.php:465-476`
- **Classification**: Obsolete Test Expectation (Medium Severity)
- **Root Cause**:
  In `app/Services/Tenancy/CompanyRoleService.php:231` (introduced in commit `9903e51`), `seedCompanyRoles()` was updated so that default `Administrator` roles require explicit Owner delegation for AI governance and do not receive `AI_PERMISSIONS` by default:
  ```php
  $adminRole->syncPermissions($catalogPermissions->reject(
      fn ($permission) => in_array($permission->name, [...self::PHASE9_PERMISSIONS, ...self::AI_PERMISSIONS], true)
  ));
  ```
  However, `CompanyDomainActionsTest.php:465` was never updated to reflect this exclusion; it calculates:
  ```php
  $adminDefaults = array_values(array_diff(CompanyRoleService::PERMISSIONS, CompanyRoleService::PHASE9_PERMISSIONS));
  ```
  Because `$adminDefaults` still includes `AI_PERMISSIONS`, the loops at lines 466–468 and the count assertion at line 476 fail against seeded Administrator roles.
- **Minimal Proposed Fix**:
  In `tests/Feature/Phase1/CompanyDomainActionsTest.php:465`, include `CompanyRoleService::AI_PERMISSIONS` in the diff calculation:
  ```php
  $adminDefaults = array_values(array_diff(
      CompanyRoleService::PERMISSIONS,
      [...CompanyRoleService::PHASE9_PERMISSIONS, ...CompanyRoleService::AI_PERMISSIONS]
  ));
  ```
  and assert `assertNotContains($aiPerm, $adminPermNames)` for `CompanyRoleService::AI_PERMISSIONS`.

---

### Finding 2: Stale Company Switch Redirect Destination
- **File & Line**: `tests/Feature/Phase1/CompanyLanguagePolicyTest.php:163, 181`
- **Classification**: Obsolete Test Expectation (Medium Severity)
- **Root Cause**:
  In commit `6dd3106`, `routes/web.php:139` changed the CSRF company switcher return destination from `redirect()->route('settings.index')` to `redirect()->intended(route('dashboard'))`.
  `tests/Feature/ProductExperience/NavigationRedirectionTest.php:85` verifies this new canonical default (`assertRedirect(route('dashboard'))`). However, legacy lines 163 and 181 in `CompanyLanguagePolicyTest.php` still assert the pre-dashboard redirect:
  ```php
  163: $switchResponse->assertRedirect(route('settings.index'));
  ...
  181: $switchBackResponse->assertRedirect(route('settings.index'));
  ```
- **Minimal Proposed Fix**:
  In `tests/Feature/Phase1/CompanyLanguagePolicyTest.php`, update lines 163 and 181 to assert the canonical redirect:
  ```php
  $switchResponse->assertRedirect(route('dashboard'));
  ...
  $switchBackResponse->assertRedirect(route('dashboard'));
  ```
  *(Or alternatively pass `'url.intended' => route('settings.index')` in `withSession(...)` if the test intention is specifically checking return to the previous page).*

---

### Verification Limits
- This inspection was conducted purely in bounded read-only mode.
- Per user instructions, no tests or runtime services were executed, and no assertions or passes are claimed.