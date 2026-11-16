<?php

declare(strict_types=1);

namespace Tests\Support\Harness;

use App\Support\Mobile\Contract\CanonicalJsonSerializer;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Owned Test Transport Proxy for Mobile V2 Supervised Paired Integration Testing.
 *
 * Sits strictly between Caddy TLS proxy (port 18443) and the supervised PHP HTTP backend (port 18081).
 * Mediates on loopback (default 127.0.0.1:18080) and forwards to upstream backend (127.0.0.1:18081).
 *
 * Architectural Guarantees:
 * 1. Default-deny configuration: requires verified private config array with exact Root authorization scope.
 * 2. Strict loopback binding: binds ONLY to 127.0.0.1 on fixed loopback ports.
 * 3. Exact route & ULID matching: matches only POST /api/mobile/v2/companies/{ulid}/sales/online-cash
 *    with exact expected company public ULID (01...) and exact target client_event_id.
 * 4. Verified DB commit check: queries MariaDB (3342) via read-only PDO connection, asserting @@port=3342,
 *    DATABASE()=disposable_schema, verifying receipt status in ('posted', 'completed'), matching company_id,
 *    positive invoice and payment IDs, canonical table joins, and exact matching payload_digest.
 * 5. Atomic witness artifact: produces lost_ack_suppression_witness.json under an owned 0700 output directory
 *    via O_CREAT | O_EXCL with 0600 permissions and UID validation before abruptly closing the client socket.
 * 6. Hardened socket handling: enforces monotonic per-request and overall deadlines, byte bounds (16KB headers,
 *    1MB body), Content-Length framing (rejects chunked / ambiguous encoding), and complete writes.
 * 7. Process lifecycle: checks parent PID liveness, dispatches pcntl signals, and safely closes all resources in finally.
 */
class TestTransportProxy
{
    public const DEFAULT_LISTEN_PORT = 18080;
    public const DEFAULT_UPSTREAM_PORT = 18081;
    public const DEFAULT_DB_PORT = 3342;
    public const DEFAULT_DB_HOST = '127.0.0.1';
    public const MAX_HEADER_BYTES = 16384;      // 16 KB
    public const MAX_BODY_BYTES = 1048576;      // 1 MB
    public const MAX_REQUEST_DURATION_SECONDS = 10.0;
    public const READ_TIMEOUT_SECONDS = 2.0;
    public const WITNESS_FILENAME = 'lost_ack_suppression_witness.json';

    private string $listenHost;
    private int $listenPort;
    private string $upstreamHost;
    private int $upstreamPort;
    private array $targetConfig;
    private int $ownerPid;
    private string $ttlExpiresAt;
    private bool $suppressionExecuted = false;
    private bool $running = false;
    /** @var resource|null */
    private $serverSocket = null;

    /**
     * @param array{
     *     listen_port?: int,
     *     upstream_port?: int,
     *     target_event_id: string,
     *     target_schema: string,
     *     target_company_id: int,
     *     target_company_public_id: string,
     *     expected_digest: string,
     *     target_nonce_hash: string,
     *     witness_path: string,
     *     db_host?: string,
     *     db_port?: int,
     *     db_user?: string,
     *     db_password?: string,
     *     owner_pid: int,
     *     ttl_expires_at: string
     * } $config
     */
    public function __construct(array $config)
    {
        $this->validateConfig($config);

        $this->listenHost = '127.0.0.1';
        $this->listenPort = self::DEFAULT_LISTEN_PORT;
        $this->upstreamHost = '127.0.0.1';
        $this->upstreamPort = self::DEFAULT_UPSTREAM_PORT;
        $this->ownerPid = (int) $config['owner_pid'];
        $this->ttlExpiresAt = (string) $config['ttl_expires_at'];
        $this->targetConfig = $config;
    }

    private function validateConfig(array $config): void
    {
        // 1. Strict fixed ports & hosts enforcement: reject arbitrary ports and bypasses
        if (isset($config['listen_port']) && (int) $config['listen_port'] !== self::DEFAULT_LISTEN_PORT) {
            throw new InvalidArgumentException('TestTransportProxy: listen_port must be fixed to '.self::DEFAULT_LISTEN_PORT.', arbitrary ports refused.');
        }
        if (isset($config['upstream_port']) && (int) $config['upstream_port'] !== self::DEFAULT_UPSTREAM_PORT) {
            throw new InvalidArgumentException('TestTransportProxy: upstream_port must be fixed to '.self::DEFAULT_UPSTREAM_PORT.', arbitrary ports refused.');
        }
        if (isset($config['db_host']) && (string) $config['db_host'] !== self::DEFAULT_DB_HOST) {
            throw new InvalidArgumentException("TestTransportProxy: db_host must be fixed to '".self::DEFAULT_DB_HOST."', arbitrary hosts refused.");
        }
        if (isset($config['db_port']) && (int) $config['db_port'] !== self::DEFAULT_DB_PORT) {
            throw new InvalidArgumentException('TestTransportProxy: db_port must be fixed to '.self::DEFAULT_DB_PORT.', arbitrary ports refused.');
        }

        // 2. Mandatory owner_pid: must be a positive integer and currently alive
        if (! isset($config['owner_pid']) || ! is_int($config['owner_pid']) || (int) $config['owner_pid'] <= 0) {
            throw new InvalidArgumentException('TestTransportProxy: owner_pid is mandatory and must be a positive integer.');
        }
        $ownerPid = (int) $config['owner_pid'];
        if (function_exists('posix_kill') && ! @posix_kill($ownerPid, 0)) {
            throw new RuntimeException("TestTransportProxy: owner process {$ownerPid} is not alive.");
        }

        // 3. Mandatory target_event_id: UUID format
        if (empty($config['target_event_id']) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $config['target_event_id'])) {
            throw new InvalidArgumentException("TestTransportProxy: target_event_id must be a valid UUID, got '".($config['target_event_id'] ?? '')."'.");
        }

