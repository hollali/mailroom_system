<?php
// api/mcp.php - Model Context Protocol (MCP) endpoint exposing the mailroom system as tools and resources

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/query.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error(405, 'method_not_allowed', 'The MCP endpoint accepts JSON-RPC 2.0 POST requests only.');
}

api_authenticate();

function mcp_result($id, $result)
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
}

function mcp_error_message($id, $code, $message, $data = null)
{
    $error = ['code' => $code, 'message' => $message];
    if ($data !== null) {
        $error['data'] = $data;
    }
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
}

function mcp_send($payload)
{
    echo api_json($payload);
    exit;
}

function mcp_initialize($params)
{
    $requested = is_array($params) ? ($params['protocolVersion'] ?? '') : '';
    if (!is_string($requested) || $requested === '') {
        $requested = '2025-06-18';
    }
    return [
        'protocolVersion' => $requested,
        'capabilities' => [
            'tools' => ['listChanged' => false],
            'resources' => ['listChanged' => false, 'subscribe' => false],
            'logging' => [],
        ],
        'serverInfo' => [
            'name' => 'Mailroom MCP',
            'version' => '1.0.0',
        ],
    ];
}

function mcp_arg($args, $key, $default = null)
{
    if (!is_array($args) || !array_key_exists($key, $args) || $args[$key] === '') {
        return $default;
    }
    return $args[$key];
}

function mcp_opt_int($args, $key, $default, $max = 100)
{
    $value = mcp_arg($args, $key, $default);
    return is_numeric($value) ? max(0, min($max, (int)$value)) : $default;
}

function mcp_opt_string($args, $key, $default = '')
{
    $value = mcp_arg($args, $key, $default);
    return is_string($value) ? trim($value) : $default;
}

function mcp_require_int($args, $key)
{
    $value = mcp_arg($args, $key, null);
    if ($value === null || !is_numeric($value) || (int)$value < 1) {
        throw new InvalidArgumentException("Argument '$key' (integer > 0) is required.");
    }
    return (int)$value;
}

function mcp_require_string($args, $key, $minLength = 1)
{
    $value = mcp_opt_string($args, $key);
    if (strlen($value) < $minLength) {
        throw new InvalidArgumentException("Argument '$key' (string, at least $minLength characters) is required.");
    }
    return $value;
}

function mcp_run_tool($name, $args)
{
    switch ($name) {
        case 'get_stats':
            return q_stats();

        case 'search_all':
            $q = mcp_require_string($args, 'q', 2);
            return q_search($q, mcp_opt_int($args, 'limit', 15, 50));

        case 'list_documents':
            return q_documents([
                'limit' => mcp_opt_int($args, 'limit', 20),
                'offset' => mcp_opt_int($args, 'offset', 0, PHP_INT_MAX),
                'q' => mcp_opt_string($args, 'q'),
                'type_id' => mcp_arg($args, 'type_id', ''),
            ]);

        case 'get_document':
            $id = mcp_require_int($args, 'id');
            $record = q_document($id);
            return $record ?: ['found' => false, 'message' => 'No document with id ' . $id . '.'];

        case 'list_document_types':
            return q_document_types();

        case 'list_document_distributions':
            return q_document_distributions([
                'limit' => mcp_opt_int($args, 'limit', 20),
                'offset' => mcp_opt_int($args, 'offset', 0, PHP_INT_MAX),
            ]);

        case 'list_parcels':
            $status = mcp_opt_string($args, 'status');
            if ($status !== '' && !in_array($status, parcelDeliveryStatuses(), true)) {
                throw new InvalidArgumentException('Invalid status. Allowed: ' . implode(', ', parcelDeliveryStatuses()) . '.');
            }
            return q_parcels([
                'limit' => mcp_opt_int($args, 'limit', 20),
                'offset' => mcp_opt_int($args, 'offset', 0, PHP_INT_MAX),
                'q' => mcp_opt_string($args, 'q'),
                'status' => $status,
            ]);

        case 'get_parcel':
            $id = mcp_require_int($args, 'id');
            $record = q_parcel($id);
            return $record ?: ['found' => false, 'message' => 'No parcel with id ' . $id . '.'];

        case 'track_parcel':
            $trackingId = mcp_require_string($args, 'tracking_id');
            $record = q_parcel_by_tracking($trackingId);
            return $record ?: ['found' => false, 'message' => 'No parcel matches tracking ID ' . $trackingId . '.'];

        case 'list_newspapers':
            return q_newspapers([
                'limit' => mcp_opt_int($args, 'limit', 20),
                'offset' => mcp_opt_int($args, 'offset', 0, PHP_INT_MAX),
                'q' => mcp_opt_string($args, 'q'),
                'status' => mcp_opt_string($args, 'status'),
                'category_id' => mcp_arg($args, 'category_id', ''),
            ]);

        case 'get_newspaper':
            $id = mcp_require_int($args, 'id');
            $record = q_newspaper($id);
            return $record ?: ['found' => false, 'message' => 'No newspaper with id ' . $id . '.'];

        case 'list_newspaper_categories':
            return q_newspaper_categories();

        case 'list_newspaper_distributions':
            return q_distributions([
                'limit' => mcp_opt_int($args, 'limit', 20),
                'offset' => mcp_opt_int($args, 'offset', 0, PHP_INT_MAX),
            ]);

        case 'get_newspaper_distribution':
            $id = mcp_require_int($args, 'id');
            $record = q_distribution($id);
            return $record ?: ['found' => false, 'message' => 'No newspaper distribution with id ' . $id . '.'];

        case 'list_recipients':
            $active = mcp_arg($args, 'active', '');
            if ($active !== '' && $active !== '0' && $active !== '1') {
                throw new InvalidArgumentException('The active filter must be 0 or 1.');
            }
            return q_recipients([
                'limit' => mcp_opt_int($args, 'limit', 20),
                'offset' => mcp_opt_int($args, 'offset', 0, PHP_INT_MAX),
                'q' => mcp_opt_string($args, 'q'),
                'active' => $active,
            ]);

        case 'get_recipient':
            $id = mcp_require_int($args, 'id');
            $record = q_recipient($id);
            return $record ?: ['found' => false, 'message' => 'No recipient with id ' . $id . '.'];

        case 'list_audit_logs':
            return q_audit([
                'limit' => mcp_opt_int($args, 'limit', 20),
                'offset' => mcp_opt_int($args, 'offset', 0, PHP_INT_MAX),
                'module' => mcp_opt_string($args, 'module'),
                'action_type' => mcp_opt_string($args, 'action_type'),
                'q' => mcp_opt_string($args, 'q'),
            ]);

        default:
            throw new InvalidArgumentException('Unknown tool: ' . $name);
    }
}

