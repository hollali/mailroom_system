<?php
// api/query.php - read-only query functions shared by the REST and MCP endpoints

function q_stats()
{
    global $conn;
    $one = function ($sql) use ($conn) {
        $result = $conn->query($sql);
        if (!$result) {
            return 0;
        }
        $row = $result->fetch_row();
        return (int)($row[0] ?? 0);
    };

    $recent = q_audit(['limit' => 5, 'offset' => 0]);

    return [
        'documents' => [
            'total' => $one('SELECT COUNT(*) FROM documents'),
            'types' => $one('SELECT COUNT(*) FROM document_types'),
            'copies_received' => $one('SELECT COALESCE(SUM(copies_received), 0) FROM documents'),
        ],
        'parcels' => [
            'total' => $one('SELECT COUNT(*) FROM parcels_received'),
            'picked' => $one('SELECT COUNT(*) FROM parcels_pickup'),
            'pending' => $one('SELECT COUNT(*) FROM parcels_received pr LEFT JOIN parcels_pickup pp ON pp.parcel_id = pr.id WHERE pp.id IS NULL'),
        ],
        'newspapers' => [
            'total' => $one('SELECT COUNT(*) FROM newspapers'),
            'categories' => $one('SELECT COUNT(*) FROM newspaper_categories'),
            'total_copies' => $one('SELECT COALESCE(SUM(total_copies), 0) FROM newspapers'),
            'available_copies' => $one('SELECT COALESCE(SUM(available_copies), 0) FROM newspapers'),
        ],
        'distributions' => [
            'newspaper_distributions' => $one('SELECT COUNT(*) FROM distribution'),
            'document_distributions' => $one('SELECT COUNT(*) FROM document_distribution'),
            'document_copies_distributed' => $one('SELECT COALESCE(SUM(number_distributed), 0) FROM document_distribution'),
        ],
        'recent_activity' => $recent['rows'],
    ];
}

