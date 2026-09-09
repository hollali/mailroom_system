<?php
require_once './config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/csrf.php';
session_start();

$message = '';
$error = '';

$parcels_received_has_timestamp = tableHasColumn($conn, 'parcels_received', 'received_at');
$parcels_pickup_has_timestamp = tableHasColumn($conn, 'parcels_pickup', 'picked_at');
$received_timestamp_select = $parcels_received_has_timestamp ? "COALESCE(pr.received_at, pr.date_received) as received_timestamp" : "pr.date_received as received_timestamp";
$picked_timestamp_select = $parcels_pickup_has_timestamp ? "COALESCE(pp.picked_at, pp.date_picked) as picked_timestamp" : "pp.date_picked as picked_timestamp";

// Handle new parcel receipt
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'receive') {
    csrf_check_post();
    // Generate unique tracking ID
    $tracking_id = 'PRCL-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));

    $description = $_POST['description'];
    $sender = $_POST['sender'];
    $addressed_to = $_POST['addressed_to'];
    $received_by = $_POST['received_by'];
    $date_received = $_POST['date_received'];
    $normalized_received_timestamp = normalizeDateTimeInput($date_received);
    $date_received_only = $normalized_received_timestamp ? date('Y-m-d', strtotime($normalized_received_timestamp)) : null;

    if ($parcels_received_has_timestamp) {
        $stmt = $conn->prepare("INSERT INTO parcels_received (description, sender, addressed_to, date_received, received_by, tracking_id, received_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssss", $description, $sender, $addressed_to, $date_received_only, $received_by, $tracking_id, $normalized_received_timestamp);
    } else {
        $stmt = $conn->prepare("INSERT INTO parcels_received (description, sender, addressed_to, date_received, received_by, tracking_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssss", $description, $sender, $addressed_to, $date_received_only, $received_by, $tracking_id);
    }

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => "Parcel received successfully! Tracking ID: $tracking_id"]);
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => "Error: " . $conn->error]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'edit_received') {
    csrf_check_post();
    header('Content-Type: application/json');

    $parcel_id = isset($_POST['parcel_id']) ? (int)$_POST['parcel_id'] : 0;
    $description = trim($_POST['description'] ?? '');
    $sender = trim($_POST['sender'] ?? '');
    $addressed_to = trim($_POST['addressed_to'] ?? '');
    $received_by = trim($_POST['received_by'] ?? '');

    if ($parcel_id <= 0 || $description === '' || $sender === '' || $addressed_to === '' || $received_by === '') {
        echo json_encode(['success' => false, 'message' => 'All fields are required']);
        exit;
    }

    $check_stmt = $conn->prepare("
        SELECT pr.id, pr.tracking_id, pp.id AS pickup_id
        FROM parcels_received pr
        LEFT JOIN parcels_pickup pp ON pr.id = pp.parcel_id
        WHERE pr.id = ?
    ");
    $check_stmt->bind_param("i", $parcel_id);
    $check_stmt->execute();
    $parcel = $check_stmt->get_result()->fetch_assoc();
    $check_stmt->close();

    if (!$parcel) {
        echo json_encode(['success' => false, 'message' => 'Parcel not found']);
        exit;
    }

    if (!empty($parcel['pickup_id'])) {
        echo json_encode(['success' => false, 'message' => 'Only pending parcels can be edited']);
        exit;
    }

    $stmt = $conn->prepare("UPDATE parcels_received SET description = ?, sender = ?, addressed_to = ?, received_by = ? WHERE id = ?");
    $stmt->bind_param("ssssi", $description, $sender, $addressed_to, $received_by, $parcel_id);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Parcel ' . $parcel['tracking_id'] . ' updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Update failed: ' . $conn->error]);
    }
    $stmt->close();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete_received') {
    csrf_check_post();
    header('Content-Type: application/json');

    $parcel_id = isset($_POST['parcel_id']) ? (int)$_POST['parcel_id'] : 0;

    if ($parcel_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid parcel selected']);
        exit;
    }

    $check_stmt = $conn->prepare("SELECT id, tracking_id FROM parcels_received WHERE id = ?");
    $check_stmt->bind_param("i", $parcel_id);
    $check_stmt->execute();
    $parcel = $check_stmt->get_result()->fetch_assoc();
    $check_stmt->close();

    if (!$parcel) {
        echo json_encode(['success' => false, 'message' => 'Parcel not found']);
        exit;
    }

    $conn->begin_transaction();

    try {
        $delete_pickup_stmt = $conn->prepare("DELETE FROM parcels_pickup WHERE parcel_id = ?");
        $delete_pickup_stmt->bind_param("i", $parcel_id);
        if (!$delete_pickup_stmt->execute()) {
            throw new Exception($delete_pickup_stmt->error);
        }
        $delete_pickup_stmt->close();

        $delete_received_stmt = $conn->prepare("DELETE FROM parcels_received WHERE id = ?");
        $delete_received_stmt->bind_param("i", $parcel_id);
        if (!$delete_received_stmt->execute()) {
            throw new Exception($delete_received_stmt->error);
        }
        $delete_received_stmt->close();

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Parcel ' . $parcel['tracking_id'] . ' deleted successfully']);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()]);
    }

    exit;
}

// Handle pickup
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['parcel_id'])) {
    csrf_check_post();
    $parcel_id = (int)$_POST['parcel_id'];
    $picked_by = $_POST['picked_by'];
    $phone_number = $_POST['phone_number'];
    $designation = $_POST['designation'];
    $picked_timestamp = date('Y-m-d H:i:s');
    $date_picked = date('Y-m-d');

    // Check if parcel exists and not picked up
    $get_stmt = $conn->prepare("SELECT * FROM parcels_received WHERE id = ?");
    $get_stmt->bind_param("i", $parcel_id);
    $get_stmt->execute();
    $check = $get_stmt->get_result();
    if ($check->num_rows > 0) {
        $get_stmt2 = $conn->prepare("SELECT * FROM parcels_pickup WHERE parcel_id = ?");
        $get_stmt2->bind_param("i", $parcel_id);
        $get_stmt2->execute();
        $check_pickup = $get_stmt2->get_result();
        if ($check_pickup->num_rows == 0) {
            if ($parcels_pickup_has_timestamp) {
                $stmt = $conn->prepare("INSERT INTO parcels_pickup (parcel_id, picked_by, phone_number, designation, date_picked, picked_at) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isssss", $parcel_id, $picked_by, $phone_number, $designation, $date_picked, $picked_timestamp);
            } else {
                $stmt = $conn->prepare("INSERT INTO parcels_pickup (parcel_id, picked_by, phone_number, designation, date_picked) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("issss", $parcel_id, $picked_by, $phone_number, $designation, $date_picked);
            }

            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => "Parcel picked up successfully!"]);
                exit;
            } else {
                echo json_encode(['success' => false, 'message' => "Error: " . $conn->error]);
                exit;
            }
        } else {
            echo json_encode(['success' => false, 'message' => "This parcel has already been picked up!"]);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'message' => "Parcel not found!"]);
        exit;
    }
}

// Get statistics
$stats = [];

// Total parcels
$result = $conn->query("SELECT COUNT(*) as total FROM parcels_received");
$stats['parcels_received'] = $result->fetch_assoc()['total'];

// Today's parcels
$today = date('Y-m-d');
$result = $conn->query("SELECT COUNT(*) as total FROM parcels_received WHERE DATE(date_received) = '$today'");
$stats['today_parcels'] = $result->fetch_assoc()['total'];

// This week's parcels
$week_start = date('Y-m-d', strtotime('monday this week'));
$result = $conn->query("SELECT COUNT(*) as total FROM parcels_received WHERE date_received >= '$week_start'");
$stats['week_parcels'] = $result->fetch_assoc()['total'];