function mcp_tool_schema($name, $description, $properties, $required = [])
{
    return [
        'name' => $name,
        'description' => $description,
        'inputSchema' => [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ],
    ];
}

function mcp_tools()
{
    $int = ['type' => 'integer', 'minimum' => 0];
    $qStr = ['type' => 'string', 'description' => 'Keyword filter (LIKE match).'];

    return [
        mcp_tool_schema('get_stats', 'Current mailroom KPIs, counts, and recent activity.', []),
        mcp_tool_schema('search_all', 'Global keyword search across documents, parcels, newspapers, recipients, and distributions.', [
            'q' => ['type' => 'string', 'description' => 'Search query (minimum 2 characters).'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 15],
        ], ['q']),
        mcp_tool_schema('list_documents', 'List documents with optional keyword, type, and pagination filters.', [
            'limit' => $int, 'offset' => $int, 'q' => $qStr,
            'type_id' => ['type' => 'integer', 'description' => 'Filter by document type id.'],
        ]),
        mcp_tool_schema('get_document', 'Get a single document record by id, including its distribution history.', [
            'id' => ['type' => 'integer', 'minimum' => 1],
        ], ['id']),
        mcp_tool_schema('list_document_types', 'List all document categories.', []),
        mcp_tool_schema('list_document_distributions', 'List document distribution records.', [
            'limit' => $int, 'offset' => $int,
        ]),
        mcp_tool_schema('list_parcels', 'List parcels with optional status, keyword, and pagination filters.', [
            'limit' => $int, 'offset' => $int, 'q' => $qStr,
            'status' => ['type' => 'string', 'enum' => parcelDeliveryStatuses(), 'description' => 'Parcel status filter.'],
        ]),
        mcp_tool_schema('get_parcel', 'Get a single parcel record by id.', [
            'id' => ['type' => 'integer', 'minimum' => 1],
        ], ['id']),
        mcp_tool_schema('track_parcel', 'Look up a parcel by its tracking ID.', [
            'tracking_id' => ['type' => 'string', 'description' => 'Full tracking ID, e.g. PRCL-20260307-9B102A.'],
        ], ['tracking_id']),
        mcp_tool_schema('list_newspapers', 'List newspapers with optional status, category, keyword, and pagination filters.', [
            'limit' => $int, 'offset' => $int, 'q' => $qStr,
            'status' => ['type' => 'string', 'description' => 'e.g. available, partial, pending.'],
            'category_id' => ['type' => 'integer', 'description' => 'Filter by newspaper category id.'],
        ]),
        mcp_tool_schema('get_newspaper', 'Get a single newspaper issue by id.', [
            'id' => ['type' => 'integer', 'minimum' => 1],
        ], ['id']),
        mcp_tool_schema('list_newspaper_categories', 'List all newspaper subscription categories.', []),
        mcp_tool_schema('list_newspaper_distributions', 'List newspaper distribution records.', [
            'limit' => $int, 'offset' => $int,
        ]),
        mcp_tool_schema('get_newspaper_distribution', 'Get a single newspaper distribution record by id.', [
            'id' => ['type' => 'integer', 'minimum' => 1],
        ], ['id']),
        mcp_tool_schema('list_recipients', 'List newspaper recipients with optional activity, keyword, and pagination filters.', [
            'limit' => $int, 'offset' => $int, 'q' => $qStr,
            'active' => ['type' => 'integer', 'enum' => [0, 1], 'description' => 'Filter by is_active flag.'],
        ]),
        mcp_tool_schema('get_recipient', 'Get a single recipient by id, including category subscriptions.', [
            'id' => ['type' => 'integer', 'minimum' => 1],
        ], ['id']),
        mcp_tool_schema('list_audit_logs', 'List audit trail entries with optional module, action type, keyword, and pagination filters.', [
            'limit' => $int, 'offset' => $int,
            'module' => ['type' => 'string', 'description' => 'e.g. document, parcel, newspaper.'],
            'action_type' => ['type' => 'string', 'description' => 'e.g. create, update, delete, pickup.'],
            'q' => $qStr,
        ]),
    ];
}

