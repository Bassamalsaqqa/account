# DeepSeek-only consolidation ? source handoff

Baseline: `088893def0c4ee985bf670c8cc6d3caecaed7b79`. Branch: `fix/ai-deepseek-consolidation`.

Status: source implementation and focused verification complete; independent architect acceptance pending. This document supersedes earlier multi-provider product configuration directions for this bounded change. Protected Owner originals and historical acceptance/deployment records are preserved.

## Product and authoritative route

The Owner flow is **DeepSeek API key ? Test connection ? Start using AI**. Password confirmation and explicit text-use terms stay within the setup form. Saved keys remain encrypted and write-only. Saving or locally validating a key never labels it verified: only a successful bounded SDK response with token usage does so.

`ProviderCapabilityRegistry` accepts exactly `deepseek / deepseek-flash / global / text_structured`. `AiProviderRoutes` resolves only the active company's `text_connection`, subject to the company's allowlist, permissions, revocation and consent. Stored personal preferences and fallback records cannot override it. A stale explicitly supplied connection locator is refused; it never silently chooses another connection.

`DirectTextProvider` uses `DeepSeekSdkAdapter` exclusively. The request-local official Laravel AI SDK integration keeps `BoundedDeepSeekGateway`: fixed HTTPS endpoint, normal DNS, TLS verification, no redirects/proxy/tools/automatic retries, bounded response bodies, shared time allowance and conservative token budgeting. No SDK conversation tables, provider marketplace or second accounting backend is introduced.

