<?php
require_once './config/db.php';
session_start();

$message = '';
$error = '';

function tableHasColumn($conn, $table, $column)
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $result && $result->num_rows > 0;
}

function normalizeDateTimeInput($value)
{
    if (!$value) {
        return null;
    }

    return str_replace('T', ' ', trim($value));
}

function formatTimestampDisplay($value)
{
    if (empty($value)) {
        return 'N/A';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return htmlspecialchars($value);
    }

    return date('M j, Y g:i A', $timestamp);
}

$parcels_received_has_timestamp = tableHasColumn($conn, 'parcels_received', 'received_at');
$parcels_pickup_has_timestamp = tableHasColumn($conn, 'parcels_pickup', 'picked_at');
$received_timestamp_select = $parcels_received_has_timestamp ? "COALESCE(pr.received_at, pr.date_received) as received_timestamp" : "pr.date_received as received_timestamp";
$picked_timestamp_select = $parcels_pickup_has_timestamp ? "COALESCE(pp.picked_at, pp.date_picked) as picked_timestamp" : "pp.date_picked as picked_timestamp";

// Handle new parcel receipt
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'receive') {
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
    $parcel_id = $_POST['parcel_id'];
    $picked_by = $_POST['picked_by'];
    $phone_number = $_POST['phone_number'];
    $designation = $_POST['designation'];
    $picked_timestamp = date('Y-m-d H:i:s');
    $date_picked = date('Y-m-d');

    // Check if parcel exists and not picked up
    $check = $conn->query("SELECT * FROM parcels_received WHERE id = $parcel_id");
    if ($check->num_rows > 0) {
        $check_pickup = $conn->query("SELECT * FROM parcels_pickup WHERE parcel_id = $parcel_id");
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
    <title>Parcel Management System - Mailroom</title>
    <link rel="icon" type="image/png" href="./images/logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #f5f5f4;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 0.75rem 1rem;
            border-bottom: 2px solid #e5e5e5;
            font-weight: 500;
            color: #4a4a4a;
        }

        td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #e5e5e5;
        }

        .tab-button.active {
            background-color: white;
            border-bottom: 2px solid #1e1e1e;
            color: #1e1e1e;
        }

        .stat-card {
            transition: all 0.2s ease;
        }

        .stat-card:hover {
            border-color: #9e9e9e;
        }

        .badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            font-size: 0.7rem;
            border-radius: 3px;
            background-color: #f5f5f4;
            color: #4a4a4a;
        }

        .badge-success {
            background-color: #e8f0e8;
            color: #2c5e2c;
        }

        .badge-warning {
            background-color: #fef7e0;
            color: #9e6b0b;
        }

        .badge-info {
            background-color: #e3f2fd;
            color: #0b5e8a;
        }

        /* Toast notification styles */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }

        .toast {
            min-width: 300px;
            margin-bottom: 10px;
            padding: 15px 20px;
            background: white;
            border-left: 4px solid;
            border-radius: 4px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: center;
            justify-content: space-between;
            animation: slideIn 0.3s ease;
        }

        .toast.success {
            border-left-color: #2c5e2c;
        }

        .toast.error {
            border-left-color: #dc2626;
        }

        .toast.info {
            border-left-color: #2563eb;
        }

        .toast.warning {
            border-left-color: #d97706;
        }

        .toast-content {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .toast-close {
            cursor: pointer;
            color: #9e9e9e;
            font-size: 18px;
        }

        .toast-close:hover {
            color: #1e1e1e;
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes slideOut {
            from {
                transform: translateX(0);
                opacity: 1;
            }

            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }

        /* Pagination styles */
        .pagination-shell {
            padding: 1rem 1.25rem;
            border-top: 1px solid #e5e5e5;
            background: linear-gradient(180deg, #ffffff 0%, #fafaf9 100%);
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .pagination-meta {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .pagination-title {
            font-size: 0.95rem;
            font-weight: 600;
            color: #1c1917;
        }

        .pagination-subtitle {
            font-size: 0.82rem;
            color: #78716c;
        }

        .pagination-controls {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: flex-end;
            gap: 0.75rem;
        }

        .pagination-page-indicator {
            padding: 0.45rem 0.85rem;
            border-radius: 9999px;
            background-color: #f5f5f4;
            color: #44403c;
            font-size: 0.82rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .pagination {
            display: flex;
            gap: 0.4rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .pagination a,
        .pagination span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 40px;
            height: 40px;
            padding: 0 14px;
            border: 1px solid #e7e5e4;
            background: white;
            color: #292524;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            border-radius: 12px;
            transition: all 0.2s ease;
            box-shadow: 0 1px 2px rgba(28, 25, 23, 0.04);
        }

        .pagination a:hover {
            background: #f5f5f4;
            border-color: #d6d3d1;
            transform: translateY(-1px);
        }

        .pagination .active {
            background: #1c1917;
            border-color: #1c1917;
            color: white;
            box-shadow: 0 10px 20px rgba(28, 25, 23, 0.14);
        }

        .pagination .disabled {
            opacity: 0.5;
            pointer-events: none;
            box-shadow: none;
        }

        .pagination .compact {
            min-width: auto;
            padding: 0 14px;
        }

        .pagination-ellipsis {
            color: #a8a29e;
            box-shadow: none;
            border-color: transparent !important;
            background: transparent !important;
            min-width: 32px;
            padding: 0;
        }

        /* Filter panel */
        .filter-panel {
            transition: all 0.3s ease;
        }

        .filter-panel.collapsed {
            max-height: 0;
            opacity: 0;
            overflow: hidden;
            padding: 0;
            margin: 0;
        }
    </style>
</head>

<body class="bg-[#f5f5f4]">
    <div class="flex">
        <?php include './sidebar.php'; ?>

        <main class="flex-1 ml-60 min-h-screen">
            <!-- Simple header -->
            <div class="px-8 py-6 border-b border-[#e5e5e5] bg-white">
                <div class="flex justify-between items-center">
                    <div>
                        <h1 class="text-2xl font-medium text-[#1e1e1e]">Parcel Management System</h1>
                        <p class="text-sm text-[#6e6e6e] mt-1">Receive and track parcels with pickup information</p>
                    </div>
                    <div class="text-sm text-[#6e6e6e]">
                        <i class="fa-regular fa-calendar mr-1"></i> <?php echo date('l, F j, Y'); ?>
                    </div>
                </div>
            </div>

            <div class="p-8">
                <!-- Tabs -->
                <div class="border-b border-[#e5e5e5] mb-6">
                    <div class="flex gap-6">
                        <button class="tab-button active px-1 py-2 text-sm font-medium text-[#1e1e1e] border-b-2 border-[#1e1e1e]" onclick="switchTab('receive')">
                            <i class="fa-regular fa-circle-down mr-2"></i>Receive Parcel
                        </button>
                        <button class="tab-button px-1 py-2 text-sm font-medium text-[#6e6e6e] hover:text-[#1e1e1e]" onclick="switchTab('pickup')">
                            <i class="fa-regular fa-circle-up mr-2"></i>Pickup Parcel
                        </button>
                        <button class="tab-button px-1 py-2 text-sm font-medium text-[#6e6e6e] hover:text-[#1e1e1e]" onclick="switchTab('records')">
                            <i class="fa-regular fa-rectangle-list mr-2"></i>All Records
                        </button>
                    </div>
                </div>

                <!-- Receive Parcel Tab - Redesigned -->
                <div id="receiveTab" class="tab-content">
                    <!-- Quick Actions Bar -->
                    <div class="bg-white border border-[#e5e5e5] rounded-md p-4 mb-6">
                        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                            <div class="flex flex-wrap gap-2">
                                <button onclick="openReceiveModal()" class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e] font-medium flex items-center">
                                    <i class="fa-regular fa-plus mr-2 text-[#6e6e6e]"></i> New Parcel
                                </button>
                                <button onclick="exportReceiveCSV()" class="px-3 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e] flex items-center">
                                    <i class="fa-regular fa-file-excel mr-1 text-[#6e6e6e]"></i> Export
                                </button>
                                <button onclick="printReceiveRecords()" class="px-3 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e] flex items-center">
                                    <i class="fa-solid fa-print mr-1 text-[#6e6e6e]"></i> Print
                                </button>
                                <button onclick="refreshReceiveTab()" class="px-3 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e] flex items-center">
                                    <i class="fa-solid fa-rotate-right mr-1 text-[#6e6e6e]"></i> Refresh
                                </button>
                            </div>
                            <div class="flex flex-wrap gap-2 w-full md:w-auto">
                                <div class="relative flex-1 md:flex-none">
                                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-2.5 text-sm text-[#9e9e9e]"></i>
                                    <input type="text" id="receiveSearch" placeholder="Search parcels..."
                                        class="pl-9 pr-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e] w-full md:w-64"
                                        autocomplete="off">
                                </div>
                                <button onclick="filterReceiveTable(true)" class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e] whitespace-nowrap">
                                    Search
                                </button>
                                <select id="receiveFilter" class="px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e] bg-white">
                                    <option value="all">All</option>
                                    <option value="today">Today</option>
                                    <option value="week">This Week</option>
                                    <option value="month">This Month</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Stats Cards -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                        <div class="stat-card bg-white border border-[#e5e5e5] rounded-md p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-xs text-[#6e6e6e] uppercase tracking-wide">Total Parcels</p>
                                    <p class="text-2xl font-medium text-[#1e1e1e] mt-1"><?php echo $stats['parcels_received']; ?></p>
                                </div>
                                <div class="w-10 h-10 bg-[#f5f5f4] rounded-full flex items-center justify-center">
                                    <i class="fa-solid fa-box text-[#6e6e6e]"></i>
                                </div>
                            </div>
                            <p class="text-xs text-[#6e6e6e] mt-2">All time parcels received</p>
                        </div>

                        <div class="stat-card bg-white border border-[#e5e5e5] rounded-md p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-xs text-[#6e6e6e] uppercase tracking-wide">Today</p>
                                    <p class="text-2xl font-medium text-[#1e1e1e] mt-1"><?php echo $stats['today_parcels']; ?></p>
                                </div>
                                <div class="w-10 h-10 bg-[#f5f5f4] rounded-full flex items-center justify-center">
                                    <i class="fa-regular fa-calendar text-[#6e6e6e]"></i>
                                </div>
                            </div>
                            <p class="text-xs text-[#6e6e6e] mt-2">Parcels received today</p>
                        </div>

                        <div class="stat-card bg-white border border-[#e5e5e5] rounded-md p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-xs text-[#6e6e6e] uppercase tracking-wide">This Week</p>
                                    <p class="text-2xl font-medium text-[#1e1e1e] mt-1"><?php echo $stats['week_parcels']; ?></p>
                                </div>
                                <div class="w-10 h-10 bg-[#f5f5f4] rounded-full flex items-center justify-center">
                                    <i class="fa-solid fa-calendar-week text-[#6e6e6e]"></i>
                                </div>
                            </div>
                            <p class="text-xs text-[#6e6e6e] mt-2">Parcels this week</p>
                        </div>

                        <div class="stat-card bg-white border border-[#e5e5e5] rounded-md p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-xs text-[#6e6e6e] uppercase tracking-wide">Pending</p>
                                    <p class="text-2xl font-medium text-[#1e1e1e] mt-1"><?php echo $stats['pending_parcels']; ?></p>
                                </div>
                                <div class="w-10 h-10 bg-[#f5f5f4] rounded-full flex items-center justify-center">
                                    <i class="fa-regular fa-clock text-[#6e6e6e]"></i>
                                </div>
                            </div>
                            <p class="text-xs text-[#6e6e6e] mt-2">Awaiting pickup</p>
                        </div>
                    </div>

                    <!-- Recent Parcels Table -->
                    <div class="bg-white border border-[#e5e5e5] rounded-md overflow-hidden">
                        <div class="px-5 py-4 border-b border-[#e5e5e5] bg-[#fafafa] flex justify-between items-center">
                            <h2 class="text-sm font-medium text-[#1e1e1e]">Recent Parcels</h2>
                            <span class="text-xs text-[#6e6e6e]">Showing page <?php echo $recent_page; ?> of <?php echo ceil($total_records / $records_per_page); ?></span>
                        </div>
                        <div class="overflow-x-auto">
                            <table>
                                <thead>
                                    <tr class="bg-[#fafafa]">
                                        <th class="text-xs">Tracking ID</th>
                                        <th class="text-xs">Description</th>
                                        <th class="text-xs">Sender</th>
                                        <th class="text-xs">Recipient</th>
                                        <th class="text-xs">Received Timestamp</th>
                                        <th class="text-xs">Received By</th>
                                        <th class="text-xs">Status</th>
                                        <th class="text-xs">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="receiveTableBody">
                                    <?php if ($recent_parcels->num_rows > 0): ?>
                                        <?php while ($parcel = $recent_parcels->fetch_assoc()): ?>
                                            <tr class="hover:bg-[#fafafa] receive-row"
                                                data-date="<?php echo date('Y-m-d', strtotime($parcel['received_timestamp'] ?? $parcel['date_received'])); ?>"
                                                data-search="<?php echo strtolower($parcel['tracking_id'] . ' ' . $parcel['sender'] . ' ' . $parcel['addressed_to']); ?>">
                                                <td class="text-sm font-mono text-[#1e1e1e]"><?php echo $parcel['tracking_id']; ?></td>
                                                <td class="text-sm text-[#1e1e1e]"><?php echo substr($parcel['description'], 0, 30); ?>...</td>
                                                <td class="text-sm text-[#1e1e1e]"><?php echo $parcel['sender']; ?></td>
                                                <td class="text-sm text-[#1e1e1e]"><?php echo $parcel['addressed_to']; ?></td>
                                                <td class="text-sm text-[#1e1e1e] whitespace-nowrap"><?php echo formatTimestampDisplay($parcel['received_timestamp'] ?? $parcel['date_received']); ?></td>
                                                <td class="text-sm text-[#1e1e1e]"><?php echo $parcel['received_by']; ?></td>
                                                <td class="text-sm">
                                                    <?php if ($parcel['status'] == 'Pending'): ?>
                                                        <span class="badge badge-warning">Pending</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-success">Picked Up</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-sm">
                                                    <button onclick="viewParcelDetails(<?php echo htmlspecialchars(json_encode($parcel)); ?>)"
                                                        class="text-[#9e9e9e] hover:text-[#1e1e1e] mr-2" title="View Details">
                                                        <i class="fa-solid fa-eye"></i>
                                                    </button>
                                                    <?php if ($parcel['status'] == 'Pending'): ?>
                                                        <button onclick='openEditReceiveModal(<?php echo htmlspecialchars(json_encode([
                                                                                                    "id" => $parcel["id"],
                                                                                                    "tracking_id" => $parcel["tracking_id"],
                                                                                                    "description" => $parcel["description"],
                                                                                                    "sender" => $parcel["sender"],
                                                                                                    "addressed_to" => $parcel["addressed_to"],
                                                                                                    "received_by" => $parcel["received_by"],
                                                                                                    "received_timestamp" => $parcel["received_timestamp"] ?? $parcel["date_received"]
                                                                                                ]), ENT_QUOTES, "UTF-8"); ?>)'
                                                            class="text-[#6e6e6e] hover:text-[#1d4ed8] mr-2" title="Edit Parcel">
                                                            <i class="fa-regular fa-pen-to-square"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <button onclick="openDeleteReceiveModal(<?php echo $parcel['id']; ?>, '<?php echo htmlspecialchars($parcel['tracking_id'], ENT_QUOTES); ?>')"
                                                        class="text-[#6e6e6e] hover:text-[#991b1b]" title="Delete Parcel">
                                                        <i class="fa-regular fa-trash-can"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-sm text-[#6e6e6e] text-center py-8">No parcels found</td>
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
                                    <div class="pagination-subtitle">Records <?php echo $receiveFrom; ?>-<?php echo $receiveTo; ?> of <?php echo $total_records; ?> total</div>
                                </div>
                                <div class="pagination-controls">
                                    <div class="pagination-page-indicator">Page <?php echo $recent_page; ?> of <?php echo $receiveTotalPages; ?></div>
                                    <div class="pagination">
                                        <a class="compact <?php echo $recent_page <= 1 ? 'disabled' : ''; ?>" href="?recent_page=1&tab=receive"><i class="fa-regular fa-chevrons-left"></i></a>
                                        <a class="compact <?php echo $recent_page <= 1 ? 'disabled' : ''; ?>" href="?recent_page=<?php echo max(1, $recent_page - 1); ?>&tab=receive"><i class="fa-regular fa-chevron-left"></i></a>
                                        <?php if ($receiveStart > 1): ?>
                                            <a href="?recent_page=1&tab=receive">1</a>
                                            <?php if ($receiveStart > 2): ?><span class="pagination-ellipsis">...</span><?php endif; ?>
                                        <?php endif; ?>
                                        <?php for ($i = $receiveStart; $i <= $receiveEnd; $i++): ?>
                                            <?php if ($i == $recent_page): ?>
                                                <span class="active"><?php echo $i; ?></span>
                                            <?php else: ?>
                                                <a href="?recent_page=<?php echo $i; ?>&tab=receive"><?php echo $i; ?></a>
                                            <?php endif; ?>
                                        <?php endfor; ?>
                                        <?php if ($receiveEnd < $receiveTotalPages): ?>
                                            <?php if ($receiveEnd < $receiveTotalPages - 1): ?><span class="pagination-ellipsis">...</span><?php endif; ?>
                                            <a href="?recent_page=<?php echo $receiveTotalPages; ?>&tab=receive"><?php echo $receiveTotalPages; ?></a>
                                        <?php endif; ?>
                                        <a class="compact <?php echo $recent_page >= $receiveTotalPages ? 'disabled' : ''; ?>" href="?recent_page=<?php echo min($receiveTotalPages, $recent_page + 1); ?>&tab=receive"><i class="fa-regular fa-chevron-right"></i></a>
                                        <a class="compact <?php echo $recent_page >= $receiveTotalPages ? 'disabled' : ''; ?>" href="?recent_page=<?php echo $receiveTotalPages; ?>&tab=receive"><i class="fa-regular fa-chevrons-right"></i></a>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Pickup Parcel Tab -->
                <div id="pickupTab" class="tab-content hidden">
                    <!-- Search and filter -->
                    <div class="bg-white border border-[#e5e5e5] rounded-md p-4 mb-6">
                        <div class="flex flex-col md:flex-row gap-3">
                            <div class="flex-1">
                                <div class="relative flex items-center gap-2">
                                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-2.5 text-sm text-[#9e9e9e]"></i>
                                    <input type="text" id="searchPickup" placeholder="Search by tracking ID, sender, or recipient..."
                                        class="w-full pl-9 pr-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]"
                                        autocomplete="off">
                                    <button onclick="filterPickupTable(true)" class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e] whitespace-nowrap">
                                        Search
                                    </button>
                                </div>
                            </div>
                            <select id="statusFilterPickup" class="px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e] bg-white">
                                <option value="all">All Status</option>
                                <option value="pending">Pending</option>
                                <option value="picked-up">Picked Up</option>
                            </select>
                        </div>
                    </div>

                    <!-- Parcels table -->
                    <div class="bg-white border border-[#e5e5e5] rounded-md overflow-hidden">
                        <div class="px-5 py-4 border-b border-[#e5e5e5] bg-[#fafafa] flex justify-between items-center">
                            <h2 class="text-sm font-medium text-[#1e1e1e]">Parcels for Pickup</h2>
                            <span class="text-xs text-[#6e6e6e]">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                        </div>
                        <div class="overflow-x-auto">
                            <table>
                                <thead>
                                    <tr class="bg-[#fafafa]">
                                        <th class="text-xs">Tracking ID</th>
                                        <th class="text-xs">Description</th>
                                        <th class="text-xs">Sender</th>
                                        <th class="text-xs">Recipient</th>
                                        <th class="text-xs">Received Timestamp</th>
                                        <th class="text-xs">Picked Up Timestamp</th>
                                        <th class="text-xs">Status</th>
                                        <th class="text-xs">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="pickupTableBody">
                                    <?php
                                    $parcels->data_seek(0);
                                    while ($parcel = $parcels->fetch_assoc()):
                                    ?>
                                        <tr class="hover:bg-[#fafafa] pickup-row"
                                            data-status="<?php echo strtolower(str_replace(' ', '-', $parcel['status'])); ?>"
                                            data-search="<?php echo strtolower($parcel['tracking_id'] . ' ' . $parcel['sender'] . ' ' . $parcel['addressed_to']); ?>">
                                            <td class="text-sm font-mono text-[#1e1e1e]"><?php echo $parcel['tracking_id']; ?></td>
                                            <td class="text-sm text-[#1e1e1e]"><?php echo substr($parcel['description'], 0, 30); ?>...</td>
                                            <td class="text-sm text-[#1e1e1e]"><?php echo $parcel['sender']; ?></td>
                                            <td class="text-sm text-[#1e1e1e]"><?php echo $parcel['addressed_to']; ?></td>
                                            <td class="text-sm text-[#1e1e1e] whitespace-nowrap"><?php echo formatTimestampDisplay($parcel['received_timestamp'] ?? $parcel['date_received']); ?></td>
                                            <td class="text-sm text-[#1e1e1e] whitespace-nowrap"><?php echo formatTimestampDisplay($parcel['picked_timestamp'] ?? $parcel['date_picked']); ?></td>
                                            <td class="text-sm">
                                                <?php if ($parcel['status'] == 'Pending'): ?>
                                                    <span class="badge badge-warning">Pending</span>
                                                <?php else: ?>
                                                    <span class="badge badge-success">Picked up</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-sm">
                                                <?php if ($parcel['status'] == 'Pending'): ?>
                                                    <button onclick="openPickupModal(<?php echo $parcel['id']; ?>, '<?php echo $parcel['tracking_id']; ?>')"
                                                        class="px-3 py-1 text-xs border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                                                        <i class="fa-solid fa-truck mr-1"></i> Process
                                                    </button>
                                                <?php else: ?>
                                                    <button onclick="viewPickupDetails(<?php echo $parcel['id']; ?>, '<?php echo $parcel['tracking_id']; ?>', '<?php echo $parcel['picked_by']; ?>', '<?php echo $parcel['picker_phone']; ?>', '<?php echo $parcel['picker_designation']; ?>', '<?php echo $parcel['picked_timestamp'] ?? $parcel['date_picked']; ?>')"
                                                        class="text-[#9e9e9e] hover:text-[#1e1e1e]">
                                                        <i class="fa-solid fa-circle-info"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination for Pickup Tab -->
                        <?php if ($total_pages > 1): ?>
                            <?php
                            $pickupStart = max(1, $page - 2);
                            $pickupEnd = min($total_pages, $page + 2);
                            $pickupFrom = (($page - 1) * $limit) + 1;
                            $pickupTo = min($page * $limit, $total_parcels);
                            ?>
                            <div class="pagination-shell">
                                <div class="pagination-meta">
                                    <div class="pagination-title">Showing pickups on this page</div>
                                    <div class="pagination-subtitle">Records <?php echo $pickupFrom; ?>-<?php echo $pickupTo; ?> of <?php echo $total_parcels; ?> total</div>
                                </div>
                                <div class="pagination-controls">
                                    <div class="pagination-page-indicator">Page <?php echo $page; ?> of <?php echo $total_pages; ?></div>
                                    <div class="pagination">
                                        <a class="compact <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="?page=1&tab=pickup"><i class="fa-regular fa-chevrons-left"></i></a>
                                        <a class="compact <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="?page=<?php echo max(1, $page - 1); ?>&tab=pickup"><i class="fa-regular fa-chevron-left"></i></a>
                                        <?php if ($pickupStart > 1): ?>
                                            <a href="?page=1&tab=pickup">1</a>
                                            <?php if ($pickupStart > 2): ?><span class="pagination-ellipsis">...</span><?php endif; ?>
                                        <?php endif; ?>
                                        <?php for ($i = $pickupStart; $i <= $pickupEnd; $i++): ?>
                                            <?php if ($i == $page): ?>
                                                <span class="active"><?php echo $i; ?></span>
                                            <?php else: ?>
                                                <a href="?page=<?php echo $i; ?>&tab=pickup"><?php echo $i; ?></a>
                                            <?php endif; ?>
                                        <?php endfor; ?>
                                        <?php if ($pickupEnd < $total_pages): ?>
                                            <?php if ($pickupEnd < $total_pages - 1): ?><span class="pagination-ellipsis">...</span><?php endif; ?>
                                            <a href="?page=<?php echo $total_pages; ?>&tab=pickup"><?php echo $total_pages; ?></a>
                                        <?php endif; ?>
                                        <a class="compact <?php echo $page >= $total_pages ? 'disabled' : ''; ?>" href="?page=<?php echo min($total_pages, $page + 1); ?>&tab=pickup"><i class="fa-regular fa-chevron-right"></i></a>
                                        <a class="compact <?php echo $page >= $total_pages ? 'disabled' : ''; ?>" href="?page=<?php echo $total_pages; ?>&tab=pickup"><i class="fa-regular fa-chevrons-right"></i></a>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- REDESIGNED ALL RECORDS TAB -->
                <div id="recordsTab" class="tab-content hidden">
                    <!-- Quick Actions Bar -->
                    <div class="bg-white border border-[#e5e5e5] rounded-md p-4 mb-6">
                        <div class="flex flex-wrap justify-between items-center gap-4">
                            <div class="flex flex-wrap gap-2">
                                <button onclick="exportToCSV()" class="px-3 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e] flex items-center">
                                    <i class="fa-regular fa-file-excel mr-1 text-[#6e6e6e]"></i> Export CSV
                                </button>
                                <!-- <button onclick="exportToPDF()" class="px-3 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e] flex items-center">
                                    <i class="fa-regular fa-file-pdf mr-1 text-[#6e6e6e]"></i> Export PDF
                                </button>-->
                                <button onclick="printRecords()" class="px-3 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e] flex items-center">
                                    <i class="fa-solid fa-print mr-1 text-[#6e6e6e]"></i> Print
                                </button>
                            </div>
                            <div class="text-sm text-[#6e6e6e]">
                                Total Records: <span class="font-medium text-[#1e1e1e]"><?php echo $total_records; ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white border border-[#e5e5e5] rounded-md p-4 mb-6">
                        <div class="flex flex-wrap items-center gap-3">
                            <div class="relative flex-1 min-w-[260px]">
                                <i class="fa-solid fa-magnifying-glass absolute left-3 top-2.5 text-sm text-[#9e9e9e]"></i>
                                <input type="text" id="quickSearch" placeholder="Search by tracking ID, sender, recipient, or picker..."
                                    class="w-full pl-9 pr-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e] focus:ring-1 focus:ring-[#9e9e9e]"
                                    autocomplete="off">
                            </div>

                            <select id="filterStatus" class="min-w-[150px] px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e] bg-white">
                                <option value="all">All Status</option>
                                <option value="pending">Pending</option>
                                <option value="picked-up">Picked Up</option>
                            </select>

                            <input type="date" id="dateFrom" class="min-w-[150px] px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]" autocomplete="off">
                            <input type="date" id="dateTo" class="min-w-[150px] px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]" autocomplete="off">

                            <button onclick="applyQuickSearch()" class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e] whitespace-nowrap">
                                Search
                            </button>

                            <button onclick="clearSearch()" class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e] whitespace-nowrap">
                                Reset
                            </button>

                            <span class="text-xs text-[#6e6e6e] whitespace-nowrap ml-auto" id="activeFiltersCount">No active filters</span>
                        </div>
                    </div>

                    <!-- Records Table - Condensed View -->
                    <div class="bg-white border border-[#e5e5e5] rounded-md overflow-hidden">
                        <div class="px-5 py-4 border-b border-[#e5e5e5] bg-[#fafafa] flex justify-between items-center">
                            <h2 class="text-sm font-medium text-[#1e1e1e]">All Parcel Records</h2>
                            <span class="text-xs text-[#6e6e6e]">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                        </div>
                        <div class="overflow-x-auto">
                            <table id="recordsTable">
                                <thead>
                                    <tr class="bg-[#fafafa]">
                                        <th class="text-xs">Tracking ID</th>
                                        <th class="text-xs">Description</th>
                                        <th class="text-xs">Sender</th>
                                        <th class="text-xs">Recipient</th>
                                        <th class="text-xs">Received</th>
                                        <th class="text-xs">Status</th>
                                        <th class="text-xs">Pickup Info</th>
                                        <th class="text-xs">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="recordsTableBody">
                                    <?php
                                    $parcels->data_seek(0);
                                    while ($parcel = $parcels->fetch_assoc()):
                                    ?>
                                        <tr class="hover:bg-[#fafafa] record-row"
                                            data-status="<?php echo strtolower(str_replace(' ', '-', $parcel['status'])); ?>"
                                            data-search="<?php echo strtolower($parcel['tracking_id'] . ' ' . $parcel['sender'] . ' ' . $parcel['addressed_to'] . ' ' . ($parcel['picked_by'] ?? '')); ?>"
                                            data-tracking="<?php echo strtolower($parcel['tracking_id']); ?>"
                                            data-sender="<?php echo strtolower($parcel['sender']); ?>"
                                            data-recipient="<?php echo strtolower($parcel['addressed_to']); ?>"
                                            data-picker="<?php echo strtolower($parcel['picked_by'] ?? ''); ?>"
                                            data-date="<?php echo date('Y-m-d', strtotime($parcel['received_timestamp'] ?? $parcel['date_received'])); ?>">
                                            <td class="text-sm font-mono text-[#1e1e1e]"><?php echo $parcel['tracking_id']; ?></td>
                                            <td class="text-sm text-[#1e1e1e] max-w-[200px] truncate"><?php echo substr($parcel['description'], 0, 30); ?>...</td>
                                            <td class="text-sm text-[#1e1e1e]"><?php echo $parcel['sender']; ?></td>
                                            <td class="text-sm text-[#1e1e1e]"><?php echo $parcel['addressed_to']; ?></td>
                                            <td class="text-sm text-[#1e1e1e] whitespace-nowrap"><?php echo formatTimestampDisplay($parcel['received_timestamp'] ?? $parcel['date_received']); ?></td>
                                            <td class="text-sm">
                                                <?php if ($parcel['status'] == 'Pending'): ?>
                                                    <span class="badge badge-warning">Pending</span>
                                                <?php else: ?>
                                                    <span class="badge badge-success">Picked Up</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-sm text-[#1e1e1e]">
                                                <?php if ($parcel['picked_by']): ?>
                                                    <div class="text-xs">
                                                        <span class="font-medium"><?php echo $parcel['picked_by']; ?></span>
                                                        <?php if ($parcel['date_picked']): ?>
                                                            <span class="text-[#6e6e6e] block"><?php echo formatTimestampDisplay($parcel['picked_timestamp'] ?? $parcel['date_picked']); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-[#9e9e9e] text-xs">Not picked up</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-sm">
                                                <div class="flex items-center gap-2">
                                                    <button onclick="viewParcelDetails(<?php echo htmlspecialchars(json_encode($parcel)); ?>)"
                                                        class="text-[#9e9e9e] hover:text-[#1e1e1e]" title="View Details">
                                                        <i class="fa-regular fa-eye"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination for Records Tab -->
                        <?php if ($total_pages > 1): ?>
                            <?php
                            $recordStart = max(1, $page - 2);
                            $recordEnd = min($total_pages, $page + 2);
                            $recordFrom = (($page - 1) * $limit) + 1;
                            $recordTo = min($page * $limit, $total_parcels);
                            ?>
                            <div class="pagination-shell">
                                <div class="pagination-meta">
                                    <div class="pagination-title">Showing parcel records on this page</div>
                                    <div class="pagination-subtitle">Records <?php echo $recordFrom; ?>-<?php echo $recordTo; ?> of <?php echo $total_parcels; ?> total</div>
                                </div>
                                <div class="pagination-controls">
                                    <div class="pagination-page-indicator">Page <?php echo $page; ?> of <?php echo $total_pages; ?></div>
                                    <div class="pagination">
                                        <a class="compact <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="?page=1&tab=records"><i class="fa-regular fa-chevrons-left"></i></a>
                                        <a class="compact <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="?page=<?php echo max(1, $page - 1); ?>&tab=records"><i class="fa-regular fa-chevron-left"></i></a>
                                        <?php if ($recordStart > 1): ?>
                                            <a href="?page=1&tab=records">1</a>
                                            <?php if ($recordStart > 2): ?><span class="pagination-ellipsis">...</span><?php endif; ?>
                                        <?php endif; ?>
                                        <?php for ($i = $recordStart; $i <= $recordEnd; $i++): ?>
                                            <?php if ($i == $page): ?>
                                                <span class="active"><?php echo $i; ?></span>
                                            <?php else: ?>
                                                <a href="?page=<?php echo $i; ?>&tab=records"><?php echo $i; ?></a>
                                            <?php endif; ?>
                                        <?php endfor; ?>
                                        <?php if ($recordEnd < $total_pages): ?>
                                            <?php if ($recordEnd < $total_pages - 1): ?><span class="pagination-ellipsis">...</span><?php endif; ?>
                                            <a href="?page=<?php echo $total_pages; ?>&tab=records"><?php echo $total_pages; ?></a>
                                        <?php endif; ?>
                                        <a class="compact <?php echo $page >= $total_pages ? 'disabled' : ''; ?>" href="?page=<?php echo min($total_pages, $page + 1); ?>&tab=records"><i class="fa-regular fa-chevron-right"></i></a>
                                        <a class="compact <?php echo $page >= $total_pages ? 'disabled' : ''; ?>" href="?page=<?php echo $total_pages; ?>&tab=records"><i class="fa-regular fa-chevrons-right"></i></a>
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
    <div id="receiveModal" class="fixed inset-0 bg-[#000000] bg-opacity-20 hidden items-center justify-center z-50" style="display: none;">
        <div class="bg-white border border-[#e5e5e5] rounded-md w-full max-w-2xl p-6">
            <div class="flex justify-between items-center mb-5">
                <h3 class="text-base font-medium text-[#1e1e1e]">Receive New Parcel</h3>
                <button onclick="closeReceiveModal()" class="text-[#6e6e6e] hover:text-[#1e1e1e]">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>

            <form id="receiveForm" onsubmit="submitReceiveForm(event)">
                <input type="hidden" name="action" value="receive">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div class="md:col-span-2">
                        <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Description <span class="text-red-400">*</span></label>
                        <textarea name="description" rows="3" required
                            class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e] focus:ring-1 focus:ring-[#9e9e9e]"
                            placeholder="Enter parcel description"
                            autocomplete="off"></textarea>
                    </div>

                    <div>
                        <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Sender <span class="text-red-400">*</span></label>
                        <input type="text" name="sender" required
                            class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e] focus:ring-1 focus:ring-[#9e9e9e]"
                            placeholder="Sender name"
                            autocomplete="off">
                    </div>

                    <div>
                        <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Addressed To <span class="text-red-400">*</span></label>
                        <input type="text" name="addressed_to" required
                            class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e] focus:ring-1 focus:ring-[#9e9e9e]"
                            placeholder="Recipient name"
                            autocomplete="off">
                    </div>

                    <div>
                        <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Received Timestamp <span class="text-red-400">*</span></label>
                        <input type="datetime-local" name="date_received" required value="<?php echo date('Y-m-d\TH:i'); ?>"
                            class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e] focus:ring-1 focus:ring-[#9e9e9e]"
                            autocomplete="off">
                    </div>

                    <div>
                        <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Received By <span class="text-red-400">*</span></label>
                        <input type="text" name="received_by" required
                            class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e] focus:ring-1 focus:ring-[#9e9e9e]"
                            placeholder="Staff name"
                            autocomplete="off">
                    </div>

                    <div class="md:col-span-2">
                        <div class="bg-[#fafafa] p-3 rounded-md border border-[#e5e5e5]">
                            <p class="text-xs text-[#6e6e6e]">
                                <i class="fa-solid fa-circle-info mr-1"></i>
                                Tracking ID will be automatically generated as: <span class="font-mono">PRCL-<?php echo date('Ymd'); ?>-XXXXXX</span>
                            </p>
                        </div>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" onclick="closeReceiveModal()"
                        class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                        Cancel
                    </button>
                    <button type="submit"
                        class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e] font-medium">
                        <i class="fa-regular fa-floppy-disk mr-1 text-[#6e6e6e]"></i>
                        Receive Parcel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="editReceiveModal" class="fixed inset-0 bg-[#000000] bg-opacity-20 hidden items-center justify-center z-50" style="display: none;">
        <div class="bg-white border border-[#e5e5e5] rounded-md w-full max-w-2xl p-6">
            <div class="flex justify-between items-center mb-5">
                <div>
                    <h3 class="text-base font-medium text-[#1e1e1e]">Edit Pending Parcel</h3>
                    <p class="text-xs text-[#6e6e6e] mt-1">Tracking ID: <span id="editTrackingId" class="font-mono"></span></p>
                </div>
                <button onclick="closeEditReceiveModal()" class="text-[#6e6e6e] hover:text-[#1e1e1e]">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>

            <form id="editReceiveForm" onsubmit="submitEditReceiveForm(event)">
                <input type="hidden" name="action" value="edit_received">
                <input type="hidden" name="parcel_id" id="editParcelId">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div class="md:col-span-2">
                        <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Description <span class="text-red-400">*</span></label>
                        <textarea name="description" id="editDescription" rows="3" required
                            class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e] focus:ring-1 focus:ring-[#9e9e9e]"
                            autocomplete="off"></textarea>
                    </div>

                    <div>
                        <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Sender <span class="text-red-400">*</span></label>
                        <input type="text" name="sender" id="editSender" required
                            class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e] focus:ring-1 focus:ring-[#9e9e9e]"
                            autocomplete="off">
                    </div>

                    <div>
                        <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Addressed To <span class="text-red-400">*</span></label>
                        <input type="text" name="addressed_to" id="editAddressedTo" required
                            class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e] focus:ring-1 focus:ring-[#9e9e9e]"
                            autocomplete="off">
                    </div>

                    <div>
                        <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Received By <span class="text-red-400">*</span></label>
                        <input type="text" name="received_by" id="editReceivedBy" required
                            class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e] focus:ring-1 focus:ring-[#9e9e9e]"
                            autocomplete="off">
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" onclick="closeEditReceiveModal()"
                        class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                        Cancel
                    </button>
                    <button type="submit"
                        class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e] font-medium">
                        <i class="fa-regular fa-floppy-disk mr-1 text-[#6e6e6e]"></i>
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Pickup Modal -->
    <div id="pickupModal" class="fixed inset-0 bg-[#000000] bg-opacity-20 hidden items-center justify-center z-50" style="display: none;">
        <div class="bg-white border border-[#e5e5e5] rounded-md w-full max-w-md p-5">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-base font-medium text-[#1e1e1e]">Process Pickup</h3>
                <button onclick="closePickupModal()" class="text-[#6e6e6e] hover:text-[#1e1e1e]">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>
            <form id="pickupForm" onsubmit="submitPickupForm(event)">
                <input type="hidden" name="parcel_id" id="modalParcelId">

                <div class="mb-4">
                    <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Tracking ID</label>
                    <input type="text" id="modalTrackingId" readonly
                        class="w-full px-3 py-2 text-sm bg-[#fafafa] border border-[#e5e5e5] rounded-md"
                        autocomplete="off">
                </div>

                <div class="mb-4">
                    <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Picked By <span class="text-red-400">*</span></label>
                    <input type="text" name="picked_by" required
                        class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]"
                        placeholder="Name of person picking up"
                        autocomplete="off">
                </div>

                <div class="mb-4">
                    <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Phone Number <span class="text-red-400">*</span></label>
                    <input type="text" name="phone_number" required
                        class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]"
                        placeholder="Contact number"
                        autocomplete="off">
                </div>

                <div class="mb-4">
                    <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Designation <span class="text-red-400">*</span></label>
                    <input type="text" name="designation" required
                        class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]"
                        placeholder="e.g., Staff, Student, etc."
                        autocomplete="off">
                </div>

                <div class="flex justify-end gap-2">
                    <button type="button" onclick="closePickupModal()"
                        class="px-3 py-1.5 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                        Cancel
                    </button>
                    <button type="submit"
                        class="px-3 py-1.5 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                        Confirm Pickup
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="deleteReceiveModal" class="fixed inset-0 bg-[#000000] bg-opacity-20 hidden items-center justify-center z-50" style="display: none;">
        <div class="bg-white border border-[#e5e5e5] rounded-md w-full max-w-md p-5">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-base font-medium text-[#1e1e1e]">Delete Parcel</h3>
                <button onclick="closeDeleteReceiveModal()" class="text-[#6e6e6e] hover:text-[#1e1e1e]">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>

            <form id="deleteReceiveForm" onsubmit="submitDeleteReceiveForm(event)">
                <input type="hidden" name="action" value="delete_received">
                <input type="hidden" name="parcel_id" id="deleteParcelId">

                <div class="mb-5 p-3 bg-[#fafafa] border border-[#e5e5e5] rounded-md">
                    <p class="text-sm text-[#1e1e1e]">Are you sure you want to delete parcel <span id="deleteTrackingId" class="font-mono font-medium"></span>?</p>
                    <p class="text-xs text-[#6e6e6e] mt-2">This also removes any pickup record linked to it.</p>
                </div>

                <div class="flex justify-end gap-2">
                    <button type="button" onclick="closeDeleteReceiveModal()"
                        class="px-3 py-1.5 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                        Cancel
                    </button>
                    <button type="submit"
                        class="px-3 py-1.5 text-sm bg-[#dc2626] text-white rounded-md hover:bg-[#b91c1c]">
                        Delete Parcel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Pickup Details Modal -->
    <div id="detailsModal" class="fixed inset-0 bg-[#000000] bg-opacity-20 hidden items-center justify-center z-50" style="display: none;">
        <div class="bg-white border border-[#e5e5e5] rounded-md w-full max-w-md p-5">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-base font-medium text-[#1e1e1e]">Pickup Details</h3>
                <button onclick="closeDetailsModal()" class="text-[#6e6e6e] hover:text-[#1e1e1e]">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>
            <div class="space-y-3">
                <div>
                    <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Tracking ID</label>
                    <p id="detailTrackingId" class="text-sm text-[#1e1e1e] font-mono"></p>
                </div>
                <div>
                    <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Picked By</label>
                    <p id="detailPickedBy" class="text-sm text-[#1e1e1e]"></p>
                </div>
                <div>
                    <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Phone Number</label>
                    <p id="detailPhone" class="text-sm text-[#1e1e1e]"></p>
                </div>
                <div>
                    <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Designation</label>
                    <p id="detailDesignation" class="text-sm text-[#1e1e1e]"></p>
                </div>
                <div>
                    <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Date Picked</label>
                    <p id="detailDate" class="text-sm text-[#1e1e1e]"></p>
                </div>
            </div>
            <div class="flex justify-end mt-4">
                <button onclick="closeDetailsModal()"
                    class="px-3 py-1.5 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                    Close
                </button>
            </div>
        </div>
    </div>

    <!-- Parcel Details Modal -->
    <div id="parcelDetailsModal" class="fixed inset-0 bg-[#000000] bg-opacity-20 hidden items-center justify-center z-50" style="display: none;">
        <div class="bg-white border border-[#e5e5e5] rounded-md w-full max-w-2xl p-5">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-base font-medium text-[#1e1e1e]">Parcel Details</h3>
                <button onclick="closeParcelDetailsModal()" class="text-[#6e6e6e] hover:text-[#1e1e1e]">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>
            <div class="grid grid-cols-2 gap-4" id="parcelDetailContent">
                <!-- Filled by JavaScript -->
            </div>
            <div class="flex justify-end mt-4">
                <button onclick="closeParcelDetailsModal()"
                    class="px-3 py-1.5 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                    Close
                </button>
            </div>
        </div>
    </div>

    <script>
        // Toast notification functions
        function showToast(message, type = 'success') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;

            let icon = 'fa-circle-check';
            if (type === 'error') icon = 'fa-circle-exclamation';
            if (type === 'warning') icon = 'fa-triangle-exclamation';
            if (type === 'info') icon = 'fa-circle-info';

            toast.innerHTML = `
                <div class="toast-content">
                    <i class="fa-regular ${icon} text-${type === 'success' ? '[#2c5e2c]' : type === 'error' ? '[#dc2626]' : type === 'warning' ? '[#d97706]' : '[#2563eb]'}"></i>
                    <span class="text-sm">${message}</span>
                </div>
                <span class="toast-close" onclick="this.parentElement.remove()">&times;</span>
            `;

            container.appendChild(toast);

            // Auto remove after 5 seconds
            setTimeout(() => {
                if (toast.parentElement) {
                    toast.style.animation = 'slideOut 0.3s ease';
                    setTimeout(() => {
                        if (toast.parentElement) {
                            toast.remove();
                        }
                    }, 300);
                }
            }, 5000);
        }

        // Tab switching with URL parameter
        function switchTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.add('hidden');
            });

            // Remove active class from all buttons
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('active', 'border-[#1e1e1e]', 'text-[#1e1e1e]');
                button.classList.add('text-[#6e6e6e]');
            });

            // Show selected tab
            document.getElementById(tabName + 'Tab').classList.remove('hidden');

            // Add active class to clicked button
            event.target.classList.add('active', 'border-[#1e1e1e]', 'text-[#1e1e1e]');
            event.target.classList.remove('text-[#6e6e6e]');

            // Update URL parameter
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);
        }

        // Check URL parameter on load
        window.addEventListener('load', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const tab = urlParams.get('tab');
            if (tab) {
                const tabButton = Array.from(document.querySelectorAll('.tab-button')).find(btn =>
                    btn.textContent.toLowerCase().includes(tab)
                );
                if (tabButton) {
                    tabButton.click();
                }
            }
        });

        // Receive Modal functions
        function openReceiveModal() {
            document.getElementById('receiveModal').style.display = 'flex';
        }

        function closeReceiveModal() {
            document.getElementById('receiveModal').style.display = 'none';
            document.getElementById('receiveForm').reset();
        }

        function openEditReceiveModal(parcel) {
            document.getElementById('editReceiveModal').style.display = 'flex';
            document.getElementById('editParcelId').value = parcel.id || '';
            document.getElementById('editTrackingId').textContent = parcel.tracking_id || '';
            document.getElementById('editDescription').value = parcel.description || '';
            document.getElementById('editSender').value = parcel.sender || '';
            document.getElementById('editAddressedTo').value = parcel.addressed_to || '';
            document.getElementById('editReceivedBy').value = parcel.received_by || '';
        }

        function closeEditReceiveModal() {
            document.getElementById('editReceiveModal').style.display = 'none';
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
            document.getElementById('pickupModal').style.display = 'flex';
            document.getElementById('modalParcelId').value = id;
            document.getElementById('modalTrackingId').value = trackingId;
        }

        function closePickupModal() {
            document.getElementById('pickupModal').style.display = 'none';
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
            document.getElementById('deleteReceiveModal').style.display = 'flex';
            document.getElementById('deleteParcelId').value = id;
            document.getElementById('deleteTrackingId').textContent = trackingId;
        }

        function closeDeleteReceiveModal() {
            document.getElementById('deleteReceiveModal').style.display = 'none';
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
            document.getElementById('detailsModal').style.display = 'flex';
        }

        function closeDetailsModal() {
            document.getElementById('detailsModal').style.display = 'none';
        }

        // Parcel Details Modal
        function viewParcelDetails(parcel) {
            const content = document.getElementById('parcelDetailContent');
            content.innerHTML = `
                <div class="col-span-2">
                    <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Description</label>
                    <p class="text-sm text-[#1e1e1e]">${parcel.description || 'N/A'}</p>
                </div>
                <div>
                    <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Tracking ID</label>
                    <p class="text-sm text-[#1e1e1e] font-mono">${parcel.tracking_id || 'N/A'}</p>
                </div>
                <div>
                    <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Status</label>
                    <p class="text-sm text-[#1e1e1e]">${parcel.status || 'N/A'}</p>
                </div>
                <div>
                    <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Sender</label>
                    <p class="text-sm text-[#1e1e1e]">${parcel.sender || 'N/A'}</p>
                </div>
                <div>
                    <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Recipient</label>
                    <p class="text-sm text-[#1e1e1e]">${parcel.addressed_to || 'N/A'}</p>
                </div>
                <div>
                    <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Received Timestamp</label>
                    <p class="text-sm text-[#1e1e1e]">${parcel.received_timestamp ? new Date(parcel.received_timestamp.replace(' ', 'T')).toLocaleString() : (parcel.date_received || 'N/A')}</p>
                </div>
                <div>
                    <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Received By</label>
                    <p class="text-sm text-[#1e1e1e]">${parcel.received_by || 'N/A'}</p>
                </div>
                ${parcel.status === 'Picked Up' ? `
                    <div>
                        <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Picked By</label>
                        <p class="text-sm text-[#1e1e1e]">${parcel.picked_by || 'N/A'}</p>
                    </div>
                    <div>
                        <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Picker Phone</label>
                        <p class="text-sm text-[#1e1e1e]">${parcel.picker_phone || 'N/A'}</p>
                    </div>
                    <div>
                        <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Designation</label>
                        <p class="text-sm text-[#1e1e1e]">${parcel.picker_designation || 'N/A'}</p>
                    </div>
                    <div>
                        <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Picked Up Timestamp</label>
                        <p class="text-sm text-[#1e1e1e]">${parcel.picked_timestamp ? new Date(parcel.picked_timestamp.replace(' ', 'T')).toLocaleString() : (parcel.date_picked || 'N/A')}</p>
                    </div>
                ` : ''}
            `;
            document.getElementById('parcelDetailsModal').style.display = 'flex';
        }

        function closeParcelDetailsModal() {
            document.getElementById('parcelDetailsModal').style.display = 'none';
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

            document.getElementById('totalRecords').textContent = visibleCount;

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

            document.getElementById('totalRecords').textContent = rows.length;
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
            location.reload();
            showToast('Refreshing data...', 'info');
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

        // Close modals when clicking outside
        window.onclick = function(event) {
            const receiveModal = document.getElementById('receiveModal');
            const editReceiveModal = document.getElementById('editReceiveModal');
            const pickupModal = document.getElementById('pickupModal');
            const deleteReceiveModal = document.getElementById('deleteReceiveModal');
            const detailsModal = document.getElementById('detailsModal');
            const parcelDetailsModal = document.getElementById('parcelDetailsModal');

            if (event.target == receiveModal) {
                closeReceiveModal();
            }
            if (event.target == editReceiveModal) {
                closeEditReceiveModal();
            }
            if (event.target == pickupModal) {
                closePickupModal();
            }
            if (event.target == deleteReceiveModal) {
                closeDeleteReceiveModal();
            }
            if (event.target == detailsModal) {
                closeDetailsModal();
            }
            if (event.target == parcelDetailsModal) {
                closeParcelDetailsModal();
            }
        }

        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeReceiveModal();
                closeEditReceiveModal();
                closePickupModal();
                closeDeleteReceiveModal();
                closeDetailsModal();
                closeParcelDetailsModal();
            }
        });
    </script>
</body>

</html>