function mcp_resources()
{
    return [
        ['uri' => 'mailroom://system/stats', 'name' => 'Mailroom Stats', 'mimeType' => 'application/json', 'description' => 'Current KPIs, counts, and recent activity.'],
        ['uri' => 'mailroom://documents', 'name' => 'Documents', 'mimeType' => 'application/json', 'description' => 'Latest documents.'],
        ['uri' => 'mailroom://parcels', 'name' => 'Parcels', 'mimeType' => 'application/json', 'description' => 'Latest parcels.'],
        ['uri' => 'mailroom://newspapers', 'name' => 'Newspapers', 'mimeType' => 'application/json', 'description' => 'Latest newspaper issues.'],
        ['uri' => 'mailroom://recipients', 'name' => 'Recipients', 'mimeType' => 'application/json', 'description' => 'Newspaper recipients.'],
        ['uri' => 'mailroom://distributions', 'name' => 'Newspaper Distributions', 'mimeType' => 'application/json', 'description' => 'Newspaper distribution records.'],
        ['uri' => 'mailroom://audit', 'name' => 'Audit Log', 'mimeType' => 'application/json', 'description' => 'Recent audit trail entries.'],
    ];
}

function mcp_resource_templates()
{
    return [
        ['uriTemplate' => 'mailroom://documents/{id}', 'name' => 'Document by id', 'mimeType' => 'application/json'],
        ['uriTemplate' => 'mailroom://parcels/{trackingId}', 'name' => 'Parcel by tracking ID', 'mimeType' => 'application/json'],
        ['uriTemplate' => 'mailroom://newspapers/{id}', 'name' => 'Newspaper by id', 'mimeType' => 'application/json'],
        ['uriTemplate' => 'mailroom://recipients/{id}', 'name' => 'Recipient by id', 'mimeType' => 'application/json'],
        ['uriTemplate' => 'mailroom://distributions/{id}', 'name' => 'Newspaper distribution by id', 'mimeType' => 'application/json'],
    ];
}