        // 4. Mandatory target_schema: disposable pattern
        if (empty($config['target_schema']) || ! preg_match('/^accounting_p8_tmp_[0-9a-f]{12}$/', (string) $config['target_schema'])) {
            throw new InvalidArgumentException("TestTransportProxy: target_schema must match disposable naming pattern, got '".($config['target_schema'] ?? '')."'.");
        }

        // 5. Mandatory target_company_id & target_company_public_id
        if (empty($config['target_company_id']) || (int) $config['target_company_id'] <= 0) {
            throw new InvalidArgumentException('TestTransportProxy: target_company_id must be a positive integer.');
        }
        if (empty($config['target_company_public_id']) || ! preg_match('/^01[0-9A-HJ-KM-NP-TV-Z]{24}$/', (string) $config['target_company_public_id'])) {
            throw new InvalidArgumentException("TestTransportProxy: target_company_public_id must be a valid ULID, got '".($config['target_company_public_id'] ?? '')."'.");
        }

        // 6. Mandatory expected_digest: strict 64-hex lowercase SHA-256
        if (empty($config['expected_digest']) || ! preg_match('/^[0-9a-f]{64}$/', (string) $config['expected_digest'])) {
            throw new InvalidArgumentException('TestTransportProxy: expected_digest is mandatory and must be a 64-hex lowercase SHA-256 string.');
        }

        // 7. Mandatory target_nonce_hash: strict 64-hex lowercase SHA-256
        if (empty($config['target_nonce_hash']) || ! preg_match('/^[0-9a-f]{64}$/', (string) $config['target_nonce_hash'])) {
            throw new InvalidArgumentException('TestTransportProxy: target_nonce_hash is mandatory and must be a 64-hex lowercase SHA-256 string.');
        }

        // 8. Mandatory ttl_expires_at: unexpired ISO 8601
        if (empty($config['ttl_expires_at']) || ! is_string($config['ttl_expires_at'])) {
            throw new InvalidArgumentException('TestTransportProxy: ttl_expires_at is mandatory.');
        }
        $exp = strtotime($config['ttl_expires_at']);
        if ($exp === false || $exp <= time()) {
            throw new InvalidArgumentException("TestTransportProxy: ttl_expires_at is expired or invalid ('{$config['ttl_expires_at']}').");
        }

