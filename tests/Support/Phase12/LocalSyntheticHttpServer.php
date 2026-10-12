<?php

declare(strict_types=1);

namespace Tests\Support\Phase12;

use RuntimeException;
use Symfony\Component\Process\Process;

final class LocalSyntheticHttpServer
{
    private Process $process;

    private int $port;

    private string $stateFile;

    public function __construct(Process $process, int $port, string $stateFile)
    {
        $this->process = $process;
        $this->port = $port;
        $this->stateFile = $stateFile;
    }

    public static function start(): self
    {
        $stateFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'synthetic-server-'.uniqid('state_', true).'.json';
        file_put_contents($stateFile, json_encode(['request_count' => 0, 'last_request' => null], JSON_THROW_ON_ERROR));

        $php = PHP_BINARY;
        $process = new Process([$php, __FILE__, $stateFile]);
        $process->start();

        $port = null;
        $start = microtime(true);
        while (microtime(true) - $start < 5) {
            $output = trim($process->getOutput());
            if ($output !== '' && is_numeric($output)) {
                $candidate = (int) $output;
                if ($candidate > 0 && $candidate <= 65535) {
                    $port = $candidate;
                    break;
                }
            }
            usleep(20000);
        }

        if ($port === null) {
            $process->stop(1);
            @unlink($stateFile);
            throw new RuntimeException('Failed to start synthetic server: '.$process->getErrorOutput());
        }

        return new self($process, $port, $stateFile);
    }

    public function port(): int
    {
        return $this->port;
    }

