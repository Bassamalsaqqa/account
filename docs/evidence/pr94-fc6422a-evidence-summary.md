# Historical PR #94 supervisor evidence

This report concerns ancestor `fc6422a1f245301abd042d15a8867f25fc367de5` only. It does not attest PR #95 executable source or its qualification races.

The JSON is a byte-for-byte copy of the original supervisor report, SHA-256 `7e8e4c35ffc43cbd04e28fab9e9fe747196ee55a3c068b320a19a4c6b6e187d0`. The original private artifact and logs remain untouched. All four original log digests were verified when restoring this report. The report contains no credentials or tokens; its public-copy digest therefore equals its original digest.

The supervisor recorded 36 tests / 194 assertions without live DB access; reverse four-class execution 41 / 206 on owned schema `accounting_p8_tmp_53e519180ddc`; random seed 20261113 execution 41 / 206 on `accounting_p8_tmp_e7526be4f306`. The supervisor recorded cleanup and metadata absence for both schemas, scoped level-6 PHPStan and changed-file Pint. These are historical results, not new executions by this worker.

The previous combined report incorrectly attributed PR #95-only classes and counts to this ancestor. Those claims are withdrawn. No complete global lock DAG or deterministic five-barrier qualification is established by this report.

Frozen manifest SHA-256: `ba75352349e552f53b02ad7159570171f88943dc26e5bdf2a4b013386b0d0659`. The historical `p0-pr94-refusal-proof.json` remains byte-identical.
