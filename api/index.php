<?php
// api/index.php - read-only REST JSON API for the mailroom system

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/query.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error(405, 'method_not_allowed', 'This API is read-only. Only GET requests are allowed.');
}

$route = trim($_SERVER['PATH_INFO'] ?? '', '/');
if ($route === '') {
    $route = trim($_GET['route'] ?? '', '/');
}
$segments = $route === '' ? [] : explode('/', $route);
$resource = $segments[0] ?? '';

if (!in_array($resource, ['ping'], true)) {
    api_authenticate();
}

$limit = api_clamp($_GET['limit'] ?? null, 1, 100, 20);
$offset = api_clamp($_GET['offset'] ?? null, 0, PHP_INT_MAX, 0);

switch ($resource) {
    case 'ping':
        api_ok(['status' => 'ok', 'service' => 'mailroom_system', 'time' => date('c')]);

    case 'stats':
        api_ok(q_stats());

    case 'search':
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) {
            api_error(400, 'short_query', 'Search query must be at least 2 characters.');
        }
        api_ok(q_search($q, $limit), ['query' => $q]);

    case 'documents':
        if (isset($segments[1])) {
            $doc = q_document($segments[1]);
            if (!$doc) {
                api_error(404, 'not_found', 'No document with id ' . $segments[1] . '.');
            }
            api_ok($doc);
        }
        api_ok_list(q_documents([
            'limit' => $limit,
            'offset' => $offset,
            'q' => trim($_GET['q'] ?? ''),
            'type_id' => $_GET['type_id'] ?? '',
        ]));

    case 'document-types':
        api_ok(q_document_types());

    case 'document-distributions':
        api_ok_list(q_document_distributions(['limit' => $limit, 'offset' => $offset]));

    case 'parcels':
        if (isset($segments[1]) && $segments[1] === 'tracking') {
            $parcel = q_parcel_by_tracking($segments[2] ?? '');
            if (!$parcel) {
                api_error(404, 'not_found', 'No parcel matches that tracking ID.');
            }
            api_ok($parcel);
        }
        if (isset($segments[1])) {
            $parcel = q_parcel($segments[1]);
            if (!$parcel) {
                api_error(404, 'not_found', 'No parcel with id ' . $segments[1] . '.');
            }
            api_ok($parcel);
        }
        $status = trim($_GET['status'] ?? '');
        if ($status !== '' && !in_array($status, parcelDeliveryStatuses(), true)) {
            api_error(400, 'invalid_status', 'Invalid parcel status. Allowed: ' . implode(', ', parcelDeliveryStatuses()) . '.');
        }
        api_ok_list(q_parcels([
            'limit' => $limit,
            'offset' => $offset,
            'q' => trim($_GET['q'] ?? ''),
            'status' => $status,
        ]));

    case 'newspapers':
        if (isset($segments[1])) {
            $newspaper = q_newspaper($segments[1]);
            if (!$newspaper) {
                api_error(404, 'not_found', 'No newspaper with id ' . $segments[1] . '.');
            }
            api_ok($newspaper);
        }
        api_ok_list(q_newspapers([
            'limit' => $limit,
            'offset' => $offset,
            'q' => trim($_GET['q'] ?? ''),
            'status' => trim($_GET['status'] ?? ''),
            'category_id' => $_GET['category_id'] ?? '',
        ]));

    case 'newspaper-categories':
        api_ok(q_newspaper_categories());

    case 'distributions':
        if (isset($segments[1])) {
            $distribution = q_distribution($segments[1]);
            if (!$distribution) {
                api_error(404, 'not_found', 'No newspaper distribution with id ' . $segments[1] . '.');
            }
            api_ok($distribution);
        }
        api_ok_list(q_distributions(['limit' => $limit, 'offset' => $offset]));

    case 'recipients':
        if (isset($segments[1])) {
            $recipient = q_recipient($segments[1]);
            if (!$recipient) {
                api_error(404, 'not_found', 'No recipient with id ' . $segments[1] . '.');
            }
            api_ok($recipient);
        }
        $active = $_GET['active'] ?? '';
        if ($active !== '' && $active !== '0' && $active !== '1') {
            api_error(400, 'invalid_active', 'The active filter must be 0 or 1.');
        }
        api_ok_list(q_recipients([
            'limit' => $limit,
            'offset' => $offset,
            'q' => trim($_GET['q'] ?? ''),
            'active' => $active,
        ]));

    case 'audit':
        api_ok_list(q_audit([
            'limit' => $limit,
            'offset' => $offset,
            'module' => trim($_GET['module'] ?? ''),
            'action_type' => trim($_GET['action_type'] ?? ''),
            'q' => trim($_GET['q'] ?? ''),
        ]));

    default:
        api_error(404, 'unknown_endpoint', 'Unknown endpoint: /' . $route . '.');
}