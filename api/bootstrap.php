<?php
// api/bootstrap.php - shared bootstrap for the mailroom API endpoints

ob_start();
require_once __DIR__ . '/../config/db.php';
ob_end_clean();

require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!isset($conn) || !$conn instanceof mysqli) {
    api_error(503, 'db_unavailable', 'Database connection failed.');
    exit;
}

function api_expected_key()
{
    return $_ENV['MAILROOM_API_KEY'] ?? getenv('MAILROOM_API_KEY') ?: '';
}

function api_key_from_request()
{
    $key = trim($_SERVER['HTTP_X_API_KEY'] ?? '');
    if ($key === '') {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            $key = trim($m[1]);
        }
    }
    if ($key === '') {
        $key = trim($_GET['api_key'] ?? '');
    }
    return $key;
}

function api_authenticate()
{
    $expected = api_expected_key();
    if ($expected === '') {
        api_error(503, 'api_not_configured', 'MAILROOM_API_KEY is not configured in the .env file.', [
            'hint' => 'Add MAILROOM_API_KEY=your-long-secret to the .env file'
        ]);
        exit;
    }
    if (!hash_equals($expected, api_key_from_request())) {
        api_error(401, 'unauthorized', 'Invalid or missing API key. Send it via the X-API-Key header.');
        exit;
    }
}

function api_ok($data, $meta = [])
{
    $payload = ['success' => true, 'data' => $data];
    if ($meta) {
        $payload['meta'] = $meta;
    }
    echo api_json($payload);
    exit;
}

function api_ok_list(array $result)
{
    api_ok($result['rows'], [
        'count' => count($result['rows']),
        'total' => $result['total'],
        'limit' => $result['limit'],
        'offset' => $result['offset'],
    ]);
}

function api_error($status, $code, $message, $extra = [])
{
    http_response_code($status);
    $error = array_merge(['code' => $code, 'message' => $message], $extra);
    echo api_json(['success' => false, 'error' => $error]);
    exit;
}

function api_json($value)
{
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function api_clamp($value, $min, $max, $default)
{
    if (!is_numeric($value)) {
        return $default;
    }
    return max($min, min($max, (int)$value));
}

function api_query($sql, $types = '', $params = [])
{
    global $conn;
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        api_error(500, 'db_error', 'Query preparation failed.', ['sql_error' => $conn->error]);
    }
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        api_error(500, 'db_error', 'Query execution failed.', ['sql_error' => $stmt->error]);
    }
    return $stmt->get_result();
}

function api_count($sql, $types = '', $params = [])
{
    $result = api_query($sql, $types, $params);
    $row = $result->fetch_assoc();
    return (int)($row['c'] ?? 0);
}