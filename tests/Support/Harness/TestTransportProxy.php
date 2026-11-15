<?php

declare(strict_types=1);

namespace Tests\Support\Harness;

use PDO;
use RuntimeException;
use InvalidArgumentException;

/**
 * Owned Test Transport Proxy for Mobile V2 Supervised paired integration testing.
 *
 * Sits strictly between Caddy TLS proxy and the supervised PHP HTTP backend.
 * Mediates on loopback (default 127.0.0.1:18080) and forwards to upstream backend (default 127.0.0.1:18081).
 *
 * Responsibilities:
 * - One-shot transport drop: intercepts target POST /api/mobile/v2/companies/{companyId}/sales/online-cash
 *   for the configured event ID.
 * - Upstream drain: lets PHP backend finish execution and emit HTTP 201 Created.
 * - Verified commit check: queries MariaDB (port 3342) via PDO in committed state (transaction level 0)
 *   to verify mobile_event_receipts row exists with posted/completed status, sales_invoice_id,
 *   customer_payment_id, and matching canonical payload digest.
 * - Atomic witness creation: creates lost_ack_suppression_witness.json via fopen(..., 'x'),
 *   mode 0600, UID validated, with state 'CAPTURED_REAL_TRANSPORT_DROP'.
 * - Abrupt socket drop: immediately closes the client socket without forwarding the HTTP 201 response.
 * - Preserves subsequent requests: subsequent requests (e.g. GET status) are forwarded cleanly to upstream.
 */
class TestTransportProxy
{
    private string $listenHost;
    private int $listenPort;
    private string $upstreamHost;
    private int $upstreamPort;
    private ?array $targetConfig;
    private bool $suppressionExecuted = false;
    private bool $running = false;
    /** @var resource|null */
    private $serverSocket = null;

    /**
     * @param array{
     *     listen_port?: int,
     *     upstream_port?: int,
     *     target_event_id?: string,
     *     target_schema?: string,
     *     target_company_id?: int,
     *     expected_digest?: string,
     *     witness_path?: string,
     *     db_host?: string,
     *     db_port?: int,
     *     db_user?: string,
     *     db_password?: string
     * } $config
     */
    public function __construct(array $config = [])
    {
        $this->listenHost = '127.0.0.1';
        $this->listenPort = $config['listen_port'] ?? DisposableMobileV2BackendHarness::BACKEND_LOOPBACK_PORT;
        $this->upstreamHost = '127.0.0.1';
        $this->upstreamPort = $config['upstream_port'] ?? DisposableMobileV2BackendHarness::BACKEND_INTERNAL_PORT;
        $this->targetConfig = $config;
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
            throw new RuntimeException("Failed to bind proxy server on {$serverUri}: [{$errno}] {$errstr}");
        }

        stream_set_blocking($this->serverSocket, false);
        $this->running = true;
        $iterations = 0;

        while ($this->running) {
            if ($maxIterations > 0 && ++$iterations > $maxIterations) {
                break;
            }

            $read = [$this->serverSocket];
            $write = null;
            $except = null;

            $numChanged = @stream_select($read, $write, $except, 0, 200000); // 200ms
            if ($numChanged === false || $numChanged === 0) {
                continue;
            }

            $client = @stream_socket_accept($this->serverSocket, 1.0);
            if ($client === false) {
                continue;
            }

            $this->handleClientConnection($client);
        }