        // 9. Mandatory witness_path
        if (empty($config['witness_path'])) {
            throw new InvalidArgumentException('TestTransportProxy: witness_path is mandatory.');
        }
        if (basename((string) $config['witness_path']) !== self::WITNESS_FILENAME) {
            throw new InvalidArgumentException("TestTransportProxy: witness_path basename must be '".self::WITNESS_FILENAME."', got '".basename((string) $config['witness_path'])."'.");
        }
    }

    public function getListenPort(): int
    {
        return $this->listenPort;
    }

    public function getUpstreamPort(): int
    {
        return $this->upstreamPort;
    }

    public function isSuppressionExecuted(): bool
    {
        return $this->suppressionExecuted;
    }

    /**
     * Start the proxy server loop.
     */
    public function run(int $maxIterations = 0): void
    {
        $context = stream_context_create([
            'socket' => [
                'so_reuseport' => 0,
                'so_reuseaddr' => 1,
            ],
        ]);

        $serverUri = "tcp://{$this->listenHost}:{$this->listenPort}";
        $errno = 0;
        $errstr = '';
        $this->serverSocket = @stream_socket_server(
            $serverUri,
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );

        if ($this->serverSocket === false) {
            throw new RuntimeException("TestTransportProxy failed to bind on {$serverUri}: [{$errno}] {$errstr}");
        }

        stream_set_blocking($this->serverSocket, false);
        $this->running = true;
        $iteration = 0;

        $serverStart = hrtime(true) / 1e9;
        $serverDeadlineSeconds = max(1.0, (float) (strtotime($this->ttlExpiresAt) - time()));

        try {
            while ($this->running) {
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }

                // Liveness check for owning parent PID
                if (function_exists('posix_kill') && ! @posix_kill($this->ownerPid, 0)) {
                    break;
                }

                // Overall monotonic deadline check
                if (((hrtime(true) / 1e9) - $serverStart) >= $serverDeadlineSeconds) {
                    break;
                }

                if ($maxIterations > 0 && $iteration >= $maxIterations) {
                    break;
                }

                $read = [$this->serverSocket];
                $write = null;
                $except = null;
                $changed = @stream_select($read, $write, $except, 1, 0);

                if ($changed === false || $changed === 0) {
                    continue;
                }

                $clientSocket = @stream_socket_accept($this->serverSocket, 1.0);
                if ($clientSocket === false) {
                    continue;
                }

                $iteration++;
                $this->handleClientConnection($clientSocket);
            }
        } finally {
            $this->stop();
        }
    }

    public function stop(): void
    {
        $this->running = false;
        if ($this->serverSocket !== null && is_resource($this->serverSocket)) {
            @fclose($this->serverSocket);
            $this->serverSocket = null;
        }
    }

    /**
     * Handles an incoming client TCP connection.
     *
     * @param resource $client
     */
    private function handleClientConnection($client): void
    {
        $upstream = null;
        try {
            stream_set_blocking($client, true);
            $deadline = (hrtime(true) / 1e9) + self::MAX_REQUEST_DURATION_SECONDS;

            // 1. Read request headers with bounds and deadline
            $rawHeaders = '';
            while ((hrtime(true) / 1e9) < $deadline) {
                $line = @fgets($client, 4096);
                if ($line === false) {
                    break;
                }
                $rawHeaders .= $line;
                if (strlen($rawHeaders) > self::MAX_HEADER_BYTES) {
                    $this->sendHttpError($client, 431, 'Request Header Fields Too Large');

                    return;
                }
                if ($line === "\r\n" || $line === "\n") {
                    break;
                }
            }

            if ($rawHeaders === '') {
                return;
            }

            // Parse method, path, and headers
            $headerLines = explode("\r\n", trim($rawHeaders));
            $requestLine = array_shift($headerLines) ?? '';
            $reqParts = explode(' ', $requestLine);
            if (count($reqParts) < 2) {
                $this->sendHttpError($client, 400, 'Bad Request');

                return;
            }

            $method = strtoupper($reqParts[0]);
            $path = $reqParts[1];

            $contentLengthValues = [];
            $transferEncodingPresent = false;
            $isChunked = false;

            foreach ($headerLines as $hLine) {
                $colonPos = strpos($hLine, ':');
                if ($colonPos !== false) {
                    $name = strtolower(trim(substr($hLine, 0, $colonPos)));
                    $val = trim(substr($hLine, $colonPos + 1));
                    if ($name === 'content-length') {
                        $contentLengthValues[] = $val;
                    }
                    if ($name === 'transfer-encoding') {
                        $transferEncodingPresent = true;
                        if (str_contains(strtolower($val), 'chunked')) {
                            $isChunked = true;
                        }
                    }
                }
            }

            // RFC 7230 request smuggling prevention: reject duplicate or conflicting Content-Length
            if (count($contentLengthValues) > 1) {
                $firstCl = $contentLengthValues[0];
                foreach ($contentLengthValues as $clVal) {
                    if ($clVal !== $firstCl) {
                        $this->sendHttpError($client, 400, 'Conflicting Content-Length Headers');

                        return;
                    }
                }
            }

            // Ambiguous framing: both Content-Length and Transfer-Encoding
            if ($transferEncodingPresent && ! empty($contentLengthValues)) {
                $this->sendHttpError($client, 400, 'Ambiguous Framing: Content-Length and Transfer-Encoding Both Present');

                return;
            }

            // Reject chunked transfer encoding
            if ($isChunked || $transferEncodingPresent) {
                $this->sendHttpError($client, 400, 'Chunked Transfer-Encoding Not Supported');

                return;
            }

            $contentLength = 0;
            if (! empty($contentLengthValues)) {
                $clRaw = trim($contentLengthValues[0]);
                if (! preg_match('/^\d+$/', $clRaw)) {
                    $this->sendHttpError($client, 400, 'Invalid Content-Length Header');

                    return;
                }
                $contentLength = (int) $clRaw;
            }

            if ($contentLength < 0) {
                $this->sendHttpError($client, 400, 'Negative Content-Length');

                return;
            }

            if ($contentLength > self::MAX_BODY_BYTES) {
                $this->sendHttpError($client, 413, 'Payload Too Large');

                return;
            }

            // 2. Read request body if Content-Length > 0
            $body = '';
            if ($contentLength > 0) {
                $bytesRemaining = $contentLength;
                while ($bytesRemaining > 0 && (hrtime(true) / 1e9) < $deadline) {
                    $chunkSize = min($bytesRemaining, 8192);
                    $chunk = @fread($client, $chunkSize);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $body .= $chunk;
                    $bytesRemaining -= strlen($chunk);
                }

                if (strlen($body) !== $contentLength) {
                    $this->sendHttpError($client, 400, 'Incomplete Body');

                    return;
                }
            }

            $isTarget = $this->isTargetRequest($method, $path, $body);
            $probeName = $this->getProbeNameIfMatching($method, $path, $body);

            // Pre-Live Verification & Route Gating:
            // Allow ONLY exact GET /_harness/readiness (+ optional hex nonce) prior to verified live Root approval.
            // Strictly fail closed (403 Forbidden) for ALL economic and other non-readiness routes until fresh live approval is verified.
            $isReadiness = self::isReadinessRequest($method, $path);
            if (! $isReadiness) {
                $liveApprovalPath = (string) ($this->targetConfig['root_live_approval_path'] ?? '');
                if ($liveApprovalPath === '' || ! is_file($liveApprovalPath)) {
                    $this->sendHttpError($client, 403, 'Forbidden: Live Root Approval Required Prior to Non-Readiness Traffic');

                    return;
                }

                try {
                    SupervisedPairedFinancialDrill::verifyLiveRootApproval(
                        $liveApprovalPath,
                        (string) ($this->targetConfig['handoff_path'] ?? ''),
                        (string) ($this->targetConfig['handoff_sha256'] ?? ''),
                        (string) ($this->targetConfig['backend_head'] ?? ''),
                        (string) ($this->targetConfig['android_publication_head'] ?? ''),
                        (string) ($this->targetConfig['android_executable_head'] ?? ''),
                        (string) ($this->targetConfig['manifest_sha256'] ?? ''),
                        (string) ($this->targetConfig['target_schema'] ?? ''),
                        (string) ($this->targetConfig['scope'] ?? SupervisedPairedFinancialDrill::REQUIRED_ADVANCED_SCOPE)
                    );
                } catch (\Throwable $e) {
                    $this->sendHttpError($client, 403, 'Forbidden: Live Root Approval Verification Failed: '.$e->getMessage());

                    return;
                }
            }

            // Capture pre-probe snapshot if this is a predeclared rollback probe
            $preProbeSnapshot = null;
            $probePdo = null;
            if ($probeName !== null) {
                $probePdo = $this->createReadOnlyPdo();
                if ($probePdo === null) {
                    $this->sendHttpError($client, 500, 'Internal Error: Probe Observer DB Connection Failed');

                    return;
                }
                $preProbeSnapshot = DisposableFinancialPostRunInspector::captureRollbackProbeSnapshot(
                    $probePdo,
                    (int) ($this->targetConfig['target_company_id'] ?? 0),
                    (string) ($this->targetConfig['target_schema'] ?? '')
                );
            }

            // 3. Forward request to upstream backend (127.0.0.1:18081)
            $upstreamUri = "tcp://{$this->upstreamHost}:{$this->upstreamPort}";
            $upstreamErrno = 0;
            $upstreamErrstr = '';
            $upstream = @stream_socket_client($upstreamUri, $upstreamErrno, $upstreamErrstr, 3.0);

            if ($upstream === false) {
                $this->sendHttpError($client, 502, "Bad Gateway: Upstream Connect Failed ({$upstreamErrno})");

                return;
            }

            stream_set_blocking($upstream, true);
            stream_set_timeout($upstream, (int) self::READ_TIMEOUT_SECONDS);

            // Reconstruct exact request and write to upstream
            $fullWireRequest = trim($rawHeaders) . "\r\n\r\n" . $body;
            if (! $this->writeAll($upstream, $fullWireRequest, $deadline)) {
                $this->sendHttpError($client, 502, 'Bad Gateway: Upstream Write Failed');

                return;
            }

            // 4. Drain bounded response from upstream
            $upstreamResponse = '';
            $maxResponseBytes = self::MAX_HEADER_BYTES + self::MAX_BODY_BYTES;
            while (! feof($upstream) && (hrtime(true) / 1e9) < $deadline) {
                $chunk = @fread($upstream, 8192);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $upstreamResponse .= $chunk;
                if (strlen($upstreamResponse) > $maxResponseBytes) {
                    $this->sendHttpError($client, 502, 'Bad Gateway: Upstream Response Exceeded Byte Limit');

                    return;
                }
            }

            // 5. Handle Target Request with Single-Response Drop
            if ($isTarget && ! $this->suppressionExecuted) {
                $responseStatus = 0;
                if (preg_match('#^HTTP/\d\.\d\s+(\d{3})#', $upstreamResponse, $m)) {
                    $responseStatus = (int) $m[1];
                }

                if ($responseStatus === 201) {
                    // Enforce independent live Root approval verification BEFORE executing fault suppression
                    $liveApprovalPath = (string) ($this->targetConfig['root_live_approval_path'] ?? '');
                    if ($liveApprovalPath === '') {
                        $this->sendHttpError($client, 500, 'Internal Error: Missing Root Live Approval Path');

                        return;
                    }

                    try {
                        SupervisedPairedFinancialDrill::verifyLiveRootApproval(
                            $liveApprovalPath,
                            (string) ($this->targetConfig['handoff_path'] ?? ''),
                            (string) ($this->targetConfig['handoff_sha256'] ?? ''),
                            (string) ($this->targetConfig['backend_head'] ?? ''),
                            (string) ($this->targetConfig['android_publication_head'] ?? ''),
                            (string) ($this->targetConfig['android_executable_head'] ?? ''),
                            (string) ($this->targetConfig['manifest_sha256'] ?? ''),
                            (string) ($this->targetConfig['target_schema'] ?? ''),
                            (string) ($this->targetConfig['scope'] ?? SupervisedPairedFinancialDrill::REQUIRED_ADVANCED_SCOPE)
                        );
                    } catch (\Throwable $e) {
                        $this->sendHttpError($client, 500, 'Internal Error: Live Root Approval Verification Failed: '.$e->getMessage());

                        return;
                    }

                    // Verify commit directly in disposable schema via read-only PDO
                    $commitInfo = $this->verifyCommitInDatabase();

                    if ($commitInfo === null) {
                        // Critical: fail closed if commit cannot be verified!
                        $this->sendHttpError($client, 500, 'Internal Error: Pre-Suppression Commit Verification Failed');

                        return;
                    }

                    // Write atomic witness artifact
                    $this->createWitnessArtifact($commitInfo);

                    // Mark suppression executed
                    $this->suppressionExecuted = true;

                    // Abruptly close client TCP socket without sending any bytes back
                    if (is_resource($client)) {
                        @fclose($client);
                    }

                    return;
                }
            }

            // Handle negative probe observer after completed upstream response
            if ($probeName !== null && $preProbeSnapshot !== null && $probePdo !== null) {
                $responseStatus = 0;
                if (preg_match('#^HTTP/\d\.\d\s+(\d{3})#', $upstreamResponse, $m)) {
                    $responseStatus = (int) $m[1];
                }
                $expectedStatus = match ($probeName) {
                    'permission_denial' => 403,
                    'insufficient_stock' => 422,
                    'controlled_fault_after_posting' => 500,
                    default => 0,
                };
                if ($responseStatus !== $expectedStatus) {
                    $this->sendHttpError($client, 500, "Internal Error: Rollback probe {$probeName} unexpected HTTP status {$responseStatus}, expected {$expectedStatus}");

                    return;
                }

                $postProbeSnapshot = DisposableFinancialPostRunInspector::captureRollbackProbeSnapshot(
                    $probePdo,
                    (int) ($this->targetConfig['target_company_id'] ?? 0),
                    (string) ($this->targetConfig['target_schema'] ?? '')
                );

                $zeroDeltaRes = DisposableFinancialPostRunInspector::assertRollbackZeroDelta($preProbeSnapshot, $postProbeSnapshot);
                if (! ($zeroDeltaRes['is_zero_delta'] ?? false)) {
                    $this->sendHttpError($client, 500, "Internal Error: Rollback probe {$probeName} economic delta is not zero");

                    return;
                }

                // Validate owned child_bootstrap_config.json artifact before recon execution
                $bootstrapConfigFile = (string) ($this->targetConfig['child_bootstrap_config_path'] ?? ($this->targetConfig['credentials_file'] ?? ''));
                if ($bootstrapConfigFile === '' || ! is_file($bootstrapConfigFile) || is_link($bootstrapConfigFile)) {
                    $this->sendHttpError($client, 500, "Internal Error: Missing or invalid bootstrap config file for rollback recon: {$bootstrapConfigFile}");

                    return;
                }
                $bPerms = fileperms($bootstrapConfigFile) & 0777;
                if ($bPerms !== 0600) {
                    $this->sendHttpError($client, 500, sprintf("Internal Error: Insecure bootstrap config file perms %04o", $bPerms));

                    return;
                }

                // Explicit isolated inspector environment
                $inspectorEnv = [
                    'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
                    'HOME' => getenv('HOME') ?: '',
                    'APP_ENV' => 'testing',
                    'MOBILE_V2_HARNESS_ENABLE' => '1',
                    'PHASE8_ALLOW_DISPOSABLE_DB' => '1',
                    'PHASE8_TEST_DB_HOST' => (string) ($this->targetConfig['db_host'] ?? '127.0.0.1'),
                    'PHASE8_TEST_DB_PORT' => (string) ($this->targetConfig['db_port'] ?? '3342'),
                    'PHASE8_TEST_DB_USERNAME' => (string) ($this->targetConfig['db_user'] ?? 'root'),
                    'PHASE8_TEST_DB_PASSWORD' => (string) ($this->targetConfig['db_password'] ?? ''),
                    'PHASE8_DISPOSABLE_DB_PROOF' => (string) ($this->targetConfig['proof_path'] ?? ''),
                    'PHASE8_DISPOSABLE_DB_NONCE' => (string) ($this->targetConfig['proof_nonce'] ?? ''),
                ];
                if (($this->targetConfig['db_password'] ?? '') === '') {
                    $inspectorEnv['PHASE8_TEST_DB_EMPTY_PASSWORD'] = '1';
                }

                // Finite remaining deadline bound
                $remTimeout = max(1.0, min(15.0, $deadline - (hrtime(true) / 1e9)));
                $repoRoot = (string) ($this->targetConfig['repo_root'] ?? dirname(__DIR__, 3));
                $reconCmd = [
                    PHP_BINARY,
                    $repoRoot . '/tests/Support/Harness/financial-inspector-cli.php',
                    '--reconcile',
                    '--credentials-file=' . $bootstrapConfigFile,
                    '--schema=' . (string) ($this->targetConfig['target_schema'] ?? ''),
                    '--company-id=' . (int) ($this->targetConfig['target_company_id'] ?? 0),
                ];
                $reconProc = new \Symfony\Component\Process\Process($reconCmd, $repoRoot, $inspectorEnv, null, $remTimeout);
                $reconProc->run();

                $reconStdout = $reconProc->getOutput();
                $reconStderr = $reconProc->getErrorOutput();
                $reconExitCode = $reconProc->getExitCode();

                if (! $reconProc->isSuccessful() || $reconExitCode !== 0) {
                    $this->sendHttpError($client, 500, "Internal Error: Rollback probe {$probeName} reconciliation execution failed with code {$reconExitCode}");

                    return;
                }

                $reconData = json_decode($reconStdout, true);
                if (! is_array($reconData) || ! ($reconData['all_healthy'] ?? false)) {
                    $this->sendHttpError($client, 500, "Internal Error: Rollback probe {$probeName} reconciliations reported unhealthy");

                    return;
                }

                // Validate all six actual named service class results
                $requiredServices = [
                    'accounting' => 'AccountingReconciliationService',
                    'inventory' => 'InventoryReconciliationService',
                    'sales' => 'SalesReconciliationService',
                    'payables' => 'PayablesReconciliationService',
                    'money' => 'MoneyReconciliationService',
                    'phase7' => 'Phase7ReconciliationService',
                ];

                $reconcilersDict = [];
                $totalIssueCount = 0;
                $allSixPass = true;
                foreach ($requiredServices as $shortKey => $fullServiceName) {
                    $svcRes = $reconData['reconciler_results'][$fullServiceName] ?? null;
                    if (! is_array($svcRes)) {
                        $allSixPass = false;
                        break;
                    }
                    $isHealthy = ($svcRes['healthy'] ?? false) === true;
                    $issueCount = (int) ($svcRes['issue_count'] ?? -1);
                    if (! $isHealthy || $issueCount !== 0) {
                        $allSixPass = false;
                    }
                    $totalIssueCount += max(0, $issueCount);
                    $reconcilersDict[$shortKey] = $isHealthy && ($issueCount === 0);
                    $reconcilersDict[$fullServiceName] = [
                        'healthy' => $isHealthy,
                        'issue_count' => $issueCount,
                    ];
                }

                $actualAllHealthy = ($reconData['all_healthy'] ?? false) === true;
                if (! $allSixPass || ! $actualAllHealthy || $totalIssueCount !== 0) {
                    $this->sendHttpError($client, 500, "Internal Error: Rollback probe {$probeName} reconciliations failed verification");

                    return;
                }

                $witnessDir = dirname((string) ($this->targetConfig['witness_path'] ?? ''));

                // Preserve raw recon output artifact exclusively with mode 0600
                $rawReconFile = $witnessDir . '/probe_' . $probeName . '_reconciliation.json';
                $this->writeExclusiveSecureFile($rawReconFile, $reconStdout . "\n");
                $rawReconSha = hash_file('sha256', $rawReconFile);

                $bodyDecoded = json_decode($body, true);
                $probeEventId = is_array($bodyDecoded) ? (string) ($bodyDecoded['client_event_id'] ?? '') : '';

                // Build probe report with verified actual values (no hardcoded zeroes or booleans)
                $probeReport = [
                    'probe_name' => $probeName,
                    'client_event_id' => $probeEventId,
                    'expected_http_status' => $expectedStatus,
                    'actual_http_status' => $responseStatus,
                    'status_matched' => ($responseStatus === $expectedStatus),
                    'schema_name' => (string) ($this->targetConfig['target_schema'] ?? ''),
                    'company_id' => (int) ($this->targetConfig['target_company_id'] ?? 0),
                    'company_public_id' => (string) ($this->targetConfig['target_company_public_id'] ?? ''),
                    'backend_head' => (string) ($this->targetConfig['backend_head'] ?? ''),
                    'handoff_sha256' => (string) ($this->targetConfig['handoff_sha256'] ?? ''),
                    'economic_delta_zero' => ($zeroDeltaRes['is_zero_delta'] ?? false) === true,
                    'zero_delta_verified' => ($zeroDeltaRes['is_zero_delta'] ?? false) === true,
                    'reconciliations_healthy' => $allSixPass && $actualAllHealthy && ($totalIssueCount === 0),
                    'issue_count' => $totalIssueCount,
                    'total_issue_count' => $totalIssueCount,
                    'reconciliations' => $reconcilersDict,
                    'baseline_metrics' => $preProbeSnapshot,
                    'post_metrics' => $postProbeSnapshot,
                    'reconciliation_exit_code' => $reconExitCode,
                    'reconciliation_artifact_sha256' => $rawReconSha,
                    'generated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
                ];

                $probeReportFile = $witnessDir . '/probe_' . $probeName . '_report.json';
                $this->writeExclusiveSecureFile($probeReportFile, json_encode($probeReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
                $probeReportSha = hash_file('sha256', $probeReportFile);

                // Update immutable manifest index file
                $manifestFile = $witnessDir . '/probe_reports_manifest.json';
                $currentManifest = [];
                if (is_file($manifestFile) && ! is_link($manifestFile)) {
                    $currentManifest = json_decode((string) file_get_contents($manifestFile), true) ?: [];
                }
                $currentManifest[$probeName] = [
                    'probe_name' => $probeName,
                    'client_event_id' => $probeEventId,
                    'expected_http_status' => $expectedStatus,
                    'actual_http_status' => $responseStatus,
                    'report_file' => basename($probeReportFile),
                    'report_sha256' => $probeReportSha,
                    'reconciliation_file' => basename($rawReconFile),
                    'reconciliation_sha256' => $rawReconSha,
                    'zero_delta_verified' => true,
                    'reconciliations_healthy' => true,
                ];
                $tmpManifest = $witnessDir . '/.probe_reports_manifest.' . bin2hex(random_bytes(6)) . '.tmp';
                $this->writeExclusiveSecureFile($tmpManifest, json_encode($currentManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
                rename($tmpManifest, $manifestFile);
                chmod($manifestFile, 0600);
            }

            // 6. Normal passthrough: return upstream response to client
            $this->writeAll($client, $upstreamResponse, $deadline);
        } finally {
            if (is_resource($client)) {
                @fclose($client);
            }
            if (is_resource($upstream)) {
                @fclose($upstream);
            }
        }
    }

    /**
     * Determines if incoming request is strictly the readiness probe.
     * Enforces GET method, exact /_harness/readiness path, and optional valid hex nonce.
     */
    public static function isReadinessRequest(string $method, string $path): bool
    {
        if ($method !== 'GET') {
            return false;
        }

        $parts = parse_url($path);
        $urlPath = $parts['path'] ?? $path;
        if ($urlPath !== '/_harness/readiness') {
            return false;
        }

        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $query);
            if (isset($query['nonce'])) {
                $nonce = (string) $query['nonce'];
                if ($nonce === '' || ! preg_match('/^[0-9a-f]{16,64}$/i', $nonce)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Determines if incoming request matches the one-shot lost-ACK target.
     * Enforces anchored path matching, exact company public ULID, method POST, and body client_event_id.
     */
    public function isTargetRequest(string $method, string $path, string $body): bool
    {
        if ($this->suppressionExecuted) {
            return false;
        }

        if ($method !== 'POST') {
            return false;
        }

        $targetEventId = (string) ($this->targetConfig['target_event_id'] ?? '');
        $expectedCompanyUlid = (string) ($this->targetConfig['target_company_public_id'] ?? '');

        if ($targetEventId === '' || $expectedCompanyUlid === '') {
            return false;
        }

        // Anchored path check: /api/mobile/v2/companies/{ulid}/sales/online-cash
        if (! preg_match('#^/api/mobile/v2/companies/([0-9A-Za-z_-]+)/sales/online-cash$#', $path, $matches)) {
            return false;
        }

        $companyPublicId = $matches[1];
        if ($companyPublicId !== $expectedCompanyUlid) {
            return false;
        }

        // Parse body JSON and verify client_event_id matches target
        $decoded = json_decode($body, true);
        if (! is_array($decoded) || ($decoded['client_event_id'] ?? '') !== $targetEventId) {
            return false;
        }

        return true;
    }

    /**
     * Identifies if incoming request matches any of the predeclared rollback probe events.
     * Returns the probe name (e.g. 'permission_denial') or null.
     */
    public function getProbeNameIfMatching(string $method, string $path, string $body): ?string
    {
        if ($method !== 'POST') {
            return null;
        }

        $expectedCompanyUlid = (string) ($this->targetConfig['target_company_public_id'] ?? '');
        if ($expectedCompanyUlid === '') {
            return null;
        }

        if (! preg_match('#^/api/mobile/v2/companies/([0-9A-Za-z_-]+)/sales/online-cash$#', $path, $matches)) {
            return null;
        }

        if ($matches[1] !== $expectedCompanyUlid) {
            return null;
        }

        $decoded = json_decode($body, true);
        if (! is_array($decoded) || empty($decoded['client_event_id'])) {
            return null;
        }

        $eventId = (string) $decoded['client_event_id'];
        $probeEventIds = (array) ($this->targetConfig['probe_event_ids'] ?? []);

        foreach ($probeEventIds as $pName => $pEventId) {
            if ($eventId === (string) $pEventId) {
                return (string) $pName;
            }
        }

        return null;
    }

    /**
     * Writes content to an exclusive new file (0600) in an owned directory.
     * Fails closed if the file already exists, if directory is a symlink or not owned by current UID,
     * or if write/sync fails.
     */
    public function writeExclusiveSecureFile(string $filePath, string $content): void
    {
        if (is_link($filePath) || file_exists($filePath)) {
            throw new \RuntimeException("Exclusive write refused: target file already exists or is symlink: {$filePath}");
        }

        $dir = dirname($filePath);
        if (is_link($dir)) {
            throw new \RuntimeException("Exclusive write refused: directory is a symlink: {$dir}");
        }

        $currentUid = function_exists('posix_getuid') ? posix_getuid() : getmyuid();
        $dirStat = @stat($dir);
        if ($dirStat === false || (int) $dirStat['uid'] !== (int) $currentUid) {
            throw new \RuntimeException("Exclusive write refused: directory UID mismatch: {$dir}");
        }

        $dirPerms = fileperms($dir) & 0777;
        if (($dirPerms & 0077) !== 0) {
            throw new \RuntimeException(sprintf("Exclusive write refused: insecure directory permissions %04o on %s", $dirPerms, $dir));
        }

        $fh = @fopen($filePath, 'xb');
        if ($fh === false) {
            throw new \RuntimeException("Exclusive write failed to open file for creation: {$filePath}");
        }

        @chmod($filePath, 0600);

        $len = strlen($content);
        $written = @fwrite($fh, $content);
        if ($written !== $len) {
            @fclose($fh);
            @unlink($filePath);
            throw new \RuntimeException("Exclusive write incomplete: expected {$len} bytes, wrote {$written} to {$filePath}");
        }

        @fflush($fh);
        if (function_exists('fsync')) {
            @fsync($fh);
        }
        @fclose($fh);

        if (is_link($filePath)) {
            @unlink($filePath);
            throw new \RuntimeException("Exclusive write result is symlink: {$filePath}");
        }
        $perms = fileperms($filePath) & 0777;
        if ($perms !== 0600) {
            @unlink($filePath);
            throw new \RuntimeException(sprintf("Exclusive write insecure perms %04o on %s", $perms, $filePath));
        }
    }

    /**
     * Creates a verified read-only PDO connection to the disposable database schema on port 3342.
     */
    private function createReadOnlyPdo(): ?PDO
    {
        $schema = (string) ($this->targetConfig['target_schema'] ?? '');
        $companyId = (int) ($this->targetConfig['target_company_id'] ?? 0);
        $host = (string) ($this->targetConfig['db_host'] ?? self::DEFAULT_DB_HOST);
        $port = (int) ($this->targetConfig['db_port'] ?? self::DEFAULT_DB_PORT);
        $user = (string) ($this->targetConfig['db_user'] ?? 'root');
        $pass = (string) ($this->targetConfig['db_password'] ?? '');

        if ($schema === '' || $companyId <= 0) {
            return null;
        }

        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$schema};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 3,
            ]);

            // 1. Assert exact port 3342
            $portRow = $pdo->query('SELECT @@port AS p')->fetch();
            if ((int) ($portRow['p'] ?? 0) !== self::DEFAULT_DB_PORT) {
                return null;
            }

            // 2. Assert exact database schema
            $dbRow = $pdo->query('SELECT DATABASE() AS d')->fetch();
            if (($dbRow['d'] ?? '') !== $schema) {
                return null;
            }

            // 3. Set read-only session
            $pdo->exec('SET SESSION TRANSACTION READ ONLY');

            return $pdo;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Queries MariaDB via read-only PDO to verify committed receipt state.
     * Asserts @@port=3342, DATABASE()=disposable_schema, matching company_id,
     * positive invoice and payment IDs, canonical table joins, and payload_digest match.
     *
     * @return array{id: int|string, status: string, sales_invoice_id: int, customer_payment_id: int, payload_digest: string}|null
     */
    private function verifyCommitInDatabase(): ?array
    {
        $companyId = (int) ($this->targetConfig['target_company_id'] ?? 0);
        $eventId = (string) ($this->targetConfig['target_event_id'] ?? '');
        $expectedDigest = (string) ($this->targetConfig['expected_digest'] ?? '');

        if ($eventId === '' || $companyId <= 0) {
            return null;
        }

        $pdo = $this->createReadOnlyPdo();
        if ($pdo === null) {
            return null;
        }

        try {
            // Query mobile_event_receipts with canonical inner joins on sales_invoices and customer_payments
            $stmt = $pdo->prepare(
                'SELECT r.id, r.status, r.payload_digest, r.sales_invoice_id, r.customer_payment_id,
                        si.id AS si_id, si.company_id AS si_company_id,
                        cp.id AS cp_id, cp.company_id AS cp_company_id
                 FROM mobile_event_receipts r
                 INNER JOIN sales_invoices si ON r.sales_invoice_id = si.id AND si.company_id = r.company_id
                 INNER JOIN customer_payments cp ON r.customer_payment_id = cp.id AND cp.company_id = r.company_id
                 WHERE r.company_id = ? AND r.client_event_id = ?
                 LIMIT 1'
            );
            $stmt->execute([$companyId, $eventId]);
            $row = $stmt->fetch();

            if (! is_array($row)) {
                return null;
            }

            $status = (string) ($row['status'] ?? '');
            $invId = $row['sales_invoice_id'] ?? null;
            $payId = $row['customer_payment_id'] ?? null;
            $digest = (string) ($row['payload_digest'] ?? '');

            if (($status !== 'posted' && $status !== 'completed') || $invId === null || $payId === null || (int) $invId <= 0 || (int) $payId <= 0) {
                return null;
            }

            // Verify company isolation in joined rows
            if ((int) $row['si_company_id'] !== $companyId || (int) $row['cp_company_id'] !== $companyId) {
                return null;
            }

            // Assert exact payload digest match
            if ($expectedDigest !== '' && ! hash_equals($expectedDigest, $digest)) {
                return null;
            }

            return [
                'id' => $row['id'],
                'status' => $status,
                'sales_invoice_id' => (int) $invId,
                'customer_payment_id' => (int) $payId,
                'payload_digest' => $digest,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Atomically creates witness file with state 'CAPTURED_REAL_TRANSPORT_DROP'.
     * File is created via O_CREAT | O_EXCL under 0700 parent directory,
     * permissions 0600, with full write, flush, and UID verification.
     *
     * @param array{id: int|string, status: string, sales_invoice_id: int, customer_payment_id: int, payload_digest: string} $commitInfo
     */
    private function createWitnessArtifact(array $commitInfo): void
    {
        $witnessPath = (string) ($this->targetConfig['witness_path'] ?? '');
        if ($witnessPath === '') {
            throw new RuntimeException('TestTransportProxy: missing witness_path.');
        }

        $parentDir = dirname($witnessPath);
        if (! is_dir($parentDir)) {
            throw new RuntimeException("TestTransportProxy: witness parent directory '{$parentDir}' does not exist.");
        }

        // Verify parent directory permissions (0700) and UID ownership
        $parentPerms = fileperms($parentDir) & 0777;
        if ($parentPerms !== 0700) {
            throw new RuntimeException(sprintf("TestTransportProxy: witness parent directory permissions must be 0700, got %04o.", $parentPerms));
        }
        if (fileowner($parentDir) !== getmyuid()) {
            throw new RuntimeException('TestTransportProxy: witness parent directory must be owned by current UID.');
        }

        $oldUmask = umask(0077);
        $fp = @fopen($witnessPath, 'x'); // atomic O_CREAT | O_EXCL
        umask($oldUmask);

        if ($fp === false) {
            throw new RuntimeException("TestTransportProxy: failed to create witness file '{$witnessPath}' with O_EXCL.");
        }

        $targetNonceHash = (string) ($this->targetConfig['target_nonce_hash'] ?? '');
        $witnessData = [
            'contract_version' => 'lost_ack_witness_v1',
            'suppression_mode' => 'TEST_TRANSPORT_BOUNDARY_SINGLE_RESPONSE_DROP',
            'network_witness_state' => 'CAPTURED_REAL_TRANSPORT_DROP',
            'timestamp_iso8601' => gmdate('Y-m-d\TH:i:s\Z'),
            'event_id' => (string) ($this->targetConfig['target_event_id'] ?? ''),
            'schema_name' => (string) ($this->targetConfig['target_schema'] ?? ''),
            'company_id' => (int) ($this->targetConfig['target_company_id'] ?? 0),
            'company_public_id' => (string) ($this->targetConfig['target_company_public_id'] ?? ''),
            'target_nonce_hash' => $targetNonceHash,
            'response_status_code_before_suppression' => 201,
            'db_commit_verified' => true,
            'proxy_socket_closed_without_response' => true,
            'client_socket_dropped' => true,
            'bytes_forwarded' => 0,
            'client_transport_drop_observed' => false,
            'sales_invoice_id' => (int) $commitInfo['sales_invoice_id'],
            'customer_payment_id' => (int) $commitInfo['customer_payment_id'],
            'payload_digest' => (string) $commitInfo['payload_digest'],
        ];

        $payload = json_encode($witnessData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $written = fwrite($fp, $payload);
        fflush($fp);
        fclose($fp);

        if ($written !== strlen($payload)) {
            @unlink($witnessPath);
            throw new RuntimeException('TestTransportProxy: partial write creating witness artifact.');
        }

        @chmod($witnessPath, 0600);
        $filePerms = fileperms($witnessPath) & 0777;
        if ($filePerms !== 0600) {
            @unlink($witnessPath);
            throw new RuntimeException(sprintf("TestTransportProxy: witness file permissions must be 0600, got %04o.", $filePerms));
        }
    }

    /**
     * Writes all bytes to stream with monotonic deadline.
     *
     * @param resource $stream
     */
    private function writeAll($stream, string $data, float $deadline): bool
    {
        $totalBytes = strlen($data);
        $bytesWritten = 0;

        while ($bytesWritten < $totalBytes && (hrtime(true) / 1e9) < $deadline) {
            $chunk = @fwrite($stream, substr($data, $bytesWritten));
            if ($chunk === false || $chunk === 0) {
                return false;
            }
            $bytesWritten += $chunk;
        }

        return $bytesWritten === $totalBytes;
    }

    /**
     * Sends an HTTP error response to client and closes socket.
     *
     * @param resource $client
     */
    private function sendHttpError($client, int $statusCode, string $reason): void
    {
        $body = "{$statusCode} {$reason}\n";
        $len = strlen($body);
        $response = "HTTP/1.1 {$statusCode} {$reason}\r\nContent-Type: text/plain\r\nContent-Length: {$len}\r\nConnection: close\r\n\r\n{$body}";
        @fwrite($client, $response);
        @fclose($client);
    }
}
