# Hermetic Android Prewarm & Critical QA Execution Report

- **Date / Time**: 2026-11-15T12:37:17Z
- **Grant**: Hermetic Android Prewarm / Critical QA Grant (b0/360 peer packet `9840f47ba9a67f3995b00a218fdeea88e229963f2db649b429456cfbfc89b1e9`)
- **Backend Current HEAD**: `8f5a2ef166e4f820be90ba12551e356c3417f638`
- **Android Publication HEAD**: `b0d5965e7dc5d0e483880afa0bf3ca6a025ee3e6`
- **Android Executable HEAD**: `360ce78408f09ecff451381e595a4ffe2f116969`
- **Android Worktree**: `/home/t3dev/.t3/worktrees/small-trader-android/t3-7ac8b73d-abb5d6e52b3b3cf9`
- **Diff vs 360 Executable HEAD**: Strictly verified; diff touches ONLY the 2 docs files:
  - `docs/evidence/pr24-bootstrap-tax-status-recovery/QA-EVIDENCE.md`
  - `docs/evidence/pr24-bootstrap-tax-status-recovery/SOURCE_RELEASE_MANIFEST.json`
- **Source Verification**: All 201 source files in Android worktree matched peer packet `sourceSha256` 100%. All 15 backend source files matched canonical hashes 100%.

---

### Execution Command & Environment

```bash
JAVA_HOME="/home/t3dev/.local/share/android-toolchain/jdk-21.0.13+9" \
ANDROID_HOME="/home/t3dev/.local/share/android-toolchain/sdk" \
PATH="/home/t3dev/.local/share/android-toolchain/jdk-21.0.13+9/bin:/usr/local/bin:/usr/bin:/bin" \
./gradlew \
  :app:testDebugUnitTest \
  --tests com.example.data.mobile.onlinecash.AndroidV2OnlineCashClientMockWebServerTest \
  --tests com.example.ui.staff.sale.StaffOnlineSaleViewModelTest.testPaymentUnverifiedHold_checkPaymentStatus_postedNullDigest_neverConfirmedHoldKept \
  --tests com.example.ui.staff.sale.StaffOnlineSaleViewModelTest.testPaymentUnverifiedHold_checkPaymentStatus_postedWrongDigest_neverConfirmedHoldKept \
  --tests com.example.ui.staff.sale.StaffOnlineSaleViewModelTest.testPaymentUnverifiedHold_checkPaymentStatus_postedMatchingDigest_succeedsConfirmedFinancialSuccess \
  --tests com.example.ui.staff.sale.StaffOnlineSaleViewModelTest.testPaymentUnverifiedHold_checkPaymentStatus_unknownNullableDigest_remainsUnknownHold \
  --tests com.example.ui.staff.sale.StaffOnlineSaleViewModelTest.testPaymentUnverifiedHold_checkPaymentStatus_recoversEvenWhenNewSaleAuthorityInvalid \
  --tests com.example.ui.staff.sale.StaffOnlineSaleViewModelTest.testPaymentUnverifiedHold_checkPaymentStatus_wrongSessionScope_refusesZeroHttpPreservesHold \
  --tests com.example.data.mobile.readclient.ReadClientRevision2BoundariesTest.testBootstrapDefaultStrict12RejectsCurrencyMinorUnits \
  --tests com.example.data.mobile.readclient.ReadClientRevision2BoundariesTest.testBootstrapRequestedStrict13RequiresCurrencyMinorUnits \
  --max-workers=1 \
  -Dorg.gradle.workers.max=1 \
  -Dorg.gradle.jvmargs="-XX:ActiveProcessorCount=2 -XX:+UseSerialGC -Xmx1536m" \
  -Pkotlin.compiler.execution.strategy=in-process \
  --no-daemon
```

- **Execution Duration**: 12s
- **Exit Code**: 0

---

### Execution Results

- **Total Tests Executed**: 46
- **Total Tests Passed**: 46
- **Failures**: 0
- **Errors**: 0
- **Skipped**: 0
- **Suite Breakdown**:
  - `com.example.data.mobile.onlinecash.AndroidV2OnlineCashClientMockWebServerTest`: 38 executed, 38 passed, 0 failures, 0 errors
  - `com.example.ui.staff.sale.StaffOnlineSaleViewModelTest`: 6 executed, 6 passed, 0 failures, 0 errors
  - `com.example.data.mobile.readclient.ReadClientRevision2BoundariesTest`: 2 executed, 2 passed, 0 failures, 0 errors

---

### Persistent Artifact Retention (Outside Ephemeral Directory)

Artifacts retained in permanent private directory (`chmod 0700`, files `chmod 0600`):
`/home/t3dev/.t3/userdata/attachments/hermetic_android_prewarm_phase8/`
- `raw-run-output.txt`
- `test-results-xml/TEST-com.example.data.mobile.onlinecash.AndroidV2OnlineCashClientMockWebServerTest.xml`
- `test-results-xml/TEST-com.example.ui.staff.sale.StaffOnlineSaleViewModelTest.xml`
- `test-results-xml/TEST-com.example.data.mobile.readclient.ReadClientRevision2BoundariesTest.xml`
- `attestation.json` (SHA-256: `eee838189c31db851240258092f5efb8cba84c7e60a957e6048751a4821c3ec3`)

---

### Post-Execution Integrity & Release

- Backend Working Tree: Clean (`8f5a2ef166e4f820be90ba12551e356c3417f638`)
- Android Working Tree: Clean (`b0d5965e7dc5d0e483880afa0bf3ca6a025ee3e6`)
- QA Lease Status: RELEASED
- Active Running Processes: Zero PHPUnit / Gradle / artisan jobs.