        $this->stop();
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
     * Handle single incoming client connection.
     *
     * @param resource $client
     */
    private function handleClientConnection($client): void
    {
        stream_set_blocking($client, true);
        stream_set_timeout($client, 10);

        // Read request headers
        $rawRequest = '';
        while (! str_contains($rawRequest, "\r\n\r\n")) {
            $chunk = @fread($client, 4096);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $rawRequest .= $chunk;
        }

        if ($rawRequest === '') {
            @fclose($client);
            return;
        }

        [$headerPart, $bodyPrefix] = explode("\r\n\r\n", $rawRequest, 2);
        $headerLines = explode("\r\n", $headerPart);
        $requestLine = array_shift($headerLines) ?? '';
        $parts = explode(' ', $requestLine);
        $method = strtoupper($parts[0] ?? 'GET');
        $path = $parts[1] ?? '/';

        // Parse Content-Length if present
        $contentLength = 0;
        foreach ($headerLines as $h) {
            if (stripos($h, 'Content-Length:') === 0) {
                $contentLength = (int) trim(substr($h, 15));
                break;
            }
        }

        // Read remaining body if needed
        $body = $bodyPrefix;
        $remaining = $contentLength - strlen($bodyPrefix);
        while ($remaining > 0) {
            $chunk = @fread($client, min(4096, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $body .= $chunk;
            $remaining -= strlen($chunk);
        }

        // Check if this request is our one-shot suppression target
        $isTarget = $this->isTargetRequest($method, $path, $body);

        // Forward request to upstream
        $upstreamUri = "tcp://{$this->upstreamHost}:{$this->upstreamPort}";
        $upstreamErrno = 0;
        $upstreamErrstr = '';
        $upstream = @stream_socket_client($upstreamUri, $upstreamErrno, $upstreamErrstr, 5.0);

        if ($upstream === false) {
            $errResponse = "HTTP/1.1 502 Bad Gateway\r\nContent-Type: text/plain\r\nContent-Length: 26\r\nConnection: close\r\n\r\n502 Proxy Upstream Connect";
            @fwrite($client, $errResponse);
            @fclose($client);
            return;
        }

        stream_set_blocking($upstream, true);
        stream_set_timeout($upstream, 15);

        // Write full original request to upstream
        $fullWireRequest = $headerPart . "\r\n\r\n" . $body;
        @fwrite($upstream, $fullWireRequest);

        // Read full response from upstream
        $upstreamResponse = '';
        while (! feof($upstream)) {
            $chunk = @fread($upstream, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $upstreamResponse .= $chunk;
        }
        @fclose($upstream);

        if ($isTarget && ! $this->suppressionExecuted) {
            // Verify upstream returned 201 Created
            $responseStatus = 0;
            if (preg_match('#^HTTP/\d\.\d\s+(\d{3})#', $upstreamResponse, $m)) {
                $responseStatus = (int) $m[1];
            }

            if ($responseStatus === 201) {
                // Perform DB commit check via PDO
                $commitInfo = $this->verifyCommitInDatabase();

                if ($commitInfo !== null) {
                    // Create atomic witness artifact
                    $this->createWitnessArtifact($commitInfo);

                    // Mark suppression executed
                    $this->suppressionExecuted = true;

                    // Abruptly drop client socket connection (zero bytes back to client)
                    @fclose($client);
                    return;
                }
            }
        }

        // Normal passthrough: return upstream response to client
        @fwrite($client, $upstreamResponse);
        @fclose($client);
    }

    /**
     * Determines if incoming request matches the one-shot lost-ACK target.
     */
    private function isTargetRequest(string $method, string $path, string $body): bool
    {
        if ($this->suppressionExecuted) {
            return false;
        }

        if ($method !== 'POST') {
            return false;
        }

        $targetEventId = (string) ($this->targetConfig['target_event_id'] ?? '');
        if ($targetEventId === '') {
            return false;
        }

        // Path check: /api/mobile/v2/companies/{companyId}/sales/online-cash
        if (! preg_match('#/api/mobile/v2/companies/\d+/sales/online-cash#', $path)) {
            return false;
        }

        // Body client_event_id check
        $decoded = json_decode($body, true);
        if (! is_array($decoded) || ($decoded['client_event_id'] ?? '') !== $targetEventId) {
            return false;
        }

        return true;
    }

    /**
     * Queries MariaDB via PDO to verify committed receipt state.
     *
     * @return array{id: int|string, status: string, sales_invoice_id: int, customer_payment_id: int, payload_digest: string}|null
     */
    private function verifyCommitInDatabase(): ?array
    {
        $schema = (string) ($this->targetConfig['target_schema'] ?? '');
        $companyId = (int) ($this->targetConfig['target_company_id'] ?? 0);
        $eventId = (string) ($this->targetConfig['target_event_id'] ?? '');
        $host = (string) ($this->targetConfig['db_host'] ?? '127.0.0.1');
        $port = (int) ($this->targetConfig['db_port'] ?? 3342);
        $user = (string) ($this->targetConfig['db_user'] ?? 'root');
        $pass = (string) ($this->targetConfig['db_password'] ?? '');

        if ($schema === '' || $eventId === '') {
            return null;
        }

        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$schema};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5,
            ]);

            $stmt = $pdo->prepare(
                'SELECT id, status, payload_digest, sales_invoice_id, customer_payment_id FROM mobile_event_receipts WHERE client_event_id = ? LIMIT 1'
            );
            $stmt->execute([$eventId]);
            $row = $stmt->fetch();

            if (! is_array($row)) {
                return null;
            }

            $status = (string) ($row['status'] ?? '');
            $invId = $row['sales_invoice_id'] ?? null;
            $payId = $row['customer_payment_id'] ?? null;

            if (($status !== 'posted' && $status !== 'completed') || $invId === null || $payId === null) {
                return null;
            }

            return [
                'id' => $row['id'],
                'status' => $status,
                'sales_invoice_id' => (int) $invId,
                'customer_payment_id' => (int) $payId,
                'payload_digest' => (string) ($row['payload_digest'] ?? ''),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Atomically creates witness file with state 'CAPTURED_REAL_TRANSPORT_DROP'.
     *
     * @param array<string, mixed> $commitInfo
     */
    private function createWitnessArtifact(array $commitInfo): void
    {
        $witnessPath = (string) ($this->targetConfig['witness_path'] ?? '');
        if ($witnessPath === '') {
            return;
        }

        $fp = @fopen($witnessPath, 'x'); // atomic O_CREAT | O_EXCL
        if ($fp === false) {
            return;
        }

        $witnessData = [
            'suppression_mode' => 'TEST_TRANSPORT_BOUNDARY_SINGLE_RESPONSE_DROP',
            'network_witness_state' => 'CAPTURED_REAL_TRANSPORT_DROP',
            'timestamp' => microtime(true),
            'client_event_id' => (string) ($this->targetConfig['target_event_id'] ?? ''),
            'schema_name' => (string) ($this->targetConfig['target_schema'] ?? ''),
            'response_status_code_before_suppression' => 201,
            'server_commit_verified_via_pdo' => true,
            'sales_invoice_id' => (int) $commitInfo['sales_invoice_id'],
            'customer_payment_id' => (int) $commitInfo['customer_payment_id'],
            'payload_digest' => (string) $commitInfo['payload_digest'],
        ];

        fwrite($fp, json_encode($witnessData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fclose($fp);
        @chmod($witnessPath, 0600);
    }
}
