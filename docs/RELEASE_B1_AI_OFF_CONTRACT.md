# B.1 company AI-OFF contract

The company policy is the authority. Disabling AI serializes with dispatch authorization through the existing company/policy locks. A new authorization or dispatch-grant attempt after OFF commits is rejected. This applies to transaction preparation, corrections, questions, tool-intent turns and connection health checks.

A grant obtained before OFF commits may still send data, finish, and incur provider charges, including when sending begins after the switch. OFF is not transport cancellation, provider cancellation or a refund guarantee. Application provider calls release their authorization transactions before external I/O; no new lock is held across a provider call.

Late results require fresh current policy and revision checks before publication. OFF followed by ON changes the revision, so an old response or draft token cannot be revived. Manual form values remain available; AI never obtains authority to post financial or stock history from a late response. Existing canonical confirmation/posting boundaries remain unchanged.

Re-enable is a password-confirmed, company-bound owner action with permission and revision checks. The Owner clarified the product contract on 2026-10-24: affirmative text-consent gates are removed for setup, transactions and report questions. Provider/privacy disclosures remain visible and non-blocking, including when provider terms change. No acceptance records are fabricated. Historical consent records are retained for audit compatibility and do not authorize dispatch. Browser microphone permission is unchanged.

Deterministic tests interleave OFF with grant acquisition and fake provider responses, including OFF→ON and stale-token reads. They are controlled interleavings, not a claim of production network cancellation or multi-process load certification. Transaction-depth checks account for the test fixture's outer transaction; source inspection verifies the production I/O call sites.
