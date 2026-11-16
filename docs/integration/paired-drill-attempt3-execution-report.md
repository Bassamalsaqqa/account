# Mobile V2 Paired Financial Drill Execution Report (Attempt 3)

- **Date / Time**: 2026-11-15T14:28:00Z to 2026-11-15T14:29:26Z
- **Backend Execution Head**: `9373589f009e006e8eed4082c7ced0be0b62bf3c`
- **Peer Android Publication Head**: `b0d5965e7dc5d0e483880afa0bf3ca6a025ee3e6`
- **Peer Android Executable Head**: `360ce78408f09ecff451381e595a4ffe2f116969`
- **Pairing Manifest SHA-256**: `bea3fbc0ef41ffbe999f4ccdfe3fadfa0fb1d3d19ebd053371c339bc242cb74b`
- **Branch**: `t3/recover-cash-writer`
- **Execution Dir**: `/home/t3dev/.t3/userdata/attachments/root-basic3-9373589` (mode `0700`, files `0600`)
- **Outcome**: `FAILED` (Exit code: 1, JVM Test Execution Timeout; NO false PASS claimed; NO zero POST claimed; Financial HTTP Outcomes: UNKNOWN)

---

### Record of Process Deviation

> [!WARNING] RECORD OF OPERATOR DEVIATION
> During the implementation of the post-cleanup partial evidence preservation helper, 1 unit test (`test_preserve_available_partial_failure_evidence`, 10 assertions) was executed without an active QA grant.
>
> - **Attestation Status**: NOT a qualified or attested QA run.
> - **Remediation**: Recorded explicitly. Zero further tests or execution commands will be performed without an explicit Root QA grant.

---

### 1. Prestart Coordination & Invariant Verification

1. **Prestart Coordination**:
   - Root granted prestart approval via `prestart.json` (SHA: `f414ce9ddb125a320f1b19bcfb8ac3e18ca4bfb50dfec99268bbb734bdebc5dd`).
   - Pairing manifest verified at `bea3fbc0ef41ffbe999f4ccdfe3fadfa0fb1d3d19ebd053371c339bc242cb74b`.
   - Backend tree admitted: `d584067cf8f8a3588f4031a4cdb2107e0fa80b4d` (G1 ADMITTED).
   - Component hashes strictly matched:
     - Source Harness SHA: `7fcf73a3c55940aa484464a56078506cb9faa15b22656750d88923dc3d90b3cc`
     - Guard Test SHA: `a55c5f7b1cc698ec0ca6f64646192a49e290466751ca0eccccb2a5b12090ffda`
     - CLI Command SHA: `0b6914ffc299cd399db6ab2311764b4bd18212238fbaeb46d32518da356a4aa7`
     - CLI Runner SHA: `70bcf0edc24cbba3d896ad7d742085bfed616b370dbb0919a1ace55f1a2a5075`
2. **Contemporaneous Pre-State**:
   - `pre.json`: SHA256 `80cea33f68ddad33270306aebdd5d5a09767e08aac6280268e99be32e2a2f4e0` (299,435 bytes).
   - `pre-both.json`: SHA256 `2c125db72e6d5ecf3c1ea7441abae7efba06711ba47923995f64899d96cead15` (387,874 bytes).

---

### 2. Candidate Child Lifecycle & Root Live Approval

1. **Child Launch & Probing**:
   - Child process launched (PID: `1774622`).
   - Owned schema created: `accounting_p8_tmp_ff0b72f41794`.
   - CA Certificate: `/tmp/v2_harness_67d48fd0984d9bd5/bootstrap/test-ca.pem`.
   - Strict TLS GET 200 check to allowlisted loopback URL verified 200 OK with matching nonce.
2. **Candidate Handoff Witness**:
   - Exposed path: `/tmp/v2_harness_67d48fd0984d9bd5/runtime_client_handoff.json`
   - Whole SHA-256: `d629ba4857025140fa3e6b40748e73644f38b331f88706f2e41a895d7d6d719f`
