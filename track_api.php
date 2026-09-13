<?php
// track_api.php - JSON tracking data endpoint for the tracking modal
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';

header('Content-Type: application/json');

$tracking_id = trim($_GET['tracking'] ?? '');
if ($tracking_id === '') {
    echo json_encode(['success' => false, 'message' => 'Please enter a tracking ID.']);
    exit;
}

$stmt = $conn->prepare("
    SELECT pr.*, pp.picked_by, pp.phone_number, pp.designation, pp.date_picked, pp.picked_at
    FROM parcels_received pr
    LEFT JOIN parcels_pickup pp ON pr.id = pp.parcel_id
    WHERE pr.tracking_id = ?
");
$stmt->bind_param("s", $tracking_id);
$stmt->execute();
$result = $stmt->get_result();
$parcel = $result->fetch_assoc();
$stmt->close();

if (!$parcel) {
    echo json_encode(['success' => false, 'message' => 'No parcel matches tracking ID ' . $tracking_id . '.']);
    exit;
}

$is_picked = !empty($parcel['picked_by']);
$status_raw = $parcel['delivery_status'] ?? 'received';
if ($is_picked) {
    $status_raw = 'picked';
}

$timeline = [
    ['key' => 'received', 'label' => 'Received', 'desc' => 'Parcel logged at the mailroom', 'icon' => 'fa-inbox'],
    ['key' => 'in_transit', 'label' => 'In Transit', 'desc' => 'Handed to dispatch / courier', 'icon' => 'fa-truck-moving'],
    ['key' => 'out_for_delivery', 'label' => 'Out for Delivery', 'desc' => 'On its way to destination', 'icon' => 'fa-truck-fast'],
    ['key' => 'delivered', 'label' => 'Delivered', 'desc' => 'Handed to the recipient', 'icon' => 'fa-circle-check'],
    ['key' => 'picked', 'label' => 'Picked Up', 'desc' => 'Parcel collected at the mailroom', 'icon' => 'fa-box-open'],
];

$step_keys = array_map(fn($s) => $s['key'], $timeline);
$status_rank = array_search($status_raw, $step_keys);
$status_rank = $status_rank === false ? 0 : $status_rank;

echo json_encode([
    'success' => true,
    'parcel' => [
        'tracking_id' => $parcel['tracking_id'],
        'description' => $parcel['description'],
        'sender' => $parcel['sender'],
        'addressed_to' => $parcel['addressed_to'],
        'date_received' => $parcel['date_received'],
        'received_by' => $parcel['received_by'],
        'status_raw' => $status_raw,
        'status_label' => ucwords(str_replace('_', ' ', $status_raw)),
        'is_picked' => $is_picked,
        'picked_by' => $parcel['picked_by'],
        'phone_number' => $parcel['phone_number'],
        'designation' => $parcel['designation'],
        'picked_at' => $parcel['picked_at'] ?: $parcel['date_picked'],
        'badge_html' => parcelStatusBadge($status_raw, $is_picked),
    ],
    'status_rank' => $status_rank,
    'timeline' => $timeline,
]);