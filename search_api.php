<?php
// search_api.php - AJAX search endpoint for global search
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';

header('Content-Type: application/json');

$query = trim($_GET['q'] ?? '');
if (strlen($query) < 2) {
    echo json_encode(['results' => [], 'total' => 0]);
    exit;
}

$search = '%' . $conn->real_escape_string($query) . '%';
$results = [];
$total = 0;

// Search documents
$stmt = $conn->prepare("
    SELECT d.id, d.document_name, d.origin, d.date_received, d.copies_received,
           dt.type_name as type
    FROM documents d
    LEFT JOIN document_types dt ON d.type_id = dt.id
    WHERE d.document_name LIKE ? OR d.origin LIKE ? OR dt.type_name LIKE ?
    ORDER BY d.date_received DESC
    LIMIT 15
");
$stmt->bind_param("sss", $search, $search, $search);
$stmt->execute();
$docs = $stmt->get_result();
while ($row = $docs->fetch_assoc()) {
    $results[] = [
        'type' => 'document',
        'icon' => 'fa-file-lines',
        'color' => 'green',
        'title' => $row['document_name'] ?: 'Untitled Document',
        'detail' => ($row['type'] ?: 'Unknown type') . ' — ' . ($row['origin'] ?: 'No origin'),
        'meta' => $row['date_received'] ? date('M j, Y', strtotime($row['date_received'])) : '',
        'url' => 'documents.php'
    ];
    $total++;
}
$stmt->close();

// Search document types
$stmt = $conn->prepare("
    SELECT id, type_name, description
    FROM document_types
    WHERE type_name LIKE ? OR description LIKE ?
    LIMIT 5
");
$stmt->bind_param("ss", $search, $search);
$stmt->execute();
$types = $stmt->get_result();
while ($row = $types->fetch_assoc()) {
    $results[] = [
        'type' => 'document_type',
        'icon' => 'fa-tags',
        'color' => 'green',
        'title' => $row['type_name'],
        'detail' => $row['description'] ?: 'Document category',
        'meta' => '',
        'url' => 'document_type.php'
    ];
    $total++;
}
$stmt->close();

// Search parcels
$stmt = $conn->prepare("
    SELECT pr.id, pr.description, pr.sender, pr.addressed_to, pr.tracking_id, pr.date_received, pr.delivery_status,
           pp.id as pickup_id
    FROM parcels_received pr
    LEFT JOIN parcels_pickup pp ON pr.id = pp.parcel_id
    WHERE pr.tracking_id LIKE ? OR pr.description LIKE ? OR pr.sender LIKE ? OR pr.addressed_to LIKE ?
    ORDER BY pr.date_received DESC
    LIMIT 15
");
$stmt->bind_param("ssss", $search, $search, $search, $search);
$stmt->execute();
$parcels = $stmt->get_result();
while ($row = $parcels->fetch_assoc()) {
    $status = !empty($row['pickup_id']) ? 'picked' : ($row['delivery_status'] ?? 'received');
    $results[] = [
        'type' => 'parcel',
        'icon' => 'fa-box',
        'color' => 'blue',
        'title' => $row['tracking_id'] . ' — ' . ($row['addressed_to'] ?: 'Unknown'),
        'detail' => ($row['description'] ?: 'No description') . ' from ' . ($row['sender'] ?: 'Unknown'),
        'meta' => $row['date_received'] ? date('M j, Y', strtotime($row['date_received'])) : '',
        'url' => 'parcels.php',
        'badge' => $status === 'picked' ? 'Picked Up' : ucfirst($status)
    ];
    $total++;
}
$stmt->close();

// Search newspapers
$stmt = $conn->prepare("
    SELECT n.id, n.newspaper_name, n.newspaper_number, n.date_received, n.total_copies, n.available_copies, nc.category_name
    FROM newspapers n
    LEFT JOIN newspaper_categories nc ON n.category_id = nc.id
    WHERE n.newspaper_name LIKE ? OR n.newspaper_number LIKE ? OR nc.category_name LIKE ?
    ORDER BY n.date_received DESC
    LIMIT 15
");
$stmt->bind_param("sss", $search, $search, $search);
$stmt->execute();
$news = $stmt->get_result();
while ($row = $news->fetch_assoc()) {
    $results[] = [
        'type' => 'newspaper',
        'icon' => 'fa-newspaper',
        'color' => 'gray',
        'title' => $row['newspaper_name'] ?: 'Unknown',
        'detail' => ($row['newspaper_number'] ?: '') . ' — ' . ($row['category_name'] ?: 'Uncategorized'),
        'meta' => $row['date_received'] ? date('M j, Y', strtotime($row['date_received'])) : '',
        'url' => 'list.php'
    ];
    $total++;
}
$stmt->close();

// Search recipients
$stmt = $conn->prepare("
    SELECT id, name
    FROM recipients
    WHERE name LIKE ? AND is_active = 1
    LIMIT 10
");
$stmt->bind_param("s", $search);
$stmt->execute();
$recips = $stmt->get_result();
while ($row = $recips->fetch_assoc()) {
    $results[] = [
        'type' => 'recipient',
        'icon' => 'fa-user',
        'color' => 'orange',
        'title' => $row['name'],
        'detail' => 'Newspaper recipient',
        'meta' => '',
        'url' => 'recipients.php'
    ];
    $total++;
}
$stmt->close();

// Search newspaper distribution
$stmt = $conn->prepare("
    SELECT id, distributed_to, department, date_distributed, copies, categories_list
    FROM distribution
    WHERE distributed_to LIKE ? OR department LIKE ? OR categories_list LIKE ?
    ORDER BY date_distributed DESC
    LIMIT 10
");
$stmt->bind_param("sss", $search, $search, $search);
$stmt->execute();
$dist = $stmt->get_result();
while ($row = $dist->fetch_assoc()) {
    $results[] = [
        'type' => 'distribution',
        'icon' => 'fa-share-from-square',
        'color' => 'orange',
        'title' => $row['distributed_to'] . ($row['department'] ? ' (' . $row['department'] . ')' : ''),
        'detail' => 'Newspaper distribution — ' . $row['copies'] . ' copies',
        'meta' => $row['date_distributed'] ? date('M j, Y', strtotime($row['date_distributed'])) : '',
        'url' => 'distribution_history.php'
    ];
    $total++;
}
$stmt->close();

echo json_encode(['results' => $results, 'total' => $total]);