function mcp_read_resource($uri)
{
    $scheme = parse_url($uri, PHP_URL_SCHEME);
    if ($scheme !== 'mailroom') {
        return null;
    }
    $host = parse_url($uri, PHP_URL_HOST);
    $path = parse_url($uri, PHP_URL_PATH);
    $combined = trim(($host ?? '') . '/' . ($path ?? ''), '/');
    $combined = preg_replace('#/+#', '/', $combined);
    $segments = $combined === '' ? [] : explode('/', $combined);
    $head = $segments[0] ?? '';

    switch ($head) {
        case 'system':
            if (($segments[1] ?? '') === 'stats') {
                return q_stats();
            }
            return null;

        case 'documents':
            if (isset($segments[1])) {
                return q_document($segments[1]);
            }
            return q_documents(['limit' => 50, 'offset' => 0]);

        case 'parcels':
            if (isset($segments[1])) {
                return q_parcel_by_tracking($segments[1]);
            }
            return q_parcels(['limit' => 50, 'offset' => 0]);

        case 'newspapers':
            if (isset($segments[1])) {
                return q_newspaper($segments[1]);
            }
            return q_newspapers(['limit' => 50, 'offset' => 0]);

        case 'recipients':
            if (isset($segments[1])) {
                return q_recipient($segments[1]);
            }
            return q_recipients(['limit' => 50, 'offset' => 0]);

        case 'distributions':
            if (isset($segments[1])) {
                return q_distribution($segments[1]);
            }
            return q_distributions(['limit' => 50, 'offset' => 0]);

        case 'document-distributions':
            return q_document_distributions(['limit' => 50, 'offset' => 0]);

        case 'audit':
            return q_audit(['limit' => 50, 'offset' => 0]);

        default:
            return null;
    }
}

function mcp_dispatch($request)
{
    if (!is_array($request) || ($request['jsonrpc'] ?? '') !== '2.0' || !isset($request['method'])) {
        $id = is_array($request) && array_key_exists('id', $request) ? $request['id'] : null;
        return mcp_error_message($id, -32600, 'Invalid Request', 'Each request must be a JSON-RPC 2.0 object with a method.');
    }

    $id = array_key_exists('id', $request) ? $request['id'] : null;
    $isNotification = !array_key_exists('id', $request);
    $method = (string)$request['method'];
    $params = $request['params'] ?? [];

    if (str_starts_with($method, 'notifications/')) {
        return null;
    }

    if ($isNotification) {
        if (in_array($method, ['initialize', 'ping', 'tools/list', 'tools/call', 'resources/list', 'resources/read'], true)) {
            return mcp_error_message(null, -32600, 'Invalid Request', 'The method "' . $method . '" requires an id.');
        }
        return null;
    }

    switch ($method) {
        case 'initialize':
            return mcp_result($id, mcp_initialize($params));

        case 'ping':
            return mcp_result($id, []);

        case 'tools/list':
            return mcp_result($id, ['tools' => mcp_tools(), 'nextCursor' => null]);

        case 'tools/call':
            $name = is_array($params) ? ($params['name'] ?? '') : '';
            if (!is_string($name) || $name === '') {
                return mcp_error_message($id, -32602, 'Invalid params', 'tools/call requires a tool name.');
            }
            $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
            try {
                $data = mcp_run_tool($name, $args);
            } catch (InvalidArgumentException $e) {
                return mcp_error_message($id, -32602, 'Invalid params', $e->getMessage());
            } catch (Throwable $e) {
                return mcp_error_message($id, -32000, 'Server error', $e->getMessage());
            }
            return mcp_result($id, [
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]],
                'structuredContent' => $data,
                'isError' => false,
            ]);

        case 'resources/list':
            return mcp_result($id, ['resources' => mcp_resources(), 'nextCursor' => null]);

        case 'resources/templates/list':
            return mcp_result($id, ['resourceTemplates' => mcp_resource_templates()]);

        case 'resources/read':
            $uri = is_array($params) ? ($params['uri'] ?? '') : '';
            if (!is_string($uri) || $uri === '') {
                return mcp_error_message($id, -32602, 'Invalid params', 'resources/read requires a uri.');
            }
            $data = mcp_read_resource($uri);
            if ($data === null) {
                return mcp_error_message($id, -32002, 'Resource not found', 'No mailroom resource matches uri: ' . $uri);
            }
            return mcp_result($id, [
                'contents' => [[
                    'uri' => $uri,
                    'mimeType' => 'application/json',
                    'text' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]],
            ]);

        default:
            return mcp_error_message($id, -32601, 'Method not found', 'Unknown method: ' . $method);
    }
}

$raw = file_get_contents('php://input');
$request = json_decode($raw, true);

if (!is_array($request)) {
    mcp_send(mcp_error_message(null, -32700, 'Parse error', 'Request body must be valid JSON-RPC 2.0.'));
}

if (array_is_list($request)) {
    $responses = [];
    foreach ($request as $item) {
        $response = mcp_dispatch($item);
        if ($response !== null) {
            $responses[] = $response;
        }
    }
    if ($responses) {
        mcp_send($responses);
    }
    http_response_code(202);
    exit;
}

$response = mcp_dispatch($request);
if ($response !== null) {
    mcp_send($response);
}
http_response_code(202);