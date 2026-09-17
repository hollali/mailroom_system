<?php
// includes/mcp_client.php - JSON-RPC 2.0 client for talking to an external MCP server (e.g. Parliament_MCP)

class ParliamentMcpClient
{
    protected string $url;
    protected string $apiKey;
    protected string $protocolVersion;
    protected int $nextId = 1;
    protected ?array $serverInfo = null;

    public function __construct(?string $url = null, ?string $apiKey = null)
    {
        $this->url = rtrim($url !== null && $url !== '' ? $url : ($_ENV['MCP_URL'] ?? getenv('MCP_URL') ?: ''), '/');
        $this->apiKey = $apiKey !== null && $apiKey !== '' ? $apiKey : ($_ENV['MCP_API_KEY'] ?? getenv('MCP_API_KEY') ?: '');
        $this->protocolVersion = '2025-06-18';

        if ($this->url === '') {
            throw new RuntimeException('MCP_URL is not configured. Set MCP_URL in the .env file to point at Parliament_MCP.');
        }
    }

    public function request(string $method, array $params = [], bool $isNotification = false): array
    {
        $payload = ['jsonrpc' => '2.0', 'method' => $method, 'params' => $params];
        if (!$isNotification) {
            $payload['id'] = $this->nextId++;
        }

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json, text/event-stream',
        ];
        if ($this->apiKey !== '') {
            $headers[] = 'X-API-Key: ' . $this->apiKey;
        }

        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($isNotification) {
            return [];
        }

        if ($body === false || trim($body) === '') {
            throw new RuntimeException('MCP request failed: ' . ($error !== '' ? $error : 'empty response (HTTP ' . $status . ')'));
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('MCP response was not valid JSON (HTTP ' . $status . ').');
        }

        if (isset($decoded['error']) && is_array($decoded['error'])) {
            $message = $decoded['error']['message'] ?? 'Unknown MCP error';
            $code = $decoded['error']['code'] ?? -32000;
            $data = $decoded['error']['data'] ?? null;
            $data !== null ? $message .= ' (data: ' . json_encode($data) . ')' : null;
            throw new RuntimeException("MCP error $code: $message");
        }

        return $decoded;
    }

    public function notify(string $method, array $params = []): void
    {
        $this->request($method, $params, true);
    }

    public function initialize(): array
    {
        $response = $this->request('initialize', [
            'protocolVersion' => $this->protocolVersion,
            'capabilities' => [],
            'clientInfo' => ['name' => 'mailroom_system', 'version' => '1.0.0'],
        ]);
        $this->serverInfo = $response['result'] ?? [];
        $this->notify('notifications/initialized');
        return $this->serverInfo;
    }

    public function ping(): array
    {
        return $this->request('ping', []);
    }

    public function listTools(): array
    {
        $response = $this->request('tools/list', []);
        return $response['result']['tools'] ?? [];
    }

    public function callTool(string $name, array $arguments = []): array
    {
        $response = $this->request('tools/call', ['name' => $name, 'arguments' => $arguments]);
        return $response['result'] ?? [];
    }

    public function listResources(): array
    {
        $response = $this->request('resources/list', []);
        return $response['result']['resources'] ?? [];
    }

    public function listResourceTemplates(): array
    {
        $response = $this->request('resources/templates/list', []);
        return $response['result']['resourceTemplates'] ?? [];
    }

    public function readResource(string $uri): array
    {
        $response = $this->request('resources/read', ['uri' => $uri]);
        return $response['result']['contents'] ?? [];
    }
}