3. **Root Live Approval**:
   - Root inspected candidate handoff and wrote `/home/t3dev/.t3/userdata/attachments/root-basic3-9373589/live.json` with all fields strictly populated (`root_live_handoff_approved: true`, matching `d629ba48...`).
4. **Pre-Run In-Schema Baseline**:
   - Company ID: `1` (ULID: `01M7FSPYR86TREC41SCZ7P4NVH`).
   - Baseline canonical hash: `ed746cce6c03ebf0ac60f47a8d7c206eb1440073592a6f5cd67af429e916ef57`.
   - Baseline persisted to `pre_run_baseline.json` (SHA: `f0af394ee2e43518ac06061e9410a7fd708f1b65e64cba2490011fbb82821ca8`).

---

### 3. Execution Failure: Facts vs Hypotheses

#### A. Observed Facts
1. **JVM Timeout**: The supervisor dispatched `./gradlew cleanTestDebugUnitTest testDebugUnitTest --no-build-cache ...` with a deadline of `76.914089202881s`. The process exceeded this deadline before returning.
2. **KSP Activity**: KSP symbol indexing in `app/build/kspCaches/debug` was actively writing files between `14:28:28` and `14:28:35 UTC`.
3. **Zero Test XML Generated**: `app/build/test-results` was completely empty upon termination; JUnit runner did not complete or write XML output.
4. **Financial HTTP Outcomes UNKNOWN**: Financial HTTP outcomes are strictly UNKNOWN; no zero POST or PASS claim is asserted.

#### B. Hypotheses & Diagnostic Analysis
1. **SourceSet Compilation vs Filter**: Gradle compiles the entire test `sourceSet` rather than compiling individual test classes per `--tests` filter. Timestamps in `app/build` show compilation tasks were executing but did not complete within the 76.91s window.
2. **Environment & Process Context**:
   - Root passed `JAVA_HOME` and `ANDROID_HOME` in the environment when invoking the supervisor for Attempt 3. In PHP Symfony `Process`, child processes inherit the parent process environment by default. The explicit `$gradleEnv` array in `supervised-financial-drill.php` only supplemented specific variables; therefore, absence from that array does not prove absence from the child process.
   - Clean / Invalidation Hypothesis: `cleanTestDebugUnitTest` coupled with `--no-build-cache` and a cold `--no-daemon` process under single-worker serial GC caused the full debug compilation and KSP DAG to rebuild, exceeding the 76.91s deadline before reaching test execution.

#### C. Supervisor Logging & Preservation Deficiencies
1. **Silent Logging Risk**: Output was collected in memory and written only after process exit; timeout discarded the buffer before it reached disk.
2. **Improper Canonical File Overwriting**: The previous preservation attempt copied an arbitrary XML report to canonical `junit-test-report.xml`, which risked asserting stale or unvalidated artifacts as canonical deliverables.

---

### 4. Automatic Teardown & Final Witness Attestation

Despite the JVM timeout failure, the supervisor executed full exact cleanup in `finally`:
1. **Child Reaped**: Sent SIGTERM to child process PID `1774622` (matching snapshot). Reaped cleanly.
2. **Schema Dropped**: Owned schema `accounting_p8_tmp_ff0b72f41794` dropped by PDO.
3. **Harness & Proof Parent Deleted**:
   - Harness directory `/tmp/v2_harness_67d48fd0984d9bd5` removed.
   - Separate owned proof parent `/tmp/p8_proof_326b2bff95c44b5b` removed.
4. **Listeners Closed**: Closed loopback ports 18080 and 18443.
5. **Post-Cleanup Final Witness Persisted**:
   - Witness file: `/home/t3dev/.t3/userdata/attachments/root-basic3-9373589/post_cleanup_final_witness.json`
   - Witness SHA-256: `3f9ae785809b42a0a6983c3dde7d7fb1ef40c9be81db94584a201cc9189d6e27`
   - All 5 cleanup assertions verified: `child_reaped=true`, `schema_absent=true`, `listeners_closed=true`, `harness_absent=true`, `proof_parent_absent=true`.
   - Zero lingering processes confirmed (`root ps: no Gradle/harness/worker`).