function q_search($q, $limit = 15)
{
    global $conn;
    $q = trim($q);
    if (strlen($q) < 2) {
        return ['results' => [], 'total' => 0];
    }
    $like = '%' . $conn->real_escape_string($q) . '%';
    $limit = (int)$limit;
    $results = [];

    $stmt = $conn->prepare("
        SELECT d.id, d.document_name, d.origin, d.date_received, d.copies_received,
               COALESCE(dt.type_name, d.type) AS type
        FROM documents d
        LEFT JOIN document_types dt ON d.type_id = dt.id
        WHERE d.document_name LIKE ? OR d.origin LIKE ? OR dt.type_name LIKE ?
        ORDER BY d.date_received DESC
        LIMIT ?
    ");
    $stmt->bind_param("sssi", $like, $like, $like, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $results[] = [
            'type' => 'document',
            'id' => (int)$row['id'],
            'title' => $row['document_name'] ?: 'Untitled Document',
            'detail' => ($row['type'] ?: 'Unknown type') . ' — ' . ($row['origin'] ?: 'No origin'),
            'date' => $row['date_received'],
        ];
    }
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT id, type_name, description FROM document_types
        WHERE type_name LIKE ? OR description LIKE ?
        LIMIT ?
    ");
    $stmt->bind_param("ssi", $like, $like, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $results[] = [
            'type' => 'document_type',
            'id' => (int)$row['id'],
            'title' => $row['type_name'],
            'detail' => $row['description'] ?: 'Document category',
            'date' => null,
        ];
    }
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT pr.id, pr.description, pr.sender, pr.addressed_to, pr.tracking_id, pr.date_received, pr.delivery_status,
               pp.picked_by
        FROM parcels_received pr
        LEFT JOIN parcels_pickup pp ON pr.id = pp.parcel_id
        WHERE pr.tracking_id LIKE ? OR pr.description LIKE ? OR pr.sender LIKE ? OR pr.addressed_to LIKE ?
        ORDER BY pr.date_received DESC
        LIMIT ?
    ");
    $stmt->bind_param("ssssi", $like, $like, $like, $like, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $status = !empty($row['picked_by']) ? 'picked' : ($row['delivery_status'] ?? 'received');
        $results[] = [
            'type' => 'parcel',
            'id' => (int)$row['id'],
            'title' => $row['tracking_id'] . ' — ' . ($row['addressed_to'] ?: 'Unknown'),
            'detail' => ($row['description'] ?: 'No description') . ' from ' . ($row['sender'] ?: 'Unknown'),
            'date' => $row['date_received'],
            'tracking_id' => $row['tracking_id'],
            'status' => $status,
        ];
    }
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT n.id, n.newspaper_name, n.newspaper_number, n.date_received, n.total_copies, n.available_copies, nc.category_name
        FROM newspapers n
        LEFT JOIN newspaper_categories nc ON n.category_id = nc.id
        WHERE n.newspaper_name LIKE ? OR n.newspaper_number LIKE ? OR nc.category_name LIKE ?
        ORDER BY n.date_received DESC
        LIMIT ?
    ");
    $stmt->bind_param("sssi", $like, $like, $like, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $results[] = [
            'type' => 'newspaper',
            'id' => (int)$row['id'],
            'title' => $row['newspaper_name'] ?: 'Unknown',
            'detail' => ($row['newspaper_number'] ?: '') . ' — ' . ($row['category_name'] ?: 'Uncategorized'),
            'date' => $row['date_received'],
        ];
    }
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT id, name FROM recipients
        WHERE name LIKE ? AND is_active = 1
        LIMIT ?
    ");
    $stmt->bind_param("si", $like, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $results[] = [
            'type' => 'recipient',
            'id' => (int)$row['id'],
            'title' => $row['name'],
            'detail' => 'Newspaper recipient',
            'date' => null,
        ];
    }
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT id, distributed_to, department, date_distributed, copies, categories_list
        FROM distribution
        WHERE distributed_to LIKE ? OR department LIKE ? OR categories_list LIKE ?
        ORDER BY date_distributed DESC
        LIMIT ?
    ");
    $stmt->bind_param("sssi", $like, $like, $like, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $results[] = [
            'type' => 'newspaper_distribution',
            'id' => (int)$row['id'],
            'title' => $row['distributed_to'] . ($row['department'] ? ' (' . $row['department'] . ')' : ''),
            'detail' => 'Newspaper distribution — ' . $row['copies'] . ' copies',
            'date' => $row['date_distributed'],
        ];
    }
    $stmt->close();

    return ['results' => $results, 'total' => count($results)];
}

function q_documents($opts = [])
{
    $limit = (int)($opts['limit'] ?? 20);
    $offset = (int)($opts['offset'] ?? 0);
    $q = trim($opts['q'] ?? '');
    $typeId = ($opts['type_id'] ?? '') !== '' ? (int)$opts['type_id'] : null;

    $where = [];
    $types = '';
    $params = [];

    if ($typeId !== null) {
        $where[] = 'd.type_id = ?';
        $types .= 'i';
        $params[] = $typeId;
    }
    if ($q !== '') {
        $where[] = '(d.document_name LIKE ? OR d.origin LIKE ? OR COALESCE(dt.type_name, "") LIKE ?)';
        $like = '%' . $q . '%';
        $types .= 'sss';
        array_push($params, $like, $like, $like);
    }

    $join = 'FROM documents d LEFT JOIN document_types dt ON d.type_id = dt.id';
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $select = 'SELECT d.id, d.document_name, d.type_id, d.type, COALESCE(dt.type_name, d.type) AS type_name, d.origin, d.copies_received, d.date_received, d.created_at, COALESCE((SELECT SUM(number_distributed) FROM document_distribution WHERE document_id = d.id), 0) AS distributed';

    $total = api_count('SELECT COUNT(*) AS c ' . $join . $whereSql, $types, $params);
    $result = api_query($select . ' ' . $join . $whereSql . ' ORDER BY d.date_received DESC, d.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset, $types, $params);

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        unset($row['type']);
        $row['id'] = (int)$row['id'];
        $row['type_id'] = $row['type_id'] ? (int)$row['type_id'] : null;
        $row['copies_received'] = (int)$row['copies_received'];
        $row['distributed'] = (int)$row['distributed'];
        $rows[] = $row;
    }

    return ['total' => $total, 'rows' => $rows, 'limit' => $limit, 'offset' => $offset];
}

function q_document($id)
{
    if (!is_numeric($id) || (int)$id < 1) {
        return null;
    }
    $id = (int)$id;
    $result = api_query("
        SELECT d.id, d.document_name, d.type_id, d.type, COALESCE(dt.type_name, d.type) AS type_name, d.origin, d.copies_received, d.date_received, d.created_at, COALESCE((SELECT SUM(number_distributed) FROM document_distribution WHERE document_id = d.id), 0) AS distributed
        FROM documents d
        LEFT JOIN document_types dt ON d.type_id = dt.id
        WHERE d.id = ?
    ", 'i', [$id]);
    $row = $result->fetch_assoc();
    if (!$row) {
        return null;
    }
    unset($row['type']);
    $row['id'] = (int)$row['id'];
    $row['type_id'] = $row['type_id'] ? (int)$row['type_id'] : null;
    $row['copies_received'] = (int)$row['copies_received'];
    $row['distributed'] = (int)$row['distributed'];
    $row['distributions'] = q_document_distributions(['document_id' => $id, 'limit' => 100, 'offset' => 0])['rows'];
    return $row;
}

function q_document_types()
{
    $result = api_query('SELECT id, type_name, description, created_at FROM document_types ORDER BY type_name ASC');
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $rows[] = $row;
    }
    return $rows;
}

function q_document_distributions($opts = [])
{
    $limit = (int)($opts['limit'] ?? 20);
    $offset = (int)($opts['offset'] ?? 0);
    $documentId = isset($opts['document_id']) && $opts['document_id'] !== '' ? (int)$opts['document_id'] : null;

    $where = [];
    $types = '';
    $params = [];
    if ($documentId !== null) {
        $where[] = 'dd.document_id = ?';
        $types .= 'i';
        $params[] = $documentId;
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $join = 'FROM document_distribution dd LEFT JOIN documents d ON dd.document_id = d.id';
    $total = api_count('SELECT COUNT(*) AS c ' . $join . $whereSql, $types, $params);
    $result = api_query('SELECT dd.id, dd.document_id, d.document_name, dd.number_received, dd.number_distributed, dd.date_distributed, dd.status, dd.created_at ' . $join . $whereSql . ' ORDER BY dd.date_distributed DESC, dd.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset, $types, $params);

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['document_id'] = (int)$row['document_id'];
        $row['number_received'] = (int)$row['number_received'];
        $row['number_distributed'] = (int)$row['number_distributed'];
        $rows[] = $row;
    }

    return ['total' => $total, 'rows' => $rows, 'limit' => $limit, 'offset' => $offset];
}

function q_parcel_row($row)
{
    $picked = !empty($row['picked_by']);
    $statusRaw = $picked ? 'picked' : ($row['delivery_status'] ?? 'received');
    $pickup = $picked ? [
        'picked_by' => $row['picked_by'],
        'phone_number' => $row['phone_number'],
        'designation' => $row['designation'],
        'date_picked' => $row['date_picked'],
        'picked_at' => $row['picked_at'],
    ] : null;
    unset($row['pickup_id'], $row['picked_by'], $row['phone_number'], $row['designation'], $row['date_picked'], $row['picked_at']);
    $row['id'] = (int)$row['id'];
    $row['delivery_status'] = $statusRaw;
    $row['status'] = $statusRaw;
    $row['status_label'] = ucwords(str_replace('_', ' ', $statusRaw));
    $row['pickup'] = $pickup;
    return $row;
}

function q_parcels($opts = [])
{
    $limit = (int)($opts['limit'] ?? 20);
    $offset = (int)($opts['offset'] ?? 0);
    $q = trim($opts['q'] ?? '');
    $status = trim($opts['status'] ?? '');

    $where = [];
    $types = '';
    $params = [];
    if ($status !== '') {
        if ($status === 'picked') {
            $where[] = 'pp.id IS NOT NULL';
        } else {
            $where[] = 'pp.id IS NULL AND pr.delivery_status = ?';
            $types .= 's';
            $params[] = $status;
        }
    }
    if ($q !== '') {
        $where[] = '(pr.tracking_id LIKE ? OR pr.description LIKE ? OR pr.sender LIKE ? OR pr.addressed_to LIKE ?)';
        $like = '%' . $q . '%';
        $types .= 'ssss';
        array_push($params, $like, $like, $like, $like);
    }

    $join = 'FROM parcels_received pr LEFT JOIN parcels_pickup pp ON pp.parcel_id = pr.id';
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $select = 'SELECT pr.*, pp.id AS pickup_id, pp.picked_by, pp.phone_number, pp.designation, pp.date_picked, pp.picked_at';

    $total = api_count('SELECT COUNT(*) AS c ' . $join . $whereSql, $types, $params);
    $result = api_query($select . ' ' . $join . $whereSql . ' GROUP BY pr.id ORDER BY pr.received_at DESC, pr.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset, $types, $params);

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = q_parcel_row($row);
    }

    return ['total' => $total, 'rows' => $rows, 'limit' => $limit, 'offset' => $offset];
}

function q_parcel($id)
{
    if (!is_numeric($id) || (int)$id < 1) {
        return null;
    }
    $id = (int)$id;
    $result = api_query("
        SELECT pr.*, pp.id AS pickup_id, pp.picked_by, pp.phone_number, pp.designation, pp.date_picked, pp.picked_at
        FROM parcels_received pr
        LEFT JOIN parcels_pickup pp ON pp.parcel_id = pr.id
        WHERE pr.id = ?
    ", 'i', [$id]);
    $row = $result->fetch_assoc();
    return $row ? q_parcel_row($row) : null;
}

function q_parcel_by_tracking($trackingId)
{
    $trackingId = trim($trackingId);
    if ($trackingId === '') {
        return null;
    }
    $result = api_query("
        SELECT pr.*, pp.id AS pickup_id, pp.picked_by, pp.phone_number, pp.designation, pp.date_picked, pp.picked_at
        FROM parcels_received pr
        LEFT JOIN parcels_pickup pp ON pp.parcel_id = pr.id
        WHERE pr.tracking_id = ?
    ", 's', [$trackingId]);
    $row = $result->fetch_assoc();
    return $row ? q_parcel_row($row) : null;
}

function q_newspapers($opts = [])
{
    $limit = (int)($opts['limit'] ?? 20);
    $offset = (int)($opts['offset'] ?? 0);
    $q = trim($opts['q'] ?? '');
    $status = trim($opts['status'] ?? '');
    $categoryId = ($opts['category_id'] ?? '') !== '' ? (int)$opts['category_id'] : null;

    $where = [];
    $types = '';
    $params = [];
    if ($status !== '') {
        $where[] = 'n.status = ?';
        $types .= 's';
        $params[] = $status;
    }
    if ($categoryId !== null) {
        $where[] = 'n.category_id = ?';
        $types .= 'i';
        $params[] = $categoryId;
    }
    if ($q !== '') {
        $where[] = '(n.newspaper_name LIKE ? OR n.newspaper_number LIKE ? OR COALESCE(nc.category_name, "") LIKE ?)';
        $like = '%' . $q . '%';
        $types .= 'sss';
        array_push($params, $like, $like, $like);
    }

    $join = 'FROM newspapers n LEFT JOIN newspaper_categories nc ON n.category_id = nc.id';
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $select = 'SELECT n.*, nc.category_name';

    $total = api_count('SELECT COUNT(*) AS c ' . $join . $whereSql, $types, $params);
    $result = api_query($select . ' ' . $join . $whereSql . ' ORDER BY n.date_received DESC, n.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset, $types, $params);

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['category_id'] = $row['category_id'] ? (int)$row['category_id'] : null;
        $row['total_copies'] = (int)$row['total_copies'];
        $row['available_copies'] = (int)$row['available_copies'];
        $rows[] = $row;
    }

    return ['total' => $total, 'rows' => $rows, 'limit' => $limit, 'offset' => $offset];
}

function q_newspaper($id)
{
    if (!is_numeric($id) || (int)$id < 1) {
        return null;
    }
    $id = (int)$id;
    $result = api_query("
        SELECT n.*, nc.category_name
        FROM newspapers n
        LEFT JOIN newspaper_categories nc ON n.category_id = nc.id
        WHERE n.id = ?
    ", 'i', [$id]);
    $row = $result->fetch_assoc();
    if (!$row) {
        return null;
    }
    $row['id'] = (int)$row['id'];
    $row['category_id'] = $row['category_id'] ? (int)$row['category_id'] : null;
    $row['total_copies'] = (int)$row['total_copies'];
    $row['available_copies'] = (int)$row['available_copies'];
    return $row;
}

function q_newspaper_categories()
{
    $result = api_query('SELECT id, category_name, newspaper_id, description, created_at FROM newspaper_categories ORDER BY category_name ASC');
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['newspaper_id'] = $row['newspaper_id'] ? (int)$row['newspaper_id'] : null;
        $rows[] = $row;
    }
    return $rows;
}

function q_distributions($opts = [])
{
    $limit = (int)($opts['limit'] ?? 20);
    $offset = (int)($opts['offset'] ?? 0);
    $whereSql = '';
    $types = '';
    $params = [];

    $join = 'FROM distribution d LEFT JOIN newspapers nw ON d.newspaper_id = nw.id';
    $total = api_count('SELECT COUNT(*) AS c ' . $join . $whereSql, $types, $params);
    $result = api_query('SELECT d.id, d.newspaper_id, nw.newspaper_name, nw.newspaper_number, d.distributed_to, d.department, d.copies, d.date_distributed, d.distributed_by, d.newspaper_ids, d.newspapers_list, d.categories_list ' . $join . ' ORDER BY d.date_distributed DESC, d.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset, $types, $params);

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['newspaper_id'] = $row['newspaper_id'] ? (int)$row['newspaper_id'] : null;
        $row['copies'] = (int)$row['copies'];
        $rows[] = $row;
    }

    return ['total' => $total, 'rows' => $rows, 'limit' => $limit, 'offset' => $offset];
}

function q_distribution($id)
{
    if (!is_numeric($id) || (int)$id < 1) {
        return null;
    }
    $id = (int)$id;
    $result = api_query("
        SELECT d.id, d.newspaper_id, nw.newspaper_name, nw.newspaper_number, d.distributed_to, d.department, d.copies, d.date_distributed, d.distributed_by, d.newspaper_ids, d.newspapers_list, d.categories_list
        FROM distribution d
        LEFT JOIN newspapers nw ON d.newspaper_id = nw.id
        WHERE d.id = ?
    ", 'i', [$id]);
    $row = $result->fetch_assoc();
    if (!$row) {
        return null;
    }
    $row['id'] = (int)$row['id'];
    $row['newspaper_id'] = $row['newspaper_id'] ? (int)$row['newspaper_id'] : null;
    $row['copies'] = (int)$row['copies'];
    return $row;
}

function q_recipients($opts = [])
{
    $limit = (int)($opts['limit'] ?? 20);
    $offset = (int)($opts['offset'] ?? 0);
    $q = trim($opts['q'] ?? '');
    $active = ($opts['active'] ?? '') !== '' ? (int)$opts['active'] : null;

    $where = [];
    $types = '';
    $params = [];
    if ($active !== null) {
        $where[] = 'r.is_active = ?';
        $types .= 'i';
        $params[] = $active;
    }
    if ($q !== '') {
        $where[] = 'r.name LIKE ?';
        $types .= 's';
        $params[] = '%' . $q . '%';
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $total = api_count('SELECT COUNT(*) AS c FROM recipients r' . $whereSql, $types, $params);
    $result = api_query('SELECT r.id, r.name, r.is_active, r.created_at FROM recipients r' . $whereSql . ' ORDER BY r.name ASC LIMIT ' . $limit . ' OFFSET ' . $offset, $types, $params);

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['is_active'] = (int)$row['is_active'];
        $row['subscriptions'] = [];
        $rows[] = $row;
    }

    if ($rows) {
        $ids = array_column($rows, 'id');
        $in = implode(',', array_map('intval', $ids));
        $subResult = api_query("
            SELECT rcs.recipient_id, nc.id AS category_id, nc.category_name, rcs.created_at AS subscribed_on
            FROM recipient_category_subscriptions rcs
            LEFT JOIN newspaper_categories nc ON rcs.category_id = nc.id
            WHERE rcs.recipient_id IN ($in)
            ORDER BY nc.category_name ASC
        ");
        $subs = [];
        while ($s = $subResult->fetch_assoc()) {
            $subs[(int)$s['recipient_id']][] = [
                'category_id' => (int)$s['category_id'],
                'category_name' => $s['category_name'],
                'subscribed_on' => $s['subscribed_on'],
            ];
        }
        foreach ($rows as &$r) {
            $r['subscriptions'] = $subs[$r['id']] ?? [];
        }
        unset($r);
    }

    return ['total' => $total, 'rows' => $rows, 'limit' => $limit, 'offset' => $offset];
}

function q_recipient($id)
{
    if (!is_numeric($id) || (int)$id < 1) {
        return null;
    }
    $id = (int)$id;
    $result = api_query('SELECT r.id, r.name, r.is_active, r.created_at FROM recipients r WHERE r.id = ?', 'i', [$id]);
    $row = $result->fetch_assoc();
    if (!$row) {
        return null;
    }
    $row['id'] = (int)$row['id'];
    $row['is_active'] = (int)$row['is_active'];

    $subResult = api_query("
        SELECT nc.id AS category_id, nc.category_name, rcs.created_at AS subscribed_on
        FROM recipient_category_subscriptions rcs
        LEFT JOIN newspaper_categories nc ON rcs.category_id = nc.id
        WHERE rcs.recipient_id = ?
        ORDER BY nc.category_name ASC
    ", 'i', [$id]);
    $row['subscriptions'] = [];
    while ($s = $subResult->fetch_assoc()) {
        $row['subscriptions'][] = [
            'category_id' => (int)$s['category_id'],
            'category_name' => $s['category_name'],
            'subscribed_on' => $s['subscribed_on'],
        ];
    }
    return $row;
}

function q_audit($opts = [])
{
    $limit = (int)($opts['limit'] ?? 20);
    $offset = (int)($opts['offset'] ?? 0);
    $module = trim($opts['module'] ?? '');
    $actionType = trim($opts['action_type'] ?? '');
    $q = trim($opts['q'] ?? '');

    $where = [];
    $types = '';
    $params = [];
    if ($module !== '') {
        $where[] = 'module = ?';
        $types .= 's';
        $params[] = $module;
    }
    if ($actionType !== '') {
        $where[] = 'action_type = ?';
        $types .= 's';
        $params[] = $actionType;
    }
    if ($q !== '') {
        $where[] = '(entity_name LIKE ? OR description LIKE ? OR user LIKE ?)';
        $like = '%' . $q . '%';
        $types .= 'sss';
        array_push($params, $like, $like, $like);
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $total = api_count('SELECT COUNT(*) AS c FROM audit_logs' . $whereSql, $types, $params);
    $result = api_query('SELECT id, action_type, module, entity_id, entity_name, description, details, user, ip_address, created_at FROM audit_logs' . $whereSql . ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset, $types, $params);

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['entity_id'] = $row['entity_id'] ? (int)$row['entity_id'] : null;
        $rows[] = $row;
    }

    return ['total' => $total, 'rows' => $rows, 'limit' => $limit, 'offset' => $offset];
}