    public function requestCount(): int
    {
        $data = $this->readState();

        return (int) ($data['request_count'] ?? 0);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lastRequest(): ?array
    {
        $data = $this->readState();

        return $data['last_request'] ?? null;
    }

    public function resetStats(): void
    {
        $this->writeState(['request_count' => 0, 'last_request' => null]);
    }

    public function stop(): void
    {
        if ($this->process->isRunning()) {
            $opts = [
                'http' => [
                    'method' => 'GET',
                    'header' => "Connection: close\r\n",
                    'timeout' => 1,
                ],
            ];
            @file_get_contents("http://127.0.0.1:{$this->port}/quit", false, stream_context_create($opts));
            // Guarantee process termination within 1 second; do not unbounded wait.
            $this->process->stop(1);
        }
        @unlink($this->stateFile);
    }

    public function __destruct()
    {
        $this->stop();
    }

    /**
     * @return array<string, mixed>
     */
    private function readState(): array
    {
        if (! file_exists($this->stateFile)) {
            return [];
        }
        $raw = @file_get_contents($this->stateFile);
        if ($raw === false || $raw === '') {
            return [];
        }

        return json_decode($raw, true) ?? [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeState(array $data): void
    {
        @file_put_contents($this->stateFile, json_encode($data, JSON_THROW_ON_ERROR), LOCK_EX);
    }
}

// Local socket loop entrypoint executed in the isolated worker process
if (php_sapi_name() === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $stateFile = $argv[1] ?? '';
    if ($stateFile === '') {
        fwrite(STDERR, "Missing state file argument\n");
        exit(1);
    }

    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (! $server) {
        fwrite(STDERR, "Socket fail: {$errstr}\n");
        exit(1);
    }

    $name = stream_socket_get_name($server, false);
    [$host, $portStr] = explode(':', $name);
    $port = (int) $portStr;

    if ($host !== '127.0.0.1' || $port <= 0 || $port > 65535) {
        fwrite(STDERR, "Invalid loopback binding: {$name}\n");
        fclose($server);
        exit(1);
    }

    echo $port."\n";
    flush();

    while ($conn = @stream_socket_accept($server, 15)) {
        // Enforce 5-second stream timeout per accepted connection
        stream_set_timeout($conn, 5);

        $headersText = '';
        while (! str_contains($headersText, "\r\n\r\n")) {
            $chunk = fread($conn, 1024);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $headersText .= $chunk;
            if (strlen($headersText) > 16384) {
                // Reject unbounded headers
                break;
            }
        }

        $headerEnd = strpos($headersText, "\r\n\r\n");
        $body = '';
        if ($headerEnd !== false) {
            $body = substr($headersText, $headerEnd + 4);
            $headersOnly = substr($headersText, 0, $headerEnd);
        } else {
            $headersOnly = $headersText;
        }

        if (preg_match('/Content-Length:\s*(\d+)/i', $headersOnly, $m)) {
            $len = min((int) $m[1], 2097152); // Bounded request body up to 2MB
            $bodySoFar = strlen($body);
            while ($bodySoFar < $len) {
                $chunk = fread($conn, min(8192, $len - $bodySoFar));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $body .= $chunk;
                $bodySoFar += strlen($chunk);
            }
        }

        $lines = explode("\r\n", $headersOnly);
        $requestLine = $lines[0] ?? '';
        $headers = [];
        foreach (array_slice($lines, 1) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }

        if (str_contains($requestLine, 'GET /quit')) {
            fwrite($conn, "HTTP/1.1 200 OK\r\nConnection: close\r\n\r\nbye");
            fclose($conn);
            break;
        }

        // Update state file atomically
        $stateRaw = @file_get_contents($stateFile);
        $state = ($stateRaw !== false && $stateRaw !== '') ? (json_decode($stateRaw, true) ?? []) : [];
        $currentCount = (int) ($state['request_count'] ?? 0);
        $state['request_count'] = $currentCount + 1;
        $state['last_request'] = [
            'request_line' => $requestLine,
            'headers' => $headers,
            'body_length' => strlen($body),
        ];
        @file_put_contents($stateFile, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);

        $scenario = $headers['x-synthetic-scenario'] ?? 'default';

        switch ($scenario) {
            case 'redirect_302':
                $status = '302 Found';
                $extraHeaders = "Location: http://127.0.0.1:9/closed\r\n";
                $respBody = '';
                break;
            case 'outage_503':
                $status = '503 Service Unavailable';
                $extraHeaders = '';
                $respBody = json_encode(['error' => 'temporarily unavailable']);
                break;
            case 'outage_429':
                $status = '429 Too Many Requests';
                $extraHeaders = '';
                $respBody = json_encode(['error' => 'rate limit exceeded']);
                break;
            case 'outage_500':
                $status = '500 Internal Server Error';
                $extraHeaders = '';
                $respBody = json_encode(['error' => 'internal server error']);
                break;
            case 'rejected_401':
                $status = '401 Unauthorized';
                $extraHeaders = '';
                $respBody = json_encode(['error' => 'invalid api key']);
                break;
            case 'rejected_400':
                $status = '400 Bad Request';
                $extraHeaders = '';
                $respBody = json_encode(['error' => 'bad request']);
                break;
            case 'balance_402':
                $status = '402 Payment Required';
                $extraHeaders = '';
                $respBody = json_encode(['error' => 'insufficient balance']);
                break;
            case 'invalid_parameters_422':
                $status = '422 Unprocessable Entity';
                $extraHeaders = '';
                $respBody = json_encode(['error' => 'invalid parameter']);
                break;
            case 'oversized':
                $status = '200 OK';
                $extraHeaders = '';
                $respBody = json_encode([
                    'choices' => [
                        [
                            'message' => ['content' => str_repeat('X', 135000)],
                            'finish_reason' => 'stop',
                        ],
                    ],
                ]);
                break;
            case 'malformed_json':
                $status = '200 OK';
                $extraHeaders = '';
                $respBody = '{"choices": [broken json';
                break;
            case 'empty_response':
                $status = '200 OK';
                $extraHeaders = '';
                $respBody = '';
                break;
            case 'whitespace_keepalive':
                $status = '200 OK';
                $extraHeaders = '';
                $respBody = "   \r\n\t  ".json_encode([
                    'choices' => [
                        [
                            'message' => ['content' => '{"intent":"sales"}'],
                            'finish_reason' => 'stop',
                        ],
                    ],
                    'usage' => [
                        'prompt_tokens' => 10,
                        'completion_tokens' => 5,
                    ],
                ]);
                break;
            case 'audio':
                $status = '200 OK';
                $extraHeaders = '';
                $respBody = json_encode(['text' => 'تسجيل صوتي تجريبي']);
                break;
            case 'finish_reason_length':
                $status = '200 OK';
                $extraHeaders = '';
                $respBody = json_encode([
                    'choices' => [
                        [
                            'message' => ['content' => '{"intent":"sales"}'],
                            'finish_reason' => 'length',
                        ],
                    ],
                ]);
                break;
            default:
                $status = '200 OK';
                $extraHeaders = '';
                $respBody = json_encode([
                    'choices' => [
                        [
                            'message' => ['content' => '{"intent":"sales"}'],
                            'finish_reason' => 'stop',
                        ],
                    ],
                    'usage' => [
                        'prompt_tokens' => 10,
                        'completion_tokens' => 5,
                    ],
                ]);
                break;
        }

        $http = "HTTP/1.1 {$status}\r\n".
                $extraHeaders.
                "Content-Type: application/json\r\n".
                'Content-Length: '.strlen($respBody)."\r\n".
                "Connection: close\r\n\r\n".
                $respBody;
        fwrite($conn, $http);
        fclose($conn);
    }
    fclose($server);
    exit(0);
}
