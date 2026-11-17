# Focused web release candidate — supervisor evidence

Prepared directly from deployed `f8a0cf7`, without merging any stack into main. Executable source `085d9196704c8fa36c709724363abd62861b51d2` combines the isolated PR96/98 patches and strengthened canonical lock-contention test orchestration. The following commits only publish evidence. The JSON records input SHAs, exact executable blobs, commands, counts, raw/published log digests and schema cleanup checks.

Root executed **143 tests / 780 assertions** (130 deletion-class cases including inherited cases, three concurrency controls, ten report selectors), plus **2 populated canonical statement tests / 12 assertions**, in separate fresh owned MariaDB schemas. No count is an independent reviewer rerun. PHPStan over all app and explicitly changed test/worker PHP files, scoped Pint and production build passed. No source maps.

The concurrency holder pauses after the canonical action acquires its Company lock; it does not start an artificial outer transaction. Restricted processlist observation sees the exact contender Execute/Query pending Company FOR UPDATE for at least one second. A missing-holder negative control must reject. INNODB_LOCK_WAITS access was unavailable; do not broaden this into global lock-graph, HTTP/browser or production proof. Earlier failed observer attempts are retained in the purchase supervisor-followup evidence.

The worker's original 132-case combined count is consistent with 130 deletion cases plus the original two concurrency cases. A prior review/supervisor inference that the concurrency file was omitted was incorrect; discovery resolved the attribution.

This is **not deployment acceptance**. Production remains unchanged. No migrations, mobile finance activation, new grants, main merge or cleanup of historical/private artifacts. PR97 picker and PR87 UX work remain separate. Required off-host recovery, final review, preservation and authenticated live verification have not been executed by this evidence. The generic client purchase-modal error has not been reproduced or attributed.
