# UI/API parity and shared DeepSeek HTTP boundary

Baseline: accepted A4 `3846f6366e82122b5460ca485533c10457e0e830`. Separate continuation source; accepted A1-A4 heads unchanged. Independent acceptance pending.

## Older review findings

The Livewire launcher calls the same application services as the guarded versioned report API. Moving the browser through another HTTP/authentication round trip is unnecessary for parity. New `AssistantUiApiParityTest` executes actual Livewire and `/api/v1/companies/{company}/assistant/turn` with persisted authenticated database sessions and CSRF. Arabic/English named customer balances, monthly comparison and deterministic low stock produce identical DTOs, canonical values and source links. Full financial/master-data fingerprints remain unchanged; each independent planned turn uses one mocked SDK exchange and stock uses zero. API responses remain private/no-store and omit internal authorization scope.

Run new parity cases with `--filter test_ui_api_parity`: **6 tests, 66 assertions, PASS** after the shared boundary correction. The class inherits canonical synthetic fixtures; filtering prevents unnecessary inherited acceptance-suite repetition. Existing API security tests cover revocation, tenancy and session boundaries. The API remains report-only: this does not claim API Sales/Purchase handoff or native-device authentication. Existing web UNSAVED forms remain intact.

`DeepSeekHttpBoundary` centralizes request-local bounded cURL sinks, native DNS, verified TLS, fixed base URL, disabled proxy/redirects/retries, nonrewindable request bodies, deadlines/timeouts and bounded response reads. Laravel AI SDK still owns model request generation/parsing. Free Laravel HTTP `/user/balance` retains its separate balance validator and 65,536-byte limit; SDK responses retain 131,072 bytes. No custom Chat Completions client or second model transport.

Focused balance/SDK/shared-boundary unit verification: **72 tests, 243 assertions, PASS**. Valid/empty balance contracts, status classification, malformed/oversized/stalled responses, sink independence and request options. Removed a worker test that constructed its own nonrewindable stream instead of exercising application behavior. Pint and targeted PHPStan: PASS. Later integrated checks are recorded in the release checkpoint.

No migration, dependency, economic writer, production setting, budget/history change, paid call, PR/main merge or deployment. Host-level egress isolation remains an operational requirement; a fixed HTTPS hostname is not IP isolation.
