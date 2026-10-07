<?php
declare(strict_types=1);

namespace Harness\Infrastructure\IPC;

use RuntimeException;

class JsonRpcClient
{
    /** @var resource|null */
    private $stream = null;
    private int $requestId = 0;

    public function __construct(
        private readonly string $socketPath = '/var/run/harness.sock',
        private readonly string $tcpAddr = '127.0.0.1:9099'
    ) {}

    public function __destruct()
    {
        $this->close();
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
            $this->stream = null;
        }
    }

    /**
     * @throws RuntimeException
     */
    public function call(string $method, array $params = []): mixed
    {
        $this->ensureConnected();

        $this->requestId++;
        $request = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'id' => $this->requestId,
        ];

        $payload = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $written = @fwrite($this->stream, $payload);
        if ($written === false || $written === 0) {
            // Reconnect once and retry
            $this->close();
            $this->ensureConnected();
            $written = fwrite($this->stream, $payload);
            if ($written === false) {
                throw new RuntimeException("Failed to write JSON-RPC request to Go Engine: {$method}");
            }
        }

        $responseLine = fgets($this->stream);
        if ($responseLine === false) {
            throw new RuntimeException("Empty response from Go Engine for method: {$method}");
        }

        $decoded = json_decode($responseLine, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Malformed JSON-RPC response from Go Engine: {$responseLine}");
        }

        if (isset($decoded['error'])) {
            $err = $decoded['error'];
            $msg = is_array($err) ? ($err['message'] ?? 'Unknown RPC error') : (string)$err;
            throw new RuntimeException("JSON-RPC error [{$method}]: {$msg}");
        }

        return $decoded['result'] ?? null;
    }

    public function ping(): bool
    {
        try {
            $res = $this->call('system.ping');
            return is_array($res) && ($res['status'] ?? '') === 'pong';
        } catch (\Throwable) {
            return false;
        }
    }

    public function execLocal(array $cmd, ?string $workDir = null, int $timeoutMs = 60000): array
    {
        return (array)$this->call('runner.exec_local', [
            'cmd' => $cmd,
            'work_dir' => $workDir,
            'timeout_ms' => $timeoutMs,
        ]);
    }

    public function execDocker(string $containerId, array $cmd, ?string $workDir = null, int $timeoutMs = 120000): array
    {
        return (array)$this->call('docker.exec', [
            'container_id' => $containerId,
            'cmd' => $cmd,
            'work_dir' => $workDir,
            'timeout_ms' => $timeoutMs,
        ]);
    }

    public function chatLlm(array $chatRequest): array
    {
        return (array)$this->call('llm.chat', $chatRequest);
    }

    public function publishEvent(string $event, string $sessionId, ?string $nodeId, array $data): bool
    {
        try {
            $this->call('graph.publish_event', [
                'event' => $event,
                'session_id' => $sessionId,
                'node_id' => $nodeId,
                'data' => $data,
            ]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function askHuman(string $sessionId, string $nodeId, string $question, array $options = [], int $timeoutMs = 600000): string
    {
        $res = (array)$this->call('human.ask', [
            'session_id' => $sessionId,
            'node_id' => $nodeId,
            'question' => $question,
            'options' => $options,
            'timeout_ms' => $timeoutMs,
        ]);

        return (string)($res['answer'] ?? '');
    }

    public function logEvent(string $sessionId, string $nodeId, string $eventType, array $payload): bool
    {
        try {
            $this->call('log.event', [
                'session_id' => $sessionId,
                'node_id' => $nodeId,
                'event_type' => $eventType,
                'payload' => $payload,
            ]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function ensureConnected(): void
    {
        if (is_resource($this->stream) && !feof($this->stream)) {
            return;
        }

        $this->close();
        $errno = 0;
        $errstr = '';

        // Try Unix domain socket first if on non-Windows and file exists
        if (PHP_OS_FAMILY !== 'Windows' && file_exists($this->socketPath)) {
            $this->stream = @stream_socket_client('unix://' . $this->socketPath, $errno, $errstr, 5.0);
            if ($this->stream) {
                stream_set_timeout($this->stream, 300); // 5 min timeout for LLM / tools
                return;
            }
        }

        // Fallback to TCP (works on Windows & Linux)
        $this->stream = @stream_socket_client('tcp://' . $this->tcpAddr, $errno, $errstr, 5.0);
        if (!$this->stream) {
            throw new RuntimeException("Cannot connect to Go Engine IPC on {$this->socketPath} or {$this->tcpAddr}: {$errstr} ({$errno})");
        }

        stream_set_timeout($this->stream, 300);
    }
}