// Pending parcels
$result = $conn->query("
    SELECT COUNT(*) as total 
    FROM parcels_received pr 
    LEFT JOIN parcels_pickup pp ON pr.id = pp.parcel_id 
    WHERE pp.id IS NULL
");
$stats['pending_parcels'] = $result->fetch_assoc()['total'];

// Pagination settings
$records_per_page = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Get total records for pagination
$total_records = $conn->query("SELECT COUNT(*) as total FROM parcels_received")->fetch_assoc()['total'];
$total_pages = ceil($total_records / $records_per_page);

// Get all parcels with pickup status and details with pagination
$parcels = $conn->query("
    SELECT pr.*, 
           pp.id as pickup_id, 
           pp.picked_by, 
           pp.phone_number as picker_phone,
           pp.designation as picker_designation,
           pp.date_picked,
           $received_timestamp_select,
           $picked_timestamp_select,
           CASE WHEN pp.id IS NULL THEN 'Pending' ELSE 'Picked Up' END as status
    FROM parcels_received pr
    LEFT JOIN parcels_pickup pp ON pr.id = pp.parcel_id
    ORDER BY pr.date_received DESC
    LIMIT $offset, $records_per_page
");

// Get recent parcels for receive tab with pagination
$recent_page = isset($_GET['recent_page']) ? (int)$_GET['recent_page'] : 1;
$recent_offset = ($recent_page - 1) * $records_per_page;

$recent_parcels = $conn->query("
    SELECT pr.*, 
           $received_timestamp_select,
           CASE WHEN pp.id IS NULL THEN 'Pending' ELSE 'Picked Up' END as status
    FROM parcels_received pr
    LEFT JOIN parcels_pickup pp ON pr.id = pp.parcel_id
    ORDER BY pr.date_received DESC 
    LIMIT $recent_offset, $records_per_page
");
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parcels - Mailroom Ops</title>
    <link rel="icon" type="image/png" href="./images/logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/app.css">
</head>

<body>
    <div class="flex">
        <?php include './sidebar.php'; ?>

        <main class="main-content">
            <!-- Header -->
            <div class="page-header flex items-center justify-between gap-4 flex-wrap">
                <div>
                    <div class="breadcrumb">
                        <a href="index.php">Mail Operations</a>
                        <span class="sep">/</span>
                        <span>Parcels</span>
                    </div>
                    <h1 class="page-header-title">Parcels</h1>
                    <p class="page-header-subtitle">Receive and track parcels with pickup information.</p>
                </div>
                <div class="header-actions flex items-center gap-2 print-hide">
                    <div class="dropdown">
                        <button class="btn btn-soft" onclick="toggleDropdown(this)">
                            <i class="fa-solid fa-arrow-up-right-from-square"></i>
                            <span class="hidden sm:inline">Export</span>
                        </button>
                        <div class="dropdown-menu">
                            <a href="#" class="dropdown-item" onclick="exportReceiveCSV(); return false;"><i class="fa-regular fa-file-excel"></i> Export CSV</a>
                            <a href="#" class="dropdown-item" onclick="printRecords(); return false;"><i class="fa-solid fa-print"></i> Print</a>
                        </div>
                    </div>
                    <button onclick="openReceiveModal()" class="btn btn-primary">
                        <i class="fa-solid fa-plus"></i>
                        <span class="hidden sm:inline">Receive Parcel</span>
                    </button>
                </div>
            </div>

            <div class="page-body">
                <!-- Tabs -->
                <div class="tabs">
                    <button class="tab-button active" data-tab="receive" onclick="switchTab('receive')">
                        <i class="fa-regular fa-circle-down"></i> Receive Parcel
                    </button>
                    <button class="tab-button" data-tab="pickup" onclick="switchTab('pickup')">
                        <i class="fa-regular fa-circle-up"></i> Pickup Parcel
                    </button>
                    <button class="tab-button" data-tab="records" onclick="switchTab('records')">
                        <i class="fa-regular fa-rectangle-list"></i> All Records
                    </button>
                </div>

                <!-- Receive Parcel Tab -->
                <div id="receiveTab" class="tab-content">
                    <!-- Quick Actions + Search -->
                    <div class="card mb-6 print-hide">
                        <div class="card-body" style="padding:14px 16px;">
                            <div class="filter-bar" style="margin-bottom:0;">
                                <button onclick="openReceiveModal()" class="btn btn-primary">
                                    <i class="fa-solid fa-plus"></i> New Parcel
                                </button>
                                <button onclick="exportReceiveCSV()" class="btn btn-soft">
                                    <i class="fa-regular fa-file-excel"></i> Export
                                </button>
                                <button onclick="printReceiveRecords()" class="btn btn-soft">
                                    <i class="fa-solid fa-print"></i> Print
                                </button>
                                <button onclick="refreshReceiveTab()" class="btn btn-soft">
                                    <i class="fa-solid fa-rotate-right"></i> Refresh
                                </button>
                                <div style="margin-left:auto;"></div>
                                <div class="search-wrap">
                                    <i class="fa-solid fa-magnifying-glass icon"></i>
                                    <input type="text" id="receiveSearch" placeholder="Search parcels..."
                                        class="input" autocomplete="off">
                                </div>
                                <select id="receiveFilter" class="select">
                                    <option value="all">All Dates</option>
                                    <option value="today">Today</option>
                                    <option value="week">This Week</option>
                                    <option value="month">This Month</option>
                                </select>
                                <button onclick="filterReceiveTable(true)" class="btn btn-soft">
                                    <i class="fa-solid fa-filter"></i> Filter
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Stats -->
                    <div class="stat-grid mb-6">
                        <div class="stat-card">
                            <div class="stat-icon blue"><i class="fa-solid fa-box"></i></div>
                            <div class="stat-label">Total Parcels</div>
                            <div class="stat-value"><?php echo number_format($stats['parcels_received']); ?></div>
                            <div class="stat-hint">All time received</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon green"><i class="fa-regular fa-calendar"></i></div>
                            <div class="stat-label">Received Today</div>
                            <div class="stat-value"><?php echo number_format($stats['today_parcels']); ?></div>
                            <div class="stat-hint">Parcels received today</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon orange"><i class="fa-solid fa-calendar-week"></i></div>
                            <div class="stat-label">This Week</div>
                            <div class="stat-value"><?php echo number_format($stats['week_parcels']); ?></div>
                            <div class="stat-hint">Parcels this week</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon gray"><i class="fa-regular fa-clock"></i></div>
                            <div class="stat-label">Pending Pickup</div>
                            <div class="stat-value"><?php echo number_format($stats['pending_parcels']); ?></div>
                            <div class="stat-hint">Awaiting pickup</div>
                        </div>
                    </div>

                    <!-- Recent Parcels Table -->
                    <div class="card">
                        <div class="card-header" style="padding:14px 20px;">
                            <div>
                                <div class="card-title">Recent Parcels</div>
                                <div class="card-subtitle">Showing page <?php echo $recent_page; ?> of <?php echo max(1, ceil($total_records / $records_per_page)); ?></div>
                            </div>
                        </div>
                        <div class="table-wrap">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Tracking ID</th>
                                        <th class="hidden md:table-cell">Description</th>
                                        <th>Sender</th>
                                        <th>Recipient</th>
                                        <th>Received On</th>
                                        <th class="hidden md:table-cell">Received By</th>
                                        <th>Status</th>
                                        <th style="width:92px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="receiveTableBody">
                                    <?php if ($recent_parcels->num_rows > 0): ?>
                                        <?php while ($parcel = $recent_parcels->fetch_assoc()): ?>
                                            <tr class="receive-row"
                                                data-date="<?php echo date('Y-m-d', strtotime($parcel['received_timestamp'] ?? $parcel['date_received'])); ?>"
                                                data-search="<?php echo strtolower($parcel['tracking_id'] . ' ' . $parcel['sender'] . ' ' . $parcel['addressed_to']); ?>">
                                                <td>
                                                    <span class="table-cell-mono"><?php echo $parcel['tracking_id']; ?></span>
                                                </td>
                                                <td class="hidden md:table-cell">
                                                    <span class="text-xs text-[#4b5570] max-w-[200px] block truncate"><?php echo substr($parcel['description'], 0, 40); ?><?php echo strlen($parcel['description']) > 40 ? '...' : ''; ?></span>
                                                </td>
                                                <td><?php echo htmlspecialchars($parcel['sender']); ?></td>
                                                <td><?php echo htmlspecialchars($parcel['addressed_to']); ?></td>
                                                <td>
                                                    <span class="table-cell-subtitle" style="font-size:12px;"><?php echo formatTimestampDisplay($parcel['received_timestamp'] ?? $parcel['date_received']); ?></span>
                                                </td>
                                                <td class="hidden md:table-cell"><?php echo htmlspecialchars($parcel['received_by']); ?></td>
                                                <td>
                                                    <?php if ($parcel['status'] == 'Pending'): ?>
                                                        <span class="badge badge-orange">Pending</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-green">Picked Up</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="row-actions">
                                                        <button class="icon-btn" onclick="viewParcelDetails(<?php echo htmlspecialchars(json_encode($parcel)); ?>)" title="View Details">
                                                            <i class="fa-regular fa-eye"></i>
                                                        </button>
                                                        <?php if ($parcel['status'] == 'Pending'): ?>
                                                            <button class="icon-btn primary" onclick='openEditReceiveModal(<?php echo htmlspecialchars(json_encode([
                                                                        "id" => $parcel["id"],
                                                                        "tracking_id" => $parcel["tracking_id"],
                                                                        "description" => $parcel["description"],
                                                                        "sender" => $parcel["sender"],
                                                                        "addressed_to" => $parcel["addressed_to"],
                                                                        "received_by" => $parcel["received_by"],
                                                                        "received_timestamp" => $parcel["received_timestamp"] ?? $parcel["date_received"]
                                                                    ]), ENT_QUOTES, "UTF-8"); ?>)' title="Edit Parcel">
                                                                <i class="fa-regular fa-pen-to-square"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                        <button class="icon-btn danger" onclick="openDeleteReceiveModal(<?php echo $parcel['id']; ?>, '<?php echo htmlspecialchars($parcel['tracking_id'], ENT_QUOTES); ?>')" title="Delete Parcel">
                                                            <i class="fa-regular fa-trash-can"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8">
                                                <div class="empty-state">
                                                    <div class="empty-state-icon"><i class="fa-regular fa-box-open"></i></div>
                                                    <div class="empty-state-title">No parcels found</div>
                                                    <div class="empty-state-text">Start by receiving your first parcel.</div>
                                                    <button onclick="openReceiveModal()" class="btn btn-soft btn-sm" style="margin-top:16px;">Receive a parcel</button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination for Receive Tab -->
                        <?php if ($total_records > $records_per_page): ?>
                            <?php
                            $receiveTotalPages = ceil($total_records / $records_per_page);
                            $receiveStart = max(1, $recent_page - 2);
                            $receiveEnd = min($receiveTotalPages, $recent_page + 2);
                            $receiveFrom = (($recent_page - 1) * $records_per_page) + 1;
                            $receiveTo = min($recent_page * $records_per_page, $total_records);
                            ?>
                            <div class="pagination-shell">
                                <div class="pagination-meta">
                                    <div class="pagination-title">Showing parcels on this page</div>
                                    <span>Records <?php echo $receiveFrom; ?>-<?php echo $receiveTo; ?> of <?php echo $total_records; ?> total</span>
                                </div>
                                <div class="pagination-controls">
                                    <div class="pagination-page-indicator">Page <?php echo $recent_page; ?> of <?php echo $receiveTotalPages; ?></div>
                                    <div class="pagination">
                                        <a class="pagination-item compact <?php echo $recent_page <= 1 ? 'disabled' : ''; ?>" href="?recent_page=1&tab=receive"><i class="fa-regular fa-chevrons-left"></i></a>
                                        <a class="pagination-item compact <?php echo $recent_page <= 1 ? 'disabled' : ''; ?>" href="?recent_page=<?php echo max(1, $recent_page - 1); ?>&tab=receive"><i class="fa-regular fa-chevron-left"></i></a>
                                        <?php if ($receiveStart > 1): ?>
                                            <a class="pagination-item" href="?recent_page=1&tab=receive">1</a>
                                            <?php if ($receiveStart > 2): ?><span class="pagination-ellipsis">...</span><?php endif; ?>
                                        <?php endif; ?>
                                        <?php for ($i = $receiveStart; $i <= $receiveEnd; $i++): ?>
                                            <?php if ($i == $recent_page): ?>
                                                <span class="pagination-item active"><?php echo $i; ?></span>
                                            <?php else: ?>
                                                <a class="pagination-item" href="?recent_page=<?php echo $i; ?>&tab=receive"><?php echo $i; ?></a>
                                            <?php endif; ?>
                                        <?php endfor; ?>
                                        <?php if ($receiveEnd < $receiveTotalPages): ?>
                                            <?php if ($receiveEnd < $receiveTotalPages - 1): ?><span class="pagination-ellipsis">...</span><?php endif; ?>
                                            <a class="pagination-item" href="?recent_page=<?php echo $receiveTotalPages; ?>&tab=receive"><?php echo $receiveTotalPages; ?></a>
                                        <?php endif; ?>
                                        <a class="pagination-item compact <?php echo $recent_page >= $receiveTotalPages ? 'disabled' : ''; ?>" href="?recent_page=<?php echo min($receiveTotalPages, $recent_page + 1); ?>&tab=receive"><i class="fa-regular fa-chevron-right"></i></a>
                                        <a class="pagination-item compact <?php echo $recent_page >= $receiveTotalPages ? 'disabled' : ''; ?>" href="?recent_page=<?php echo $receiveTotalPages; ?>&tab=receive"><i class="fa-regular fa-chevrons-right"></i></a>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Pickup Parcel Tab -->
                <div id="pickupTab" class="tab-content hidden">
                    <!-- Search and filter -->
                    <div class="card mb-6 print-hide">
                        <div class="card-body" style="padding:14px 16px;">
                            <div class="filter-bar" style="margin-bottom:0;">
                                <div class="search-wrap" style="flex:1;min-width:220px;">
                                    <i class="fa-solid fa-magnifying-glass icon"></i>
                                    <input type="text" id="searchPickup" placeholder="Search by tracking ID, sender, or recipient..."
                                        class="input" autocomplete="off">
                                </div>
                                <select id="statusFilterPickup" class="select">
                                    <option value="all">All Status</option>
                                    <option value="pending">Pending</option>
                                    <option value="picked-up">Picked Up</option>
                                </select>
                                <button onclick="filterPickupTable(true)" class="btn btn-primary">
                                    <i class="fa-solid fa-filter"></i> Filter
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Parcels table -->
                    <div class="card">
                        <div class="card-header" style="padding:14px 20px;">
                            <div>
                                <div class="card-title">Parcels for Pickup</div>
                                <div class="card-subtitle">Page <?php echo $page; ?> of <?php echo max(1, $total_pages); ?></div>
                            </div>
                        </div>
                        <div class="table-wrap">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Tracking ID</th>
                                        <th class="hidden md:table-cell">Description</th>
                                        <th>Sender</th>
                                        <th>Recipient</th>
                                        <th>Received On</th>
                                        <th class="hidden md:table-cell">Picked Up On</th>
                                        <th>Status</th>
                                        <th style="width:110px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="pickupTableBody">
                                    <?php if ($parcels->num_rows > 0): ?>
                                        <?php
                                        $parcels->data_seek(0);
                                        while ($parcel = $parcels->fetch_assoc()):
                                        ?>
                                            <tr class="pickup-row"
                                                data-status="<?php echo strtolower(str_replace(' ', '-', $parcel['status'])); ?>"
                                                data-search="<?php echo strtolower($parcel['tracking_id'] . ' ' . $parcel['sender'] . ' ' . $parcel['addressed_to']); ?>">
                                                <td>
                                                    <span class="table-cell-mono"><?php echo $parcel['tracking_id']; ?></span>
                                                </td>
                                                <td class="hidden md:table-cell">
                                                    <span class="text-xs text-[#4b5570] max-w-[200px] block truncate"><?php echo substr($parcel['description'], 0, 40); ?><?php echo strlen($parcel['description']) > 40 ? '...' : ''; ?></span>
                                                </td>
                                                <td><?php echo htmlspecialchars($parcel['sender']); ?></td>
                                                <td><?php echo htmlspecialchars($parcel['addressed_to']); ?></td>
                                                <td>
                                                    <span class="table-cell-subtitle" style="font-size:12px;"><?php echo formatTimestampDisplay($parcel['received_timestamp'] ?? $parcel['date_received']); ?></span>
                                                </td>
                                                <td class="hidden md:table-cell">
                                                    <span class="table-cell-subtitle" style="font-size:12px;"><?php echo formatTimestampDisplay($parcel['picked_timestamp'] ?? $parcel['date_picked']); ?></span>
                                                </td>
                                                <td>
                                                    <?php if ($parcel['status'] == 'Pending'): ?>
                                                        <span class="badge badge-orange">Pending</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-green">Picked Up</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($parcel['status'] == 'Pending'): ?>
                                                        <button onclick="openPickupModal(<?php echo $parcel['id']; ?>, '<?php echo $parcel['tracking_id']; ?>')"
                                                            class="btn btn-primary btn-sm">
                                                            <i class="fa-solid fa-truck"></i> Process
                                                        </button>
                                                    <?php else: ?>
                                                        <div class="row-actions">
                                                            <button onclick="viewPickupDetails(<?php echo $parcel['id']; ?>, '<?php echo htmlspecialchars($parcel['tracking_id'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($parcel['picked_by'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($parcel['picker_phone'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($parcel['picker_designation'] ?? '', ENT_QUOTES); ?>', '<?php echo $parcel['picked_timestamp'] ?? $parcel['date_picked']; ?>')"
                                                                class="icon-btn" title="View Pickup Details">
                                                                <i class="fa-solid fa-circle-info"></i>
                                                            </button>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8">
                                                <div class="empty-state">
                                                    <div class="empty-state-icon"><i class="fa-regular fa-box-open"></i></div>
                                                    <div class="empty-state-title">No parcels found</div>
                                                    <div class="empty-state-text">Adjust your search or filters.</div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination for Pickup Tab -->
                        <?php if ($total_pages > 1): ?>
                            <?php
                            $pickupStart = max(1, $page - 2);
                            $pickupEnd = min($total_pages, $page + 2);
                            $pickupFrom = (($page - 1) * $records_per_page) + 1;
                            $pickupTo = min($page * $records_per_page, $total_records);
                            ?>
                            <div class="pagination-shell">
                                <div class="pagination-meta">
                                    <div class="pagination-title">Showing pickups on this page</div>
                                    <span>Records <?php echo $pickupFrom; ?>-<?php echo $pickupTo; ?> of <?php echo $total_records; ?> total</span>
                                </div>
                                <div class="pagination-controls">
                                    <div class="pagination-page-indicator">Page <?php echo $page; ?> of <?php echo $total_pages; ?></div>
                                    <div class="pagination">
                                        <a class="pagination-item compact <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="?page=1&tab=pickup"><i class="fa-regular fa-chevrons-left"></i></a>
                                        <a class="pagination-item compact <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="?page=<?php echo max(1, $page - 1); ?>&tab=pickup"><i class="fa-regular fa-chevron-left"></i></a>
                                        <?php if ($pickupStart > 1): ?>
                                            <a class="pagination-item" href="?page=1&tab=pickup">1</a>
                                            <?php if ($pickupStart > 2): ?><span class="pagination-ellipsis">...</span><?php endif; ?>
                                        <?php endif; ?>
                                        <?php for ($i = $pickupStart; $i <= $pickupEnd; $i++): ?>
                                            <?php if ($i == $page): ?>
                                                <span class="pagination-item active"><?php echo $i; ?></span>
                                            <?php else: ?>
                                                <a class="pagination-item" href="?page=<?php echo $i; ?>&tab=pickup"><?php echo $i; ?></a>
                                            <?php endif; ?>
                                        <?php endfor; ?>
                                        <?php if ($pickupEnd < $total_pages): ?>
                                            <?php if ($pickupEnd < $total_pages - 1): ?><span class="pagination-ellipsis">...</span><?php endif; ?>
                                            <a class="pagination-item" href="?page=<?php echo $total_pages; ?>&tab=pickup"><?php echo $total_pages; ?></a>
                                        <?php endif; ?>
                                        <a class="pagination-item compact <?php echo $page >= $total_pages ? 'disabled' : ''; ?>" href="?page=<?php echo min($total_pages, $page + 1); ?>&tab=pickup"><i class="fa-regular fa-chevron-right"></i></a>
                                        <a class="pagination-item compact <?php echo $page >= $total_pages ? 'disabled' : ''; ?>" href="?page=<?php echo $total_pages; ?>&tab=pickup"><i class="fa-regular fa-chevrons-right"></i></a>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- All Records Tab -->
                <div id="recordsTab" class="tab-content hidden">
                    <!-- Quick Actions Bar -->
                    <div class="card mb-6 print-hide">
                        <div class="card-body" style="padding:14px 16px;">
                            <div class="filter-bar" style="margin-bottom:0;justify-content:space-between;">
                                <div class="flex items-center gap-2">
                                    <button onclick="exportToCSV()" class="btn btn-soft">
                                        <i class="fa-regular fa-file-excel"></i> Export CSV
                                    </button>
                                    <button onclick="printRecords()" class="btn btn-soft">
                                        <i class="fa-solid fa-print"></i> Print
                                    </button>
                                </div>
                                <div class="text-xs text-[#7d8398]">
                                    Total Records: <span id="totalRecords" class="font-medium text-[#1b2a4a]"><?php echo number_format($total_records); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filters -->
                    <div class="card mb-6 print-hide">
                        <div class="card-body" style="padding:14px 16px;">
                            <div class="filter-bar" style="margin-bottom:0;">
                                <div class="search-wrap" style="flex:1;min-width:220px;">
                                    <i class="fa-solid fa-magnifying-glass icon"></i>
                                    <input type="text" id="quickSearch" placeholder="Search by tracking ID, sender, recipient, or picker..."
                                        class="input" autocomplete="off">
                                </div>
                                <select id="filterStatus" class="select">
                                    <option value="all">All Status</option>
                                    <option value="pending">Pending</option>
                                    <option value="picked-up">Picked Up</option>
                                </select>
                                <input type="date" id="dateFrom" class="input" autocomplete="off">
                                <input type="date" id="dateTo" class="input" autocomplete="off">
                                <button onclick="applyQuickSearch()" class="btn btn-primary">
                                    <i class="fa-solid fa-filter"></i> Search
                                </button>
                                <button onclick="clearSearch()" class="btn btn-soft">
                                    <i class="fa-solid fa-rotate-left"></i> Reset
                                </button>
                                <span class="text-xs text-[#7d8398] whitespace-nowrap" id="activeFiltersCount">No active filters</span>
                            </div>
                        </div>
                    </div>

                    <!-- Records Table -->
                    <div class="card">
                        <div class="card-header" style="padding:14px 20px;">
                            <div>
                                <div class="card-title">All Parcel Records</div>
                                <div class="card-subtitle">Page <?php echo $page; ?> of <?php echo max(1, $total_pages); ?></div>
                            </div>
                        </div>
                        <div class="table-wrap">
                            <table id="recordsTable" class="table">
                                <thead>
                                    <tr>
                                        <th>Tracking ID</th>
                                        <th class="hidden md:table-cell">Description</th>
                                        <th>Sender</th>
                                        <th>Recipient</th>
                                        <th>Received</th>
                                        <th>Status</th>
                                        <th class="hidden md:table-cell">Pickup Info</th>
                                        <th style="width:60px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="recordsTableBody">
                                    <?php if ($parcels->num_rows > 0): ?>
                                        <?php
                                        $parcels->data_seek(0);
                                        while ($parcel = $parcels->fetch_assoc()):
                                        ?>
                                            <tr class="record-row"
                                                data-status="<?php echo strtolower(str_replace(' ', '-', $parcel['status'])); ?>"
                                                data-search="<?php echo strtolower($parcel['tracking_id'] . ' ' . $parcel['sender'] . ' ' . $parcel['addressed_to'] . ' ' . ($parcel['picked_by'] ?? '')); ?>"
                                                data-tracking="<?php echo strtolower($parcel['tracking_id']); ?>"
                                                data-sender="<?php echo strtolower($parcel['sender']); ?>"
                                                data-recipient="<?php echo strtolower($parcel['addressed_to']); ?>"
                                                data-picker="<?php echo strtolower($parcel['picked_by'] ?? ''); ?>"
                                                data-date="<?php echo date('Y-m-d', strtotime($parcel['received_timestamp'] ?? $parcel['date_received'])); ?>">
                                                <td>
                                                    <span class="table-cell-mono"><?php echo $parcel['tracking_id']; ?></span>
                                                </td>
                                                <td class="hidden md:table-cell">
                                                    <span class="text-xs text-[#4b5570] max-w-[200px] block truncate"><?php echo substr($parcel['description'], 0, 40); ?><?php echo strlen($parcel['description']) > 40 ? '...' : ''; ?></span>
                                                </td>
                                                <td><?php echo htmlspecialchars($parcel['sender']); ?></td>
                                                <td><?php echo htmlspecialchars($parcel['addressed_to']); ?></td>
                                                <td>
                                                    <span class="table-cell-subtitle" style="font-size:12px;"><?php echo formatTimestampDisplay($parcel['received_timestamp'] ?? $parcel['date_received']); ?></span>
                                                </td>
                                                <td>
                                                    <?php if ($parcel['status'] == 'Pending'): ?>
                                                        <span class="badge badge-orange">Pending</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-green">Picked Up</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="hidden md:table-cell">
                                                    <?php if ($parcel['picked_by']): ?>
                                                        <div class="table-cell-title" style="font-size:12px;"><?php echo htmlspecialchars($parcel['picked_by']); ?></div>
                                                        <?php if ($parcel['date_picked']): ?>
                                                            <div class="table-cell-subtitle"><?php echo formatTimestampDisplay($parcel['picked_timestamp'] ?? $parcel['date_picked']); ?></div>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-xs text-[#9aa0b5]">Not picked up</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="row-actions">
                                                        <button class="icon-btn" onclick="viewParcelDetails(<?php echo htmlspecialchars(json_encode($parcel)); ?>)" title="View Details">
                                                            <i class="fa-regular fa-eye"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8">
                                                <div class="empty-state">
                                                    <div class="empty-state-icon"><i class="fa-regular fa-box-open"></i></div>
                                                    <div class="empty-state-title">No parcel records found</div>
                                                    <div class="empty-state-text">Adjust your search or filters.</div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination for Records Tab -->
                        <?php if ($total_pages > 1): ?>
                            <?php
                            $recordStart = max(1, $page - 2);
                            $recordEnd = min($total_pages, $page + 2);
                            $recordFrom = (($page - 1) * $records_per_page) + 1;
                            $recordTo = min($page * $records_per_page, $total_records);
                            ?>
                            <div class="pagination-shell">
                                <div class="pagination-meta">
                                    <div class="pagination-title">Showing parcel records on this page</div>
                                    <span>Records <?php echo $recordFrom; ?>-<?php echo $recordTo; ?> of <?php echo $total_records; ?> total</span>
                                </div>
                                <div class="pagination-controls">
                                    <div class="pagination-page-indicator">Page <?php echo $page; ?> of <?php echo $total_pages; ?></div>
                                    <div class="pagination">
                                        <a class="pagination-item compact <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="?page=1&tab=records"><i class="fa-regular fa-chevrons-left"></i></a>
                                        <a class="pagination-item compact <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="?page=<?php echo max(1, $page - 1); ?>&tab=records"><i class="fa-regular fa-chevron-left"></i></a>
                                        <?php if ($recordStart > 1): ?>
                                            <a class="pagination-item" href="?page=1&tab=records">1</a>
                                            <?php if ($recordStart > 2): ?><span class="pagination-ellipsis">...</span><?php endif; ?>
                                        <?php endif; ?>
                                        <?php for ($i = $recordStart; $i <= $recordEnd; $i++): ?>
                                            <?php if ($i == $page): ?>
                                                <span class="pagination-item active"><?php echo $i; ?></span>
                                            <?php else: ?>
                                                <a class="pagination-item" href="?page=<?php echo $i; ?>&tab=records"><?php echo $i; ?></a>
                                            <?php endif; ?>
                                        <?php endfor; ?>
                                        <?php if ($recordEnd < $total_pages): ?>
                                            <?php if ($recordEnd < $total_pages - 1): ?><span class="pagination-ellipsis">...</span><?php endif; ?>
                                            <a class="pagination-item" href="?page=<?php echo $total_pages; ?>&tab=records"><?php echo $total_pages; ?></a>
                                        <?php endif; ?>
                                        <a class="pagination-item compact <?php echo $page >= $total_pages ? 'disabled' : ''; ?>" href="?page=<?php echo min($total_pages, $page + 1); ?>&tab=records"><i class="fa-regular fa-chevron-right"></i></a>
                                        <a class="pagination-item compact <?php echo $page >= $total_pages ? 'disabled' : ''; ?>" href="?page=<?php echo $total_pages; ?>&tab=records"><i class="fa-regular fa-chevrons-right"></i></a>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Toast Notification Container -->
    <div id="toastContainer" class="toast-container"></div>

    <!-- Receive Parcel Modal -->
    <div id="receiveModal" class="modal-backdrop" style="display:none;">
        <div class="modal-dialog lg">
            <div class="modal-header">
                <h3 class="modal-title">Receive New Parcel</h3>
                <button type="button" class="modal-close" onclick="closeReceiveModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form id="receiveForm" onsubmit="submitReceiveForm(event)">
                <input type="hidden" name="action" value="receive">
                <?php csrf_field(); ?>
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-field span-2">
                            <label class="label">Description <span class="req">*</span></label>
                            <textarea name="description" rows="3" required class="input" autocomplete="off" placeholder="Enter parcel description"></textarea>
                        </div>
                        <div class="form-field">
                            <label class="label">Sender <span class="req">*</span></label>
                            <input type="text" name="sender" required class="input" autocomplete="off" placeholder="Sender name">
                        </div>
                        <div class="form-field">
                            <label class="label">Addressed To <span class="req">*</span></label>
                            <input type="text" name="addressed_to" required class="input" autocomplete="off" placeholder="Recipient name">
                        </div>
                        <div class="form-field">
                            <label class="label">Received Timestamp <span class="req">*</span></label>
                            <input type="datetime-local" name="date_received" required value="<?php echo date('Y-m-d\TH:i'); ?>" class="input" autocomplete="off">
                        </div>
                        <div class="form-field">
                            <label class="label">Received By <span class="req">*</span></label>
                            <input type="text" name="received_by" required class="input" autocomplete="off" placeholder="Staff name">
                        </div>
                        <div class="form-field span-2">
                            <div class="notice-bar">
                                <i class="fa-solid fa-circle-info"></i>
                                <span>Tracking ID will be auto-generated as <span class="font-mono">PRCL-<?php echo date('Ymd'); ?>-XXXXXX</span></span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeReceiveModal()" class="btn btn-soft">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-regular fa-floppy-disk"></i> Receive Parcel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Pending Parcel Modal -->
    <div id="editReceiveModal" class="modal-backdrop" style="display:none;">
        <div class="modal-dialog lg">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title">Edit Pending Parcel</h3>
                    <p class="text-xs text-[#7d8398] mt-1">Tracking ID: <span id="editTrackingId" class="font-mono"></span></p>
                </div>
                <button type="button" class="modal-close" onclick="closeEditReceiveModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form id="editReceiveForm" onsubmit="submitEditReceiveForm(event)">
                <input type="hidden" name="action" value="edit_received">
                <input type="hidden" name="parcel_id" id="editParcelId">
                <?php csrf_field(); ?>
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-field span-2">
                            <label class="label">Description <span class="req">*</span></label>
                            <textarea name="description" id="editDescription" rows="3" required class="input" autocomplete="off"></textarea>
                        </div>
                        <div class="form-field">
                            <label class="label">Sender <span class="req">*</span></label>
                            <input type="text" name="sender" id="editSender" required class="input" autocomplete="off">
                        </div>
                        <div class="form-field">
                            <label class="label">Addressed To <span class="req">*</span></label>
                            <input type="text" name="addressed_to" id="editAddressedTo" required class="input" autocomplete="off">
                        </div>
                        <div class="form-field span-2">
                            <label class="label">Received By <span class="req">*</span></label>
                            <input type="text" name="received_by" id="editReceivedBy" required class="input" autocomplete="off">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeEditReceiveModal()" class="btn btn-soft">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-regular fa-floppy-disk"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Pickup Modal -->
    <div id="pickupModal" class="modal-backdrop" style="display:none;">
        <div class="modal-dialog">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title">Process Pickup</h3>
                    <p class="text-xs text-[#7d8398] mt-1">Tracking ID: <span id="modalTrackingId" class="font-mono"></span></p>
                </div>
                <button type="button" class="modal-close" onclick="closePickupModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form id="pickupForm" onsubmit="submitPickupForm(event)">
                <input type="hidden" name="parcel_id" id="modalParcelId">
                <?php csrf_field(); ?>
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-field">
                            <label class="label">Picked By <span class="req">*</span></label>
                            <input type="text" name="picked_by" required class="input" autocomplete="off" placeholder="Name of person picking up">
                        </div>
                        <div class="form-field">
                            <label class="label">Phone Number <span class="req">*</span></label>
                            <input type="text" name="phone_number" required class="input" autocomplete="off" placeholder="Contact number">
                        </div>
                        <div class="form-field span-2">
                            <label class="label">Designation <span class="req">*</span></label>
                            <input type="text" name="designation" required class="input" autocomplete="off" placeholder="e.g., Chamber, HR, etc.">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closePickupModal()" class="btn btn-soft">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-check"></i> Confirm Pickup
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Parcel Modal -->
    <div id="deleteReceiveModal" class="modal-backdrop" style="display:none;">
        <div class="modal-dialog sm">
            <div class="modal-header">
                <h3 class="modal-title">Delete Parcel</h3>
                <button type="button" class="modal-close" onclick="closeDeleteReceiveModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form id="deleteReceiveForm" onsubmit="submitDeleteReceiveForm(event)">
                <input type="hidden" name="action" value="delete_received">
                <input type="hidden" name="parcel_id" id="deleteParcelId">
                <?php csrf_field(); ?>
                <div class="modal-body">
                    <div class="notice-bar" style="background:var(--red-soft);border-color:var(--red-border);color:var(--red);">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <span>Are you sure you want to delete parcel <span id="deleteTrackingId" class="font-mono font-medium"></span>? This also removes any pickup record linked to it.</span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeDeleteReceiveModal()" class="btn btn-soft">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fa-regular fa-trash-can"></i> Delete Parcel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Pickup Details Modal -->
    <div id="detailsModal" class="modal-backdrop" style="display:none;">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title">Pickup Details</h3>
                <button type="button" class="modal-close" onclick="closeDetailsModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <div class="space-y-4">
                    <div>
                        <div class="label">Tracking ID</div>
                        <p id="detailTrackingId" class="text-sm font-mono text-[#1b2a4a]"></p>
                    </div>
                    <div>
                        <div class="label">Picked By</div>
                        <p id="detailPickedBy" class="text-sm text-[#1b2a4a]"></p>
                    </div>
                    <div>
                        <div class="label">Phone Number</div>
                        <p id="detailPhone" class="text-sm text-[#1b2a4a]"></p>
                    </div>
                    <div>
                        <div class="label">Designation</div>
                        <p id="detailDesignation" class="text-sm text-[#1b2a4a]"></p>
                    </div>
                    <div>
                        <div class="label">Date Picked</div>
                        <p id="detailDate" class="text-sm text-[#1b2a4a]"></p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button onclick="closeDetailsModal()" class="btn btn-soft">Close</button>
            </div>
        </div>
    </div>

    <!-- Parcel Details Modal -->
    <div id="parcelDetailsModal" class="modal-backdrop" style="display:none;">
        <div class="modal-dialog lg">
            <div class="modal-header">
                <h3 class="modal-title">Parcel Details</h3>
                <button type="button" class="modal-close" onclick="closeParcelDetailsModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <div class="grid grid-cols-2 gap-5" id="parcelDetailContent">
                    <!-- Filled by JavaScript -->
                </div>
            </div>
            <div class="modal-footer">
                <button onclick="closeParcelDetailsModal()" class="btn btn-soft">Close</button>
            </div>
        </div>
    </div>

    <script>
        // Toast notification functions - delegates to shared MailroomToast
        function showToast(message, type = 'success') {
            MailroomToast.show(message, type);
        }

        // Tab switching with URL parameter
        function switchTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.add('hidden');
            });
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('active');
            });
            document.getElementById(tabName + 'Tab').classList.remove('hidden');
            const btn = document.querySelector('.tab-button[data-tab="' + tabName + '"]');
            if (btn) btn.classList.add('active');

            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);
        }

        // Check URL parameter on load
        window.addEventListener('load', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const tab = urlParams.get('tab');
            if (tab) {
                const tabButton = document.querySelector('.tab-button[data-tab="' + tab + '"]');
                if (tabButton) {
                    tabButton.click();
                }
            }
        });

        // Receive Modal functions
        function openReceiveModal() {
            MailroomModal.open('receiveModal');
        }

        function closeReceiveModal() {
            MailroomModal.close('receiveModal');
            document.getElementById('receiveForm').reset();
        }

        function openEditReceiveModal(parcel) {
            document.getElementById('editParcelId').value = parcel.id || '';
            document.getElementById('editTrackingId').textContent = parcel.tracking_id || '';
            document.getElementById('editDescription').value = parcel.description || '';
            document.getElementById('editSender').value = parcel.sender || '';
            document.getElementById('editAddressedTo').value = parcel.addressed_to || '';
            document.getElementById('editReceivedBy').value = parcel.received_by || '';
            MailroomModal.open('editReceiveModal');
        }

        function closeEditReceiveModal() {
            MailroomModal.close('editReceiveModal');
            document.getElementById('editReceiveForm').reset();
            document.getElementById('editTrackingId').textContent = '';
        }

        // Submit receive form via AJAX
        function submitReceiveForm(event) {
            event.preventDefault();

            const formData = new FormData(event.target);

            fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message, 'success');
                        closeReceiveModal();
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showToast(data.message, 'error');
                    }
                })
                .catch(error => {
                    showToast('An error occurred', 'error');
                });
        }

        function submitEditReceiveForm(event) {
            event.preventDefault();

            const formData = new FormData(event.target);

            fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message, 'success');
                        closeEditReceiveModal();
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showToast(data.message, 'error');
                    }
                })
                .catch(error => {
                    showToast('An error occurred', 'error');
                });
        }

        // Pickup Modal functions
        function openPickupModal(id, trackingId) {
            document.getElementById('modalParcelId').value = id;
            document.getElementById('modalTrackingId').textContent = trackingId;
            MailroomModal.open('pickupModal');
        }

        function closePickupModal() {
            MailroomModal.close('pickupModal');
            document.getElementById('pickupForm').reset();
        }

        // Submit pickup form via AJAX
        function submitPickupForm(event) {
            event.preventDefault();

            const formData = new FormData(event.target);

            fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message, 'success');
                        closePickupModal();
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showToast(data.message, 'error');
                    }
                })
                .catch(error => {
                    showToast('An error occurred', 'error');
                });
        }

        function openDeleteReceiveModal(id, trackingId) {
            document.getElementById('deleteParcelId').value = id;
            document.getElementById('deleteTrackingId').textContent = trackingId;
            MailroomModal.open('deleteReceiveModal');
        }

        function closeDeleteReceiveModal() {
            MailroomModal.close('deleteReceiveModal');
            document.getElementById('deleteReceiveForm').reset();
            document.getElementById('deleteTrackingId').textContent = '';
        }

        function submitDeleteReceiveForm(event) {
            event.preventDefault();

            const formData = new FormData(event.target);

            fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message, 'success');
                        closeDeleteReceiveModal();
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showToast(data.message, 'error');
                    }
                })
                .catch(error => {
                    showToast('An error occurred', 'error');
                });
        }

        // Details Modal functions
        function viewPickupDetails(id, trackingId, pickedBy, phone, designation, date) {
            document.getElementById('detailTrackingId').textContent = trackingId;
            document.getElementById('detailPickedBy').textContent = pickedBy || 'N/A';
            document.getElementById('detailPhone').textContent = phone || 'N/A';
            document.getElementById('detailDesignation').textContent = designation || 'N/A';
            document.getElementById('detailDate').textContent = date ? new Date(date.replace(' ', 'T')).toLocaleString() : 'N/A';
            MailroomModal.open('detailsModal');
        }

        function closeDetailsModal() {
            MailroomModal.close('detailsModal');
        }

        // Parcel Details Modal
        function viewParcelDetails(parcel) {
            const content = document.getElementById('parcelDetailContent');
            content.innerHTML = `
                <div class="col-span-2">
                    <div class="label">Description</div>
                    <p class="text-sm text-[#1b2a4a]">${parcel.description || 'N/A'}</p>
                </div>
                <div>
                    <div class="label">Tracking ID</div>
                    <p class="text-sm font-mono text-[#1b2a4a]">${parcel.tracking_id || 'N/A'}</p>
                </div>
                <div>
                    <div class="label">Status</div>
                    <p class="text-sm text-[#1b2a4a]">${parcel.status || 'N/A'}</p>
                </div>
                <div>
                    <div class="label">Sender</div>
                    <p class="text-sm text-[#1b2a4a]">${parcel.sender || 'N/A'}</p>
                </div>
                <div>
                    <div class="label">Recipient</div>
                    <p class="text-sm text-[#1b2a4a]">${parcel.addressed_to || 'N/A'}</p>
                </div>
                <div>
                    <div class="label">Received Timestamp</div>
                    <p class="text-sm text-[#1b2a4a]">${parcel.received_timestamp ? new Date(parcel.received_timestamp.replace(' ', 'T')).toLocaleString() : (parcel.date_received || 'N/A')}</p>
                </div>
                <div>
                    <div class="label">Received By</div>
                    <p class="text-sm text-[#1b2a4a]">${parcel.received_by || 'N/A'}</p>
                </div>
                ${parcel.status === 'Picked Up' ? `
                    <div>
                        <div class="label">Picked By</div>
                        <p class="text-sm text-[#1b2a4a]">${parcel.picked_by || 'N/A'}</p>
                    </div>
                    <div>
                        <div class="label">Picker Phone</div>
                        <p class="text-sm text-[#1b2a4a]">${parcel.picker_phone || 'N/A'}</p>
                    </div>
                    <div>
                        <div class="label">Designation</div>
                        <p class="text-sm text-[#1b2a4a]">${parcel.picker_designation || 'N/A'}</p>
                    </div>
                    <div>
                        <div class="label">Picked Up Timestamp</div>
                        <p class="text-sm text-[#1b2a4a]">${parcel.picked_timestamp ? new Date(parcel.picked_timestamp.replace(' ', 'T')).toLocaleString() : (parcel.date_picked || 'N/A')}</p>
                    </div>
                ` : ''}
            `;
            MailroomModal.open('parcelDetailsModal');
        }

        function closeParcelDetailsModal() {
            MailroomModal.close('parcelDetailsModal');
        }

        function getSearchTokens(value) {
            return value.toLowerCase().split(/\s+/).filter(Boolean);
        }

        // Search and filter for Receive tab
        document.getElementById('receiveSearch')?.addEventListener('input', filterReceiveTable);
        document.getElementById('receiveFilter')?.addEventListener('change', filterReceiveTable);

        function filterReceiveTable(showFeedback = false) {
            const searchTokens = getSearchTokens(document.getElementById('receiveSearch').value);
            const filterValue = document.getElementById('receiveFilter').value;
            const rows = document.getElementsByClassName('receive-row');
            const today = new Date().toISOString().split('T')[0];

            // Get week start and end
            const currentDate = new Date();
            const weekStart = new Date(currentDate.setDate(currentDate.getDate() - currentDate.getDay() + 1)).toISOString().split('T')[0];
            const monthStart = new Date(currentDate.getFullYear(), currentDate.getMonth(), 1).toISOString().split('T')[0];

            let visibleCount = 0;
            for (let row of rows) {
                const searchText = row.getAttribute('data-search');
                const rowDate = row.getAttribute('data-date');

                let showByDate = true;
                if (filterValue === 'today') {
                    showByDate = rowDate === today;
                } else if (filterValue === 'week') {
                    showByDate = rowDate >= weekStart;
                } else if (filterValue === 'month') {
                    showByDate = rowDate >= monthStart;
                }

                const matchesSearch = searchTokens.length === 0 || searchTokens.every(token => searchText.includes(token));

                const show = matchesSearch && showByDate;
                row.style.display = show ? '' : 'none';
                if (show) visibleCount++;
            }

            if (showFeedback && visibleCount === 0) {
                showToast('No matching records found', 'info');
            }
        }

        // Search and filter for Pickup tab
        document.getElementById('searchPickup')?.addEventListener('input', filterPickupTable);
        document.getElementById('statusFilterPickup')?.addEventListener('change', filterPickupTable);

        function filterPickupTable(showFeedback = false) {
            const searchTokens = getSearchTokens(document.getElementById('searchPickup').value);
            const statusFilter = document.getElementById('statusFilterPickup').value;
            const rows = document.getElementsByClassName('pickup-row');

            let visibleCount = 0;
            for (let row of rows) {
                const searchText = row.getAttribute('data-search');
                const status = row.getAttribute('data-status');

                const matchesSearch = searchTokens.length === 0 || searchTokens.every(token => searchText.includes(token));
                const matchesStatus = statusFilter === 'all' || status === statusFilter;

                const show = matchesSearch && matchesStatus;
                row.style.display = show ? '' : 'none';
                if (show) visibleCount++;
            }

            if (showFeedback && visibleCount === 0) {
                showToast('No matching records found', 'info');
            }
        }

        // Records tab search functions
        function applyQuickSearch() {
            applyAdvancedFilters(true);
        }

        // Advanced filters
        function applyAdvancedFilters(showFeedback = false) {
            const status = document.getElementById('filterStatus').value;
            const dateFrom = document.getElementById('dateFrom').value;
            const dateTo = document.getElementById('dateTo').value;
            const quickSearchTokens = getSearchTokens(document.getElementById('quickSearch').value);

            const rows = document.getElementsByClassName('record-row');
            let visibleCount = 0;

            for (let row of rows) {
                let show = true;

                // Apply quick search if present
                if (quickSearchTokens.length > 0) {
                    const searchText = row.getAttribute('data-search');
                    if (!quickSearchTokens.every(token => searchText.includes(token))) show = false;
                }

                // Apply status filter
                if (show && status !== 'all') {
                    const rowStatus = row.getAttribute('data-status');
                    if (rowStatus !== status) show = false;
                }

                // Apply date range
                if (show && dateFrom) {
                    const rowDate = row.getAttribute('data-date');
                    if (rowDate < dateFrom) show = false;
                }
                if (show && dateTo) {
                    const rowDate = row.getAttribute('data-date');
                    if (rowDate > dateTo) show = false;
                }

                row.style.display = show ? '' : 'none';
                if (show) visibleCount++;
            }

            const totalRecordsEl = document.getElementById('totalRecords');
            if (totalRecordsEl) totalRecordsEl.textContent = visibleCount;

            if (showFeedback && visibleCount === 0) {
                showToast('No matching records found', 'info');
            } else if (showFeedback) {
                showToast(`Found ${visibleCount} matching records`, 'success');
            }

            updateActiveFiltersCount();
        }

        function clearSearch() {
            document.getElementById('quickSearch').value = '';
            document.getElementById('filterStatus').value = 'all';
            document.getElementById('dateFrom').value = '';
            document.getElementById('dateTo').value = '';

            const rows = document.getElementsByClassName('record-row');
            for (let row of rows) {
                row.style.display = '';
            }

            const totalRecordsEl = document.getElementById('totalRecords');
            if (totalRecordsEl) totalRecordsEl.textContent = rows.length;
            showToast('Search cleared', 'info');
            updateActiveFiltersCount();
        }

        function resetFilters() {
            clearSearch();
        }

        function updateActiveFiltersCount() {
            const quickSearch = document.getElementById('quickSearch').value;
            const status = document.getElementById('filterStatus').value;
            const dateFrom = document.getElementById('dateFrom').value;
            const dateTo = document.getElementById('dateTo').value;

            let count = 0;
            if (quickSearch) count++;
            if (status !== 'all') count++;
            if (dateFrom) count++;
            if (dateTo) count++;

            const filterSpan = document.getElementById('activeFiltersCount');
            if (filterSpan) {
                if (count === 0) {
                    filterSpan.textContent = 'No active filters';
                } else {
                    filterSpan.textContent = `${count} active filter${count > 1 ? 's' : ''}`;
                }
            }
        }

        // Search on Enter key
        document.getElementById('quickSearch')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                applyQuickSearch();
            }
        });

        document.getElementById('quickSearch')?.addEventListener('input', function() {
            applyAdvancedFilters(false);
        });

        document.getElementById('receiveSearch')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                filterReceiveTable(true);
            }
        });

        document.getElementById('searchPickup')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                filterPickupTable(true);
            }
        });

        document.getElementById('filterStatus')?.addEventListener('change', function() {
            applyAdvancedFilters(false);
        });

        document.getElementById('dateFrom')?.addEventListener('change', function() {
            applyAdvancedFilters(false);
        });

        document.getElementById('dateTo')?.addEventListener('change', function() {
            applyAdvancedFilters(false);
        });

        // Refresh receive tab
        function refreshReceiveTab() {
            showToast('Refreshing data...', 'info');
            setTimeout(() => location.reload(), 400);
        }

        // Export functions for Receive tab
        function exportReceiveCSV() {
            const rows = [];
            const headers = ['Tracking ID', 'Description', 'Sender', 'Recipient', 'Date Received', 'Received By', 'Status'];
            rows.push(headers.join(','));

            document.querySelectorAll('.receive-row:not([style*="display: none"])').forEach(row => {
                const cells = row.querySelectorAll('td');
                const rowData = [
                    `"${cells[0].textContent}"`,
                    `"${cells[1].textContent}"`,
                    `"${cells[2].textContent}"`,
                    `"${cells[3].textContent}"`,
                    `"${cells[4].textContent}"`,
                    `"${cells[5].textContent}"`,
                    `"${cells[6].textContent.trim()}"`
                ];
                rows.push(rowData.join(','));
            });

            const csv = rows.join('\n');
            const blob = new Blob([csv], {
                type: 'text/csv'
            });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `parcels_received_${new Date().toISOString().split('T')[0]}.csv`;
            a.click();
            showToast('Export started', 'success');
        }

        function printReceiveRecords() {
            let rowsHtml = '';
            document.querySelectorAll('.receive-row:not([style*="display: none"])').forEach(row => {
                const cells = row.querySelectorAll('td');
                rowsHtml += `
                    <tr>
                        <td>${cells[0].textContent}</td>
                        <td>${cells[1].textContent}</td>
                        <td>${cells[2].textContent}</td>
                        <td>${cells[3].textContent}</td>
                        <td>${cells[4].textContent}</td>
                        <td>${cells[5].textContent}</td>
                        <td>${cells[6].textContent.trim()}</td>
                    </tr>
                `;
            });

            const printContent = `
                <html>
                <head>
                    <title>Received Parcels</title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 20px; }
                        h2 { color: #333; }
                        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
                        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                        th { background-color: #f2f2f2; }
                        .header { margin-bottom: 20px; }
                        .date { color: #666; font-size: 14px; }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h2>Received Parcels Report</h2>
                        <p class="date">Generated on: ${new Date().toLocaleString()}</p>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>Tracking ID</th>
                                <th>Description</th>
                                <th>Sender</th>
                                <th>Recipient</th>
                                <th>Date Received</th>
                                <th>Received By</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${rowsHtml}
                        </tbody>
                    </table>
                </body>
                </html>
            `;
            printHtmlOnPage(printContent);
            showToast('Print dialog opened', 'info');
        }

        // Export functions for Records tab
        function exportToCSV() {
            const rows = [];
            const headers = ['Tracking ID', 'Description', 'Sender', 'Recipient', 'Date Received', 'Status', 'Picked By', 'Date Picked'];
            rows.push(headers.join(','));

            document.querySelectorAll('.record-row:not([style*="display: none"])').forEach(row => {
                const cells = row.querySelectorAll('td');
                const status = cells[5].querySelector('.badge')?.textContent.trim() || cells[5].textContent.trim();
                const pickupInfo = cells[6].textContent.trim().replace(/\s+/g, ' ').replace(/\n/g, ' ');

                const rowData = [
                    `"${cells[0].textContent}"`,
                    `"${cells[1].textContent}"`,
                    `"${cells[2].textContent}"`,
                    `"${cells[3].textContent}"`,
                    `"${cells[4].textContent}"`,
                    `"${status}"`,
                    `"${pickupInfo.split(' ')[0]}"`,
                    `"${pickupInfo.includes('Not picked') ? '' : pickupInfo.split(' ').slice(1).join(' ')}"`
                ];
                rows.push(rowData.join(','));
            });

            const csv = rows.join('\n');
            const blob = new Blob([csv], {
                type: 'text/csv'
            });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `parcels_export_${new Date().toISOString().split('T')[0]}.csv`;
            a.click();
            showToast('Export started', 'success');
        }

        function exportToPDF() {
            printRecords();
        }

        function printRecords() {
            let rowsHtml = '';
            document.querySelectorAll('.record-row:not([style*="display: none"])').forEach(row => {
                const cells = row.querySelectorAll('td');
                rowsHtml += `
                    <tr>
                        <td>${cells[0].textContent}</td>
                        <td>${cells[1].textContent}</td>
                        <td>${cells[2].textContent}</td>
                        <td>${cells[3].textContent}</td>
                        <td>${cells[4].textContent}</td>
                        <td>${cells[5].innerHTML.replace(/<[^>]*>/g, '')}</td>
                        <td>${cells[6].innerHTML.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim()}</td>
                    </tr>
                `;
            });

            const printContent = `
                <html>
                <head>
                    <title>Parcel Records</title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 20px; }
                        h2 { color: #333; }
                        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
                        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                        th { background-color: #f2f2f2; }
                        .header { margin-bottom: 20px; }
                        .date { color: #666; font-size: 14px; }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h2>Parcel Records Report</h2>
                        <p class="date">Generated on: ${new Date().toLocaleString()}</p>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>Tracking ID</th>
                                <th>Description</th>
                                <th>Sender</th>
                                <th>Recipient</th>
                                <th>Date Received</th>
                                <th>Status</th>
                                <th>Pickup Information</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${rowsHtml}
                        </tbody>
                    </table>
                </body>
                </html>
            `;
            printHtmlOnPage(printContent);
            showToast('Print dialog opened', 'info');
        }

        function printHtmlOnPage(html) {
            const frame = document.createElement('iframe');
            frame.style.position = 'fixed';
            frame.style.right = '0';
            frame.style.bottom = '0';
            frame.style.width = '0';
            frame.style.height = '0';
            frame.style.border = '0';
            document.body.appendChild(frame);

            const frameWindow = frame.contentWindow;
            const frameDocument = frameWindow.document;
            frameDocument.open();
            frameDocument.write(html);
            frameDocument.close();

            frameWindow.focus();
            frameWindow.print();

            setTimeout(() => {
                frame.remove();
            }, 1000);
        }
    </script>
    <script src="assets/app.js"></script>
</body>

</html>