The internal pricing/default references remain [DeepSeek published pricing](https://api-docs.deepseek.com/quick_start/pricing/) and the existing dated `DeepSeekDefaults` policy. Peak/cache-miss rates bound estimates; reported tokens and estimated/reserved costs do not establish billed cost. [Laravel's SDK documentation](https://laravel.com/framework/docs/13.x/ai-sdk) identifies its DeepSeek text integration; Accounting uses its existing isolated adapter rather than global provider credentials or SDK tools.

## Existing-company transition

An Owner explicitly selects **Use DeepSeek only**, confirms their password and supplies the mounted policy/connection revisions. The company-first authorization lock serializes the transition with credential and policy mutations. Repeated normalization at the current revision is a no-op; stale concurrent revisions are refused.

- The selected active company DeepSeek credential is reused. Otherwise, only a unique active company DeepSeek credential can be adopted through the explicit transition. Ambiguous credentials remain stored; the Owner enters a key in the same setup form. Revoked credentials never revive.
- Connection rows/ciphertext, historical provider records, user preferences, consents, audits and usage requests are retained. Transition never deletes tables or resets request counts, estimates or unknown reservations.
- Existing enabled/disabled state, allowed Sales/Purchase intents, report scopes, follow-up mode, retention and custom limits remain. Missing fields receive conservative defaults; explicitly disabled capabilities remain disabled.
- A disabled company can test its connection through an Owner-only `connection_test` purpose without enabling any business workflow. Enabling the company requires a separate deliberate Owner action and revision check; it preserves the existing scope.
- Active routing becomes one company connection; personal/fallback/commercial speech controls become inactive. Reviewed DeepSeek pricing is internal. Privacy terms change explicitly when moving from a different provider; matching DeepSeek privacy terms are retained.
- Credential identity/revision, privacy terms, purpose and authorized scopes determine consent. Budget/count changes, unrelated policy revisions and ordering of allowed scope lists do not invalidate consent. Relevant disclosure, route, credential or scope changes require renewed acceptance. Fresh authorization and policy-revision checks still refuse stale in-flight results.

There are no schema migrations and no edits to accounting, inventory or posting services.

## Retired runtime surfaces

Removed: `DirectProviderTransport`, `DirectSpeechProvider`, `GatewayTransport`, `GatewayIntentProvider`, `GatewaySpeechProvider`, the old `SalesAssistant`/`AssistantBudget`/`AssistantAccess` engine, obsolete Sales resolver and speech interfaces/policy/WAV helpers. The three legacy credential/audio controllers and named routes are removed; middleware returns content-free 404/no-store for their old URLs before CSRF/authentication or controller execution.

Removed: cloud WAV recorder/worklet utilities and unused Sales assistant partial. The current unsaved-form correction coordinator remains typed and explicit, preserving human edits, late-result refusal, cancellation and single acknowledgement. Native browser dictation remains in the global launcher; transcripts are editable and require Send. No application microphone consent popup, automatic recording or automatic AI submission is added.

Removed from active Settings/conversations: provider/model/region pickers, personal-key routing, per-user provider overrides, fallback selection, commercial speech selection, manual price fields and legacy advanced panels. Database attributes and protected permission catalog entries remain for historical compatibility, without creating an active provider path.

## Safe diagnostics and private production diagnosis

Issue [#35](https://github.com/Bassamalsaqqa/account/issues/35) was inspected before implementation. Production identity and request/configuration metadata were inspected read-only; no paid request was made. Detailed production metadata remains in ignored private custody and is excluded from this public record.

The launcher preserves understandable AR/EN categories for credentials/configuration/consent, HTTP authentication/credit/request/rate failures, timeout/outage, budget limits, stale context, unsupported instructions, invalid JSON and invalid accounting proposals. `AiDiagnostics` records only whitelisted category/stage and bounded HTTP status. Keys, prompts, raw responses, private accounting data and chained provider exception messages are excluded.

A previous failure classification cannot by itself establish token usage, invoice billing or the reason a provider rejected a credential. Local canonical reports and mocked SDK responses are explicitly distinct from live connectivity evidence. Live connectivity/actual billing are **NOT VERIFIED by this correction**.

## Verification and retired-test replacement

Final focused verification:

| Check | Actual result |
| --- | --- |
| Combined relevant MariaDB AI/setup/consent/unsaved/report subset | PASS ? 153 tests, 1,163 assertions |
| SDK-only transport and SDK verification unit subset | PASS ? 48 tests, 199 assertions |
| Extra unique-active-key transition regression | PASS ? 1 test, 5 assertions |
| Real concurrent budget tests | Six races passed in the seven-test run; foreign-company test's outdated refusal label was corrected and rerun independently: PASS ? 1 test, 16 assertions. All seven scenarios verified; no change to economic assertions. |
| Native browser dictation and typed correction Node subset | PASS ? 22 tests |
| Targeted PHPStan: AI application/infrastructure/Livewire, prune command, retired-route middleware | PASS ? no errors |
| Focused Pint, whitespace, retired-reference and sensitive-content review | PASS |
| Production frontend build | PASS ? Vite 7.3.6 |
| Mechanical UI detector | No findings |
| Actual loopback browser | Owner setup and fake authenticated SDK test; canonical AR/EN reports; mocked SDK Sales and Purchase handoffs remain unsaved; 390/768/1440 settings with no horizontal overflow; repeat/stop/cancel/denial speech callbacks simulated; no auto Send |

Tests use owned disposable MariaDB and mocked HTTP; no real provider, mail or financial production operations. Earlier failures during consolidation were corrected before publication. SDK test-helper naming was corrected so Pint cannot rename a private `test*` helper without its call sites. The final combined subset excludes obsolete commercial speech/provider-selection expectations; their replacements above test the retired entrypoints and current product behavior.

Obsolete tests for the retired gateway/cloud/multi-provider engine are removed together with their implementation. Their meaningful safeguards are represented by current tests: `DeepSeekOnlyTransportTest`/`DeepSeekSdkAdapterTest` (transport/security/status bounds), `RetiredAiEndpointsTest` (closed old routes), `DeepSeekSetupTest` (transition/preservation), `AssistantConsentScopeTest`/`AiGovernanceTest`/`AiSettingsSecurityTest` (consent/Owner/tenant limits), `AiRequestBudgetConcurrencyTest` (actual lock races), current transaction/schema/resolver/form tests (unsaved canonical handoff), and Unified report authorization/provider/semantic boundary tests. Native microphone and typed correction Node tests retain explicit Send, cancellation, denied permission, repeated recording and late-response checks.

Real browser evidence is retained privately under `.ai/consolidation-qa/`: owner setup, authenticated canonical report answers, mocked SDK unsaved Sales and Purchase handoffs, six AR/EN responsive settings captures and assistant captures. Browser speech callbacks are simulated for repeat/stop/cancel/denial verification; real microphone transcription quality and device/browser-provider availability remain **NOT VERIFIED**. Browser audit checks financial tables remain unchanged.

## Acceptance boundary

Submit the exact pushed source head as a draft PR for independent architectural review. No merge, deployment, production configuration/credential/budget changes or live paid requests are part of this task. Existing production remains at the verified baseline. Release requires independent acceptance and Owner authorization; any later live test also needs a usable credential and available existing budget, with historical reservations preserved.

## Effective changed-file inventory

```text
M	.env.example
M	PRODUCT.md
D	app/Application/AI/AiAudioLimits.php
A	app/Application/AI/AiDiagnostics.php
M	app/Application/AI/AiFormCorrections.php
M	app/Application/AI/AiGovernance.php
M	app/Application/AI/AiProviderDeadline.php
M	app/Application/AI/AiProviderRoutes.php
M	app/Application/AI/AiRequestBudget.php
D	app/Application/AI/AiSpeechMethods.php
D	app/Application/AI/AssistantAccess.php
D	app/Application/AI/AssistantBudget.php
M	app/Application/AI/AssistantQuestionPlanner.php
M	app/Application/AI/AssistantUnavailable.php
D	app/Application/AI/AudioToStructuredIntentProvider.php
M	app/Application/AI/DeepSeekQuickConnect.php
D	app/Application/AI/PcmWave.php
M	app/Application/AI/ProviderCapabilityRegistry.php
D	app/Application/AI/SalesAssistant.php
D	app/Application/AI/SalesProposalResolver.php
D	app/Application/AI/SpeechTranscriptionProvider.php
D	app/Application/AI/StructuredIntentProvider.php
M	app/Application/AI/TransactionAssistant.php
M	app/Console/Commands/PruneAssistantProposals.php
D	app/Http/Controllers/AiCredentialController.php
D	app/Http/Controllers/AssistantAudioController.php
D	app/Http/Controllers/SalesAssistantAudioController.php
M	app/Http/Middleware/Phase12Boundary.php
M	app/Infrastructure/AI/BoundedDeepSeekGateway.php
M	app/Infrastructure/AI/DeepSeekSdkAdapter.php
D	app/Infrastructure/AI/DirectProviderTransport.php
D	app/Infrastructure/AI/DirectSpeechProvider.php
M	app/Infrastructure/AI/DirectTextProvider.php
D	app/Infrastructure/AI/GatewayIntentProvider.php
D	app/Infrastructure/AI/GatewaySpeechProvider.php
D	app/Infrastructure/AI/GatewayTransport.php
M	app/Livewire/AI/AiSettings.php
M	app/Livewire/AI/AssistantLauncher.php
M	app/Livewire/AI/DeepSeekSetup.php
M	app/Providers/Phase12ServiceProvider.php
M	config/ai_assistant.php
A	docs/AI_DEEPSEEK_ONLY_CONSOLIDATION_HANDOFF_2026-10-12.md
D	resources/js/ai-audio-worklet.js
D	resources/js/ai-sale-recorder.js
D	resources/js/ai-wave.js
M	resources/js/app.js
M	resources/js/transaction-recorder.js
M	resources/lang/ar/ai_product.php
M	resources/lang/ar/assistant_v2.php
M	resources/lang/en/ai_product.php
M	resources/lang/en/assistant_v2.php
M	resources/views/livewire/ai/assistant-launcher.blade.php
M	resources/views/livewire/ai/deep-seek-setup.blade.php
M	resources/views/livewire/ai/settings.blade.php
M	resources/views/livewire/ai/unsaved-notice.blade.php
D	resources/views/livewire/pages/sales/partials/ai-assistant.blade.php
M	resources/views/livewire/pages/settings-index.blade.php
M	routes/web.php
A	tests/Feature/DeepSeekOnly/RetiredAiEndpointsTest.php
A	tests/Feature/DeepSeekProduct/AssistantDiagnosticTest.php
M	tests/Feature/DeepSeekProduct/DeepSeekSetupTest.php
M	tests/Feature/Phase12/AiCompanyControlsTest.php
M	tests/Feature/Phase12/AiGovernanceTest.php
M	tests/Feature/Phase12/AiRequestBudgetConcurrencyTest.php
M	tests/Feature/Phase12/AiRequestBudgetTest.php
M	tests/Feature/Phase12/AiSettingsSecurityTest.php
D	tests/Feature/Phase12/AiSpeechPolicyTest.php
D	tests/Feature/Phase12/AssistantAudioBoundaryTest.php
D	tests/Feature/Phase12/AssistantConcurrencyTest.php
M	tests/Feature/Phase12/AssistantConsentScopeTest.php
D	tests/Feature/Phase12/AssistantEconomicTest.php
D	tests/Feature/Phase12/AssistantSecurityTest.php
D	tests/Feature/Phase12/AssistantWorkflowTest.php
M	tests/Feature/Phase12/TenantSpeechCandidatesTest.php
M	tests/Feature/Phase12/TransactionAssistantTest.php
M	tests/Feature/UnifiedAssistant/QuestionAdditionalBoundaryTest.php
M	tests/Feature/UnifiedAssistant/QuestionGovernanceUiTest.php
M	tests/Feature/UnifiedAssistant/QuestionProviderBoundaryTest.php
M	tests/Feature/UnifiedAssistant/QuestionSemanticRoutingTest.php
D	tests/Frontend/ai-recorder.test.js
D	tests/Frontend/ai-wave.test.js
M	tests/Frontend/transaction-recorder.test.js
M	tests/Support/Phase12/AssistantFixtures.php
M	tests/Support/Phase12/FinalAiFixtures.php
D	tests/Support/Phase12/budget-race-worker.php
A	tests/Unit/DeepSeekOnlyTransportTest.php
M	tests/Unit/DeepSeekProduct/DeepSeekSdkAdapterTest.php
D	tests/Unit/Phase12/AiProviderDeadlineTest.php
D	tests/Unit/Phase12/DirectProviderContractTest.php
D	tests/Unit/Phase12/DirectProviderDnsPolicyTest.php
D	tests/Unit/Phase12/DirectProviderNativeTransportTest.php
D	tests/Unit/Phase12/ProviderCapabilityMatrixTest.php
```