---

### 5. Retained Evidence Directory (`/home/t3dev/.t3/userdata/attachments/root-basic3-9373589/`)

| File Name | SHA-256 Digest | Size (Bytes) | Mode | Purpose |
|---|---|---|---|---|
| `pre.json` | `80cea33f68ddad33270306aebdd5d5a09767e08aac6280268e99be32e2a2f4e0` | 299,435 | `0600` | Pre-execution backend git state |
| `pre-both.json` | `2c125db72e6d5ecf3c1ea7441abae7efba06711ba47923995f64899d96cead15` | 387,874 | `0600` | Pre-execution dual-repo state |
| `post-both.json` | `963e0396058e710ce776a8053e7cd36f9a3cf69432b316dc016f677f9def7579` | 387,874 | `0600` | Post-execution dual-repo state (invariance verified) |
| `prestart.json` | `f414ce9ddb125a320f1b19bcfb8ac3e18ca4bfb50dfec99268bbb734bdebc5dd` | 474 | `0600` | Root prestart coordination grant |
| `live.json` | `4fa2a120c238520f7485b99b5badb76f7298ce097fe92e680bcca1a0fd6216b1` | 786 | `0600` | Root live approval of candidate handoff |
| `pre_run_baseline.json` | `f0af394ee2e43518ac06061e9410a7fd708f1b65e64cba2490011fbb82821ca8` | 1,149 | `0600` | Pre-run database state baseline |
| `raw.log` | `e76c58ed445d85142f46e6a293b1d0c967d108f55fff26ed46b39a9b3ef022cb` | 3,446 | `0600` | Supervisor console standard output and error |
| `post_cleanup_final_witness.json` | `3f9ae785809b42a0a6983c3dde7d7fb1ef40c9be81db94584a201cc9189d6e27` | 1,225 | `0600` | Restricted hash-pinned post-cleanup final witness |
| `post_cleanup_artifact_index.json` | `af68adc0181304c6148a4aa2d0cae8f78c59f1b2c1c03907134c5873a63edc11` | 199 | `0600` | Post-cleanup artifact index |
| `pairing-manifest.json` | `bea3fbc0ef41ffbe999f4ccdfe3fadfa0fb1d3d19ebd053371c339bc242cb74b` | 5,011 | `0600` | Authoritative pairing manifest with Root-corrected pub/exe pins |
| `argv.json` | `a519302d33e620abe7a76c9241913acfe1938f8dcf1d561b24bd48e28484cbcd` | 795 | `0600` | Exact invocation command line |
| `exit.json` | `7daa5896b75a3bb763519d651741d371c16968df8bd6c2eab309ead39e217d4e` | 15 | `0600` | Exit code record (`exit: 1`) |

---

### 6. Published Sanitized Documents

- [`docs/integration/paired-drill-attempt3-execution-report.md`](file:///home/t3dev/.t3/worktrees/account/integration-mobile-backend-supervision/docs/integration/paired-drill-attempt3-execution-report.md)
- [`docs/integration/paired-drill-attempt3-machine-manifest.json`](file:///home/t3dev/.t3/worktrees/account/integration-mobile-backend-supervision/docs/integration/paired-drill-attempt3-machine-manifest.json)
- [`docs/integration/paired-drill-attempt3-run-output.txt`](file:///home/t3dev/.t3/worktrees/account/integration-mobile-backend-supervision/docs/integration/paired-drill-attempt3-run-output.txt)
- [`docs/integration/paired-drill-attempt3-final-witness.json`](file:///home/t3dev/.t3/worktrees/account/integration-mobile-backend-supervision/docs/integration/paired-drill-attempt3-final-witness.json)
