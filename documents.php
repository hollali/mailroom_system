<?php
// available_documents.php
require_once './config/db.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session for toast messages
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

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

$documents_has_created_at = tableHasColumn($conn, 'documents', 'created_at');
$documents_has_serial_number = tableHasColumn($conn, 'documents', 'serial_number');
$document_received_expr = $documents_has_created_at
    ? "COALESCE(d.created_at, d.date_received) as received_timestamp"
    : "d.date_received as received_timestamp";

function getDocumentSerialDisplay(array $document, $hasSerialNumberColumn)
{
    if ($hasSerialNumberColumn && !empty($document['serial_number'])) {
        return $document['serial_number'];
    }

    return 'DOC-' . str_pad((string)($document['id'] ?? 0), 6, '0', STR_PAD_LEFT);
}

// Handle add document via AJAX
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] == 'add_document') {
    header('Content-Type: application/json');

    $document_name = trim($_POST['document_name']);
    $type_id = (int)$_POST['type_id'];
    $origin = trim($_POST['origin']);
    $copies_received = (int)$_POST['copies_received'];
    $date_received = $_POST['date_received'];
    $normalized_received_timestamp = normalizeDateTimeInput($date_received);
    $date_received_only = $normalized_received_timestamp ? date('Y-m-d', strtotime($normalized_received_timestamp)) : null;

    // Validate inputs
    if (empty($document_name)) {
        echo json_encode(['success' => false, 'message' => 'Document name is required']);
        exit();
    }

    if ($type_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Please select a document type']);
        exit();
    }

    if ($copies_received < 1) {
        echo json_encode(['success' => false, 'message' => 'Number of copies must be at least 1']);
        exit();
    }

    if (empty($normalized_received_timestamp)) {
        echo json_encode(['success' => false, 'message' => 'Date received is required']);
        exit();
    }

    $serial_number = null;

    if ($documents_has_serial_number) {
        // Auto-generate serial number only when the database supports it.
        $prefix = 'DOC';
        $year = date('Y');
        $random = str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
        $serial_number = $prefix . $year . $random;

        $check_stmt = $conn->prepare("SELECT id FROM documents WHERE serial_number = ?");
        $check_stmt->bind_param("s", $serial_number);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            $random = str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
            $serial_number = $prefix . $year . $random;
        }
        $check_stmt->close();
    }

    // Insert new document using the columns that actually exist in the table.
    if ($documents_has_serial_number && $documents_has_created_at) {
        $insert_stmt = $conn->prepare("INSERT INTO documents 
            (serial_number, document_name, type_id, origin, copies_received, date_received, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insert_stmt->bind_param("ssisiss", $serial_number, $document_name, $type_id, $origin, $copies_received, $date_received_only, $normalized_received_timestamp);
    } elseif ($documents_has_serial_number) {
        $insert_stmt = $conn->prepare("INSERT INTO documents 
            (serial_number, document_name, type_id, origin, copies_received, date_received) 
            VALUES (?, ?, ?, ?, ?, ?)");
        $insert_stmt->bind_param("ssisis", $serial_number, $document_name, $type_id, $origin, $copies_received, $date_received_only);
    } elseif ($documents_has_created_at) {
        $insert_stmt = $conn->prepare("INSERT INTO documents 
            (document_name, type_id, origin, copies_received, date_received, created_at) 
            VALUES (?, ?, ?, ?, ?, ?)");
        $insert_stmt->bind_param("sisiss", $document_name, $type_id, $origin, $copies_received, $date_received_only, $normalized_received_timestamp);
    } else {
        $insert_stmt = $conn->prepare("INSERT INTO documents 
            (document_name, type_id, origin, copies_received, date_received) 
            VALUES (?, ?, ?, ?, ?)");
        $insert_stmt->bind_param("sisis", $document_name, $type_id, $origin, $copies_received, $date_received_only);
    }

    if ($insert_stmt->execute()) {
        $new_id = $conn->insert_id;
        $success_message = 'Document added successfully';
        if ($serial_number !== null) {
            $success_message .= ' with serial number: ' . $serial_number;
        }

        echo json_encode([
            'success' => true,
            'message' => $success_message,
            'document_id' => $new_id,
            'serial_number' => $serial_number
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $insert_stmt->error]);
    }
    $insert_stmt->close();
    exit();
}

// Handle get document info for Edit Modal
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] == 'get_document') {
    header('Content-Type: application/json');
    $id = (int)$_POST['id'];

    $stmt = $conn->prepare("SELECT d.*, dt.type_name FROM documents d LEFT JOIN document_types dt ON d.type_id = dt.id WHERE d.id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($doc = $result->fetch_assoc()) {
        echo json_encode(['success' => true, 'document' => $doc]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Document not found']);
    }
    $stmt->close();
    exit();
}

// Handle edit document via AJAX
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] == 'edit_document') {
    header('Content-Type: application/json');

    $id = (int)$_POST['id'];
    $document_name = trim($_POST['document_name']);
    $type_id = (int)$_POST['type_id'];
    $origin = trim($_POST['origin']);
    $copies_received = (int)$_POST['copies_received'];
    $date_received = $_POST['date_received'];
    $normalized_received_timestamp = normalizeDateTimeInput($date_received);
    $date_received_only = $normalized_received_timestamp ? date('Y-m-d', strtotime($normalized_received_timestamp)) : null;

    if ($id <= 0 || empty($document_name) || $type_id <= 0 || $copies_received < 1 || empty($normalized_received_timestamp)) {
        echo json_encode(['success' => false, 'message' => 'Invalid input data']);
        exit();
    }

    // Check if new total copies is less than distributed copies
    $check_dist = $conn->prepare("SELECT COALESCE(SUM(number_distributed), 0) as distributed FROM document_distribution WHERE document_id = ?");
    $check_dist->bind_param("i", $id);
    $check_dist->execute();
    $distributed = $check_dist->get_result()->fetch_assoc()['distributed'];
    $check_dist->close();

    if ($copies_received < $distributed) {
        echo json_encode(['success' => false, 'message' => "Cannot reduce total copies to $copies_received. Total distributed is $distributed."]);
        exit();
    }

    if ($documents_has_created_at) {
        $stmt = $conn->prepare("UPDATE documents SET document_name = ?, type_id = ?, origin = ?, copies_received = ?, date_received = ?, created_at = ? WHERE id = ?");
        $stmt->bind_param("sisissi", $document_name, $type_id, $origin, $copies_received, $date_received_only, $normalized_received_timestamp, $id);
    } else {
        $stmt = $conn->prepare("UPDATE documents SET document_name = ?, type_id = ?, origin = ?, copies_received = ?, date_received = ? WHERE id = ?");
        $stmt->bind_param("sisis i", $document_name, $type_id, $origin, $copies_received, $date_received_only, $id);
    }

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Document updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $stmt->error]);
    }
    $stmt->close();
    exit();
}

// Handle delete document via AJAX
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] == 'delete_document') {
    header('Content-Type: application/json');
    $id = (int)$_POST['id'];

    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid ID']);
        exit();
    }

    $stmt = $conn->prepare("DELETE FROM documents WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Document deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $stmt->error]);
    }
    $stmt->close();
    exit();
}

// Get all documents with their basic info (no distribution or availability tracking)
$sql = "SELECT 
            d.*, 
            dt.type_name as document_type,
            $document_received_expr
        FROM documents d
        LEFT JOIN document_types dt ON d.type_id = dt.id
        ORDER BY d.date_received DESC";

$documents_result = $conn->query($sql);

if (!$documents_result) {
    $_SESSION['toast'] = ['type' => 'error', 'message' => "Database error: " . $conn->error];
}

// Calculate basic statistics
$stats = [
    'total_documents' => 0,
    'total_copies' => 0
];

$stats_sql = "SELECT 
                COUNT(DISTINCT d.id) as total_documents,
                SUM(d.copies_received) as total_copies
              FROM documents d";

$stats_result = $conn->query($stats_sql);
if ($stats_result) {
    $stats = $stats_result->fetch_assoc();
}

// Get document types for filter and add form
$types_result = $conn->query("SELECT id, type_name FROM document_types ORDER BY type_name");
$document_types = [];
if ($types_result) {
    while ($row = $types_result->fetch_assoc()) {
        $document_types[] = $row;
    }
}

// Get toast message from session
$toast = null;
if (isset($_SESSION['toast'])) {
    $toast = $_SESSION['toast'];
    unset($_SESSION['toast']);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document List - Mailroom Management System</title>
    <link rel="icon" type="image/png" href="./images/logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #f5f5f4;
        }

        .stat-card {
            transition: all 0.2s ease;
            border: 1px solid #e5e5e5;
            background-color: white;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            border-color: #9e9e9e;
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
            font-size: 0.75rem;
            background-color: #fafafa;
            cursor: pointer;
            user-select: none;
        }

        th:hover {
            background-color: #f0f0f0;
        }

        td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #e5e5e5;
            font-size: 0.875rem;
            color: #1e1e1e;
            vertical-align: middle;
        }

        tr:hover {
            background-color: #fafafa;
        }

        .badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            font-size: 0.7rem;
            font-weight: 500;
            border-radius: 3px;
        }

        .badge-info {
            background-color: #e3f2fd;
            color: #0b5e8a;
        }

        .filter-btn {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            border: 1px solid #e5e5e5;
            border-radius: 0.375rem;
            background-color: white;
            color: #1e1e1e;
            cursor: pointer;
            transition: all 0.2s;
        }

        .filter-btn:hover {
            background-color: #f5f5f4;
        }

        .filter-btn.active {
            background-color: #1e1e1e;
            color: white;
            border-color: #1e1e1e;
        }

        .modal {
            transition: opacity 0.3s ease;
        }

        .toastify {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            padding: 12px 20px;
            color: white;
            display: inline-block;
            box-shadow: 0 3px 6px -1px rgba(0, 0, 0, 0.12), 0 10px 36px -4px rgba(77, 96, 232, 0.3);
            border-radius: 4px;
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }

        .serial-column {
            font-family: monospace;
            font-size: 0.8rem;
        }

        .action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 6px;
            border: 1px solid #e5e5e5;
            background-color: white;
            color: #4a4a4a;
            transition: all 0.2s;
            cursor: pointer;
        }

        .action-btn:hover {
            background-color: #f5f5f4;
            color: #1e1e1e;
            border-color: #9e9e9e;
        }

        .action-btn.delete-btn:hover {
            background-color: #fee2e2;
            color: #dc2626;
            border-color: #fecaca;
        }

        /* Pagination styles */
        .pagination-shell {
            padding: 1rem 1.25rem;
            border: 1px solid #e7e5e4;
            border-radius: 1rem;
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

        .pagination-item {
            min-width: 2.5rem;
            height: 2.5rem;
            padding: 0 0.85rem;
            border: 1px solid #e7e5e4;
            background-color: white;
            font-size: 0.875rem;
            font-weight: 500;
            color: #292524;
            cursor: pointer;
            transition: all 0.2s ease;
            border-radius: 0.8rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 1px 2px rgba(28, 25, 23, 0.04);
        }

        .pagination-item:hover:not(.disabled):not(.active) {
            background-color: #f5f5f4;
            border-color: #d6d3d1;
            transform: translateY(-1px);
        }

        .pagination-item.active {
            background-color: #1c1917;
            color: white;
            border-color: #1c1917;
            box-shadow: 0 10px 20px rgba(28, 25, 23, 0.14);
        }

        .pagination-item.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .pagination-item.compact {
            min-width: auto;
            padding: 0 0.9rem;
        }

        .pagination-ellipsis {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 2.5rem;
            height: 2.5rem;
            color: #a8a29e;
            font-size: 0.95rem;
        }

        /* Floating Action Button */
        .fab {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 60px;
            height: 60px;
            background-color: #1e1e1e;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            cursor: pointer;
            transition: all 0.3s ease;
            z-index: 100;
            border: none;
        }

        .fab:hover {
            background-color: #2d2d2d;
            transform: scale(1.1);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.3);
        }

        .fab-tooltip {
            position: absolute;
            right: 70px;
            background-color: #1e1e1e;
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 14px;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
        }

        .fab:hover .fab-tooltip {
            opacity: 1;
        }
    </style>
</head>

<body class="bg-[#f5f5f4]">
    <div class="flex">
        <!-- Sidebar -->
        <?php include './sidebar.php'; ?>

        <!-- Main Content -->
        <main class="flex-1 ml-60 min-h-screen">
            <!-- Header -->
            <div class="px-8 py-6 border-b border-[#e5e5e5] bg-white">
                <div class="flex justify-between items-center">
                    <div>
                        <h1 class="text-2xl font-medium text-[#1e1e1e]">Document List</h1>
                        <p class="text-sm text-[#6e6e6e] mt-1">View and manage documents in the system</p>
                    </div>
                    <button onclick="openAddDocumentModal()" class="px-4 py-2 bg-[#1e1e1e] text-white rounded-md hover:bg-[#2d2d2d] text-sm flex items-center gap-2 transition-all">
                        <i class="fa-solid fa-plus"></i>
                        Add Document
                    </button>
                </div>
            </div>

            <div class="p-8">
                <!-- Filters -->
                <div class="bg-white border border-[#e5e5e5] rounded-md p-4 mb-6">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="text-sm font-medium text-[#1e1e1e]">Filter:</span>

                        <select id="typeFilter" class="px-3 py-1.5 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e] bg-white">
                            <option value="">All Document Types</option>
                            <?php foreach ($document_types as $type): ?>
                                <option value="<?php echo htmlspecialchars($type['type_name']); ?>">
                                    <?php echo htmlspecialchars($type['type_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <div class="flex-1 relative">
                            <input type="text" id="searchInput" placeholder="Search by document name, serial number, or type..."
                                class="w-full px-3 py-1.5 pl-9 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]"
                                autocomplete="off">
                            <i class="fa-solid fa-magnifying-glass absolute left-3 top-2.5 text-sm text-[#9e9e9e]"></i>
                        </div>

                        <button onclick="applyFilters()" class="px-4 py-1.5 text-sm bg-[#1e1e1e] text-white rounded-md hover:bg-[#2d2d2d] whitespace-nowrap">
                            Search
                        </button>

                        <button onclick="applyFilters()" class="px-4 py-1.5 text-sm bg-[#1e1e1e] text-white rounded-md hover:bg-[#2d2d2d]">
                            Apply Filters
                        </button>

                        <button onclick="resetFilters()" class="px-4 py-1.5 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                            Reset
                        </button>
                    </div>
                </div>

                <!-- Documents Table -->
                <div class="bg-white border border-[#e5e5e5] rounded-md overflow-hidden">
                    <div class="overflow-x-auto">
                        <table id="documentsTable">
                            <thead>
                                <tr>
                                    <th onclick="sortTable(0)">Serial # <i class="fa-solid fa-sort ml-1 text-[#9e9e9e]"></i></th>
                                    <th onclick="sortTable(1)">Document Name <i class="fa-solid fa-sort ml-1 text-[#9e9e9e]"></i></th>
                                    <th onclick="sortTable(2)">Type <i class="fa-solid fa-sort ml-1 text-[#9e9e9e]"></i></th>
                                    <th onclick="sortTable(3)">Origin <i class="fa-solid fa-sort ml-1 text-[#9e9e9e]"></i></th>
                                    <th onclick="sortTable(4)">Received At <i class="fa-solid fa-sort ml-1 text-[#9e9e9e]"></i></th>
                                    <th onclick="sortTable(5)">Total Copies <i class="fa-solid fa-sort ml-1 text-[#9e9e9e]"></i></th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="tableBody">
                                <?php if ($documents_result && $documents_result->num_rows > 0): ?>
                                    <?php
                                    while ($doc = $documents_result->fetch_assoc()):
                                        $total = $doc['copies_received'];
                                        $serialDisplay = getDocumentSerialDisplay($doc, $documents_has_serial_number);
                                    ?>
                                        <tr class="document-row hover:bg-[#fafafa]"
                                            data-id="<?php echo $doc['id']; ?>"
                                            data-type="<?php echo strtolower(htmlspecialchars($doc['document_type'] ?? 'uncategorized')); ?>"
                                            data-name="<?php echo strtolower(htmlspecialchars($doc['document_name'])); ?>"
                                            data-serial="<?php echo strtolower(htmlspecialchars($serialDisplay)); ?>"
                                            data-origin="<?php echo strtolower(htmlspecialchars($doc['origin'] ?? '')); ?>"
                                            data-search="<?php echo strtolower(htmlspecialchars(trim(($doc['document_name'] ?? '') . ' ' . $serialDisplay . ' ' . ($doc['document_type'] ?? '') . ' ' . ($doc['origin'] ?? '') . ' ' . ($doc['received_timestamp'] ?? '') . ' ' . $total))); ?>">

                                            <td class="serial-column"><?php echo htmlspecialchars($serialDisplay); ?> </td>
                                            <td class="font-medium">
                                                <a href="list.php?search=<?php echo urlencode($doc['document_name']); ?>" class="hover:underline">
                                                    <?php echo htmlspecialchars($doc['document_name']); ?>
                                                </a>
                                            </td>
                                            <td>
                                                <span class="badge badge-info">
                                                    <?php echo htmlspecialchars($doc['document_type'] ?? 'Uncategorized'); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($doc['origin'] ?? 'N/A'); ?> </td>
                                            <td class="text-sm text-[#1e1e1e] whitespace-nowrap">
                                                <?php echo formatTimestampDisplay($doc['received_timestamp'] ?? null); ?>
                                            </td>
                                            <td class="font-mono"><?php echo $total; ?> </td>
                                            <td class="whitespace-nowrap">
                                                <div class="flex gap-2">
                                                    <button onclick="openViewModal(<?php echo $doc['id']; ?>)" class="action-btn" title="View Details">
                                                        <i class="fa-solid fa-eye"></i>
                                                    </button>
                                                    <button onclick="openEditModal(<?php echo $doc['id']; ?>)" class="action-btn" title="Edit Document">
                                                        <i class="fa-solid fa-pen-to-square"></i>
                                                    </button>
                                                    <button onclick="openDeleteModal(<?php echo $doc['id']; ?>, '<?php echo htmlspecialchars(addslashes($doc['document_name'])); ?>')" class="action-btn delete-btn" title="Delete Document">
                                                        <i class="fa-solid fa-trash-can"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-8 text-sm text-[#6e6e6e]">
                                            <i class="fa-regular fa-folder-open text-3xl mb-2 block"></i>
                                            No documents found.
                                            <button onclick="openAddDocumentModal()" class="text-[#1e1e1e] underline">Add your first document</button>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Table Footer with Record Count -->
                    <div class="px-5 py-3 border-t border-[#e5e5e5] bg-[#fafafa] text-xs text-[#6e6e6e] flex justify-between items-center">
                        <span>Showing <span id="visibleCount"><?php echo $documents_result ? $documents_result->num_rows : 0; ?></span> documents</span>
                        <span>Total Copies: <?php echo number_format($stats['total_copies'] ?? 0); ?></span>
                    </div>
                </div>

                <!-- No Results Message (hidden by default) -->
                <div id="noResultsMessage" class="hidden bg-white border border-[#e5e5e5] rounded-md p-8 text-center mt-4">
                    <i class="fa-regular fa-circle-xmark text-4xl text-[#9e9e9e] mb-3"></i>
                    <p class="text-sm text-[#6e6e6e]">No documents match your filters.</p>
                    <button onclick="resetFilters()" class="mt-2 text-sm text-[#1e1e1e] underline">Clear filters</button>
                </div>

                <div id="documentsPagination" class="pagination-shell mt-4 <?php echo (!$documents_result || $documents_result->num_rows === 0) ? 'hidden' : ''; ?>">
                    <div class="pagination-meta">
                        <div id="documentsPaginationTitle" class="pagination-title"></div>
                        <div id="documentsPaginationInfo" class="pagination-subtitle"></div>
                    </div>
                    <div class="pagination-controls">
                        <div id="documentsPaginationPage" class="pagination-page-indicator"></div>
                        <div class="pagination" id="documentsPaginationControls"></div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Document Modal -->
    <div id="addDocumentModal" class="fixed inset-0 bg-[#000000] bg-opacity-20 hidden items-center justify-center z-50 modal">
        <div class="bg-white border border-[#e5e5e5] rounded-md w-full max-w-lg p-5">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-base font-medium text-[#1e1e1e]">Add New Document</h3>
                <button onclick="closeAddDocumentModal()" class="text-[#9e9e9e] hover:text-[#1e1e1e]">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>

            <form id="addDocumentForm" onsubmit="return false;">
                <div class="grid grid-cols-1 gap-4">
                    <div>
                        <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Document Name <span class="text-red-400">*</span></label>
                        <input type="text" id="add_document_name" required
                            class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]"
                            autocomplete="off" placeholder="Enter document name">
                    </div>

                    <div>
                        <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Document Type <span class="text-red-400">*</span></label>
                        <select id="add_type_id" required class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e] bg-white">
                            <option value="">Select Type</option>
                            <?php foreach ($document_types as $type): ?>
                                <option value="<?php echo $type['id']; ?>">
                                    <?php echo htmlspecialchars($type['type_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Origin / Source</label>
                        <input type="text" id="add_origin"
                            class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]"
                            autocomplete="off" placeholder="e.g. Office of the President">
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Number of Copies <span class="text-red-400">*</span></label>
                            <input type="number" id="add_copies_received" required min="1" value="1"
                                class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]" autocomplete="off">
                        </div>
                        <div>
                            <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Received Timestamp <span class="text-red-400">*</span></label>
                            <input type="datetime-local" id="add_date_received" required
                                class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]"
                                autocomplete="off">
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-2 mt-6">
                    <button type="button" onclick="closeAddDocumentModal()"
                        class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                        Cancel
                    </button>
                    <button type="button" onclick="submitAddDocument()" id="addDocumentSubmitBtn"
                        class="px-4 py-2 text-sm bg-[#1e1e1e] text-white rounded-md hover:bg-[#2d2d2d]">
                        <i class="fa-solid fa-plus mr-1"></i>
                        Add Document
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Document Modal -->
    <div id="viewDocumentModal" class="fixed inset-0 bg-[#000000] bg-opacity-20 hidden items-center justify-center z-50 modal">
        <div class="bg-white border border-[#e5e5e5] rounded-md w-full max-w-lg p-5">
            <div class="flex justify-between items-center mb-6">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-[#f5f5f4] flex items-center justify-center text-[#1e1e1e]">
                        <i class="fa-solid fa-file-lines text-xl"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-medium text-[#1e1e1e]">Document Details</h3>
                        <p class="text-xs text-[#6e6e6e]" id="view_serial_display"></p>
                    </div>
                </div>
                <button onclick="closeViewModal()" class="text-[#9e9e9e] hover:text-[#1e1e1e]">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>

            <div class="space-y-4">
                <div class="grid grid-cols-2 gap-4 pb-4 border-b border-[#f5f5f4]">
                    <div>
                        <p class="text-[10px] text-[#9e9e9e] uppercase tracking-wider font-semibold mb-1">Document Name</p>
                        <p class="text-sm font-medium text-[#1e1e1e]" id="view_document_name"></p>
                    </div>
                    <div>
                        <p class="text-[10px] text-[#9e9e9e] uppercase tracking-wider font-semibold mb-1">Document Type</p>
                        <span id="view_type_badge" class="badge badge-info"></span>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4 pb-4 border-b border-[#f5f5f4]">
                    <div>
                        <p class="text-[10px] text-[#9e9e9e] uppercase tracking-wider font-semibold mb-1">Origin / Source</p>
                        <p class="text-sm text-[#1e1e1e]" id="view_origin"></p>
                    </div>
                    <div>
                        <p class="text-[10px] text-[#9e9e9e] uppercase tracking-wider font-semibold mb-1">Total Copies Received</p>
                        <p class="text-sm font-mono text-[#1e1e1e]" id="view_copies"></p>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <p class="text-[10px] text-[#9e9e9e] uppercase tracking-wider font-semibold mb-1">Received Timestamp</p>
                        <p class="text-sm text-[#1e1e1e]" id="view_date"></p>
                    </div>
                    <div>
                        <p class="text-[10px] text-[#9e9e9e] uppercase tracking-wider font-semibold mb-1">System ID</p>
                        <p class="text-sm font-mono text-[#6e6e6e]" id="view_id"></p>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-2 mt-8 pt-4 border-t border-[#f5f5f4]">
                <button type="button" onclick="closeViewModal()"
                    class="px-5 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e] font-medium transition-colors">
                    Close
                </button>
                <button type="button" id="view_edit_btn"
                    class="px-5 py-2 text-sm bg-[#1e1e1e] text-white rounded-md hover:bg-[#2d2d2d] font-medium transition-colors">
                    <i class="fa-solid fa-pen-to-square mr-1.5"></i>
                    Edit Document
                </button>
            </div>
        </div>
    </div>

    <!-- Edit Document Modal -->
    <div id="editDocumentModal" class="fixed inset-0 bg-[#000000] bg-opacity-20 hidden items-center justify-center z-50 modal">
        <div class="bg-white border border-[#e5e5e5] rounded-md w-full max-w-lg p-5">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-base font-medium text-[#1e1e1e]">Edit Document</h3>
                <button onclick="closeEditModal()" class="text-[#9e9e9e] hover:text-[#1e1e1e]">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>

            <form id="editDocumentForm" onsubmit="return false;">
                <input type="hidden" id="edit_id">
                <div class="grid grid-cols-1 gap-4">
                    <div>
                        <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Document Name <span class="text-red-400">*</span></label>
                        <input type="text" id="edit_document_name" required
                            class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]"
                            autocomplete="off">
                    </div>

                    <div>
                        <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Document Type <span class="text-red-400">*</span></label>
                        <select id="edit_type_id" required class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e] bg-white">
                            <option value="">Select Type</option>
                            <?php foreach ($document_types as $type): ?>
                                <option value="<?php echo $type['id']; ?>">
                                    <?php echo htmlspecialchars($type['type_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Origin</label>
                        <input type="text" id="edit_origin"
                            class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]"
                            autocomplete="off">
                    </div>

                    <div>
                        <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Number of Copies <span class="text-red-400">*</span></label>
                        <input type="number" id="edit_copies_received" required min="1"
                            class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]" autocomplete="off">
                    </div>

                    <div>
                        <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Received Timestamp <span class="text-red-400">*</span></label>
                        <input type="datetime-local" id="edit_date_received" required
                            class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]"
                            autocomplete="off">
                    </div>
                </div>

                <div class="flex justify-end gap-2 mt-5">
                    <button type="button" onclick="closeEditModal()"
                        class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                        Cancel
                    </button>
                    <button type="button" onclick="submitEditDocument()" id="editDocumentSubmitBtn"
                        class="px-4 py-2 text-sm bg-[#1e1e1e] text-white rounded-md hover:bg-[#2d2d2d]">
                        <i class="fa-regular fa-floppy-disk mr-1"></i>
                        Update Document
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteDocumentModal" class="fixed inset-0 bg-[#000000] bg-opacity-20 hidden items-center justify-center z-50 modal">
        <div class="bg-white border border-[#e5e5e5] rounded-md w-full max-w-md p-5">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-base font-medium text-[#c73b2b]">Confirm Deletion</h3>
                <button onclick="closeDeleteModal()" class="text-[#9e9e9e] hover:text-[#1e1e1e]">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>

            <div class="mb-5">
                <p class="text-sm text-[#4a4a4a]">Are you sure you want to delete <span id="deleteDocNameDisplay" class="font-bold"></span>?</p>
                <p class="text-xs text-[#c73b2b] mt-2 font-medium">Warning: This action will also delete all distribution records associated with this document and cannot be undone.</p>
            </div>

            <input type="hidden" id="delete_id">
            <div class="flex justify-end gap-2 mt-5">
                <button type="button" onclick="closeDeleteModal()"
                    class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                    Cancel
                </button>
                <button type="button" onclick="confirmDeleteDocument()" id="deleteDocumentBtn"
                    class="px-4 py-2 text-sm bg-[#c73b2b] text-white rounded-md hover:bg-[#b52a1b]">
                    <i class="fa-regular fa-trash-can mr-1"></i>
                    Yes, Delete Document
                </button>
            </div>
        </div>
    </div>

    <!-- Toast Container (for custom toasts) -->
    <div id="toastContainer"></div>

    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
    <script>
        // Store document data
        let documents = [];

        // Toast notification function
        function showToast(message, type = 'success') {
            const backgroundColor = type === 'success' ? '#10b981' :
                type === 'error' ? '#ef4444' :
                type === 'warning' ? '#f59e0b' : '#3b82f6';

            Toastify({
                text: message,
                duration: 3000,
                close: true,
                gravity: "top",
                position: "right",
                backgroundColor: backgroundColor,
                stopOnFocus: true,
                className: "toastify"
            }).showToast();
        }

        // Show toast from PHP session
        <?php if ($toast): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showToast('<?php echo addslashes($toast['message']); ?>', '<?php echo $toast['type']; ?>');
            });
        <?php endif; ?>

        // Add Document Modal Functions
        function openAddDocumentModal() {
            // Reset form
            document.getElementById('add_document_name').value = '';
            document.getElementById('add_type_id').value = '';
            document.getElementById('add_origin').value = '';
            document.getElementById('add_copies_received').value = '1';
            document.getElementById('add_date_received').value = '<?php echo date('Y-m-d\TH:i'); ?>';

            document.getElementById('addDocumentModal').style.display = 'flex';
        }

        function closeAddDocumentModal() {
            document.getElementById('addDocumentModal').style.display = 'none';
        }

        function submitAddDocument() {
            const document_name = document.getElementById('add_document_name').value.trim();
            const type_id = document.getElementById('add_type_id').value;
            const origin = document.getElementById('add_origin').value.trim();
            const copies_received = parseInt(document.getElementById('add_copies_received').value);
            const date_received = document.getElementById('add_date_received').value;

            // Validation
            if (!document_name) {
                showToast('Please enter document name', 'warning');
                return;
            }

            if (!type_id) {
                showToast('Please select a document type', 'warning');
                return;
            }

            if (!copies_received || copies_received < 1) {
                showToast('Number of copies must be at least 1', 'warning');
                return;
            }

            if (!date_received) {
                showToast('Please select date received', 'warning');
                return;
            }

            // Show loading state
            const submitBtn = document.getElementById('addDocumentSubmitBtn');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fa-regular fa-spinner fa-spin mr-1"></i> Saving...';
            submitBtn.disabled = true;

            // Submit via AJAX
            fetch('<?php echo $_SERVER['PHP_SELF']; ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `ajax_action=add_document&document_name=${encodeURIComponent(document_name)}&type_id=${type_id}&origin=${encodeURIComponent(origin)}&copies_received=${copies_received}&date_received=${date_received}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message, 'success');
                        closeAddDocumentModal();

                        // Reload the page after a short delay to show the new document
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    } else {
                        showToast(data.message, 'error');
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }
                })
                .catch(error => {
                    showToast('An error occurred. Please try again.', 'error');
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                });
        }

        // Filter Functions
        const documentsPageSize = 10;
        let documentsCurrentPage = 1;
        let filterDebounceTimer;

        function getVisibleDocumentRows() {
            return Array.from(document.querySelectorAll('.document-row')).filter(row => row.dataset.filtered !== 'false');
        }

        function renderDocumentsPagination() {
            const visibleRows = getVisibleDocumentRows();
            const totalRows = visibleRows.length;
            const totalPages = Math.max(1, Math.ceil(totalRows / documentsPageSize));
            const paginationTitle = document.getElementById('documentsPaginationTitle');
            const paginationInfo = document.getElementById('documentsPaginationInfo');
            const paginationPage = document.getElementById('documentsPaginationPage');
            const paginationControls = document.getElementById('documentsPaginationControls');
            const paginationWrapper = document.getElementById('documentsPagination');

            if (!paginationInfo || !paginationControls || !paginationWrapper) {
                return;
            }

            if (documentsCurrentPage > totalPages) {
                documentsCurrentPage = totalPages;
            }

            const startIndex = (documentsCurrentPage - 1) * documentsPageSize;
            const endIndex = startIndex + documentsPageSize;

            document.querySelectorAll('.document-row').forEach(row => {
                row.style.display = 'none';
            });

            visibleRows.forEach((row, index) => {
                row.style.display = index >= startIndex && index < endIndex ? '' : 'none';
            });

            if (totalRows === 0) {
                if (paginationTitle) {
                    paginationTitle.textContent = '';
                }
                paginationInfo.textContent = 'No matching documents';
                if (paginationPage) {
                    paginationPage.textContent = '';
                }
                paginationControls.innerHTML = '';
                paginationWrapper.classList.add('hidden');
                return;
            }

            const from = startIndex + 1;
            const to = Math.min(endIndex, totalRows);

            const visibleCount = Math.max(0, to - startIndex);
            if (paginationTitle) {
                paginationTitle.textContent = `Showing ${visibleCount} ${visibleCount === 1 ? 'document' : 'documents'} on this page`;
            }
            paginationInfo.textContent = `Records ${from}-${to} of ${totalRows} total`;
            if (paginationPage) {
                paginationPage.textContent = `Page ${documentsCurrentPage} of ${totalPages}`;
            }
            paginationWrapper.classList.toggle('hidden', totalRows <= documentsPageSize);
            const startPage = Math.max(1, documentsCurrentPage - 2);
            const endPage = Math.min(totalPages, documentsCurrentPage + 2);
            let controlsHtml = `
                <button class="pagination-item compact ${documentsCurrentPage === 1 ? 'disabled' : ''}" ${documentsCurrentPage === 1 ? 'disabled' : ''} onclick="changeDocumentsPage(1)" aria-label="First page">
                    <i class="fa-solid fa-chevrons-left"></i>
                </button>
                <button class="pagination-item compact ${documentsCurrentPage === 1 ? 'disabled' : ''}" ${documentsCurrentPage === 1 ? 'disabled' : ''} onclick="changeDocumentsPage(${documentsCurrentPage - 1})" aria-label="Previous page">
                    <i class="fa-solid fa-chevron-left"></i>
                </button>
            `;

            if (startPage > 1) {
                controlsHtml += `<button class="pagination-item" onclick="changeDocumentsPage(1)">1</button>`;
                if (startPage > 2) {
                    controlsHtml += `<span class="pagination-ellipsis">...</span>`;
                }
            }

            for (let i = startPage; i <= endPage; i++) {
                controlsHtml += `<button class="pagination-item ${i === documentsCurrentPage ? 'active' : ''}" onclick="changeDocumentsPage(${i})">${i}</button>`;
            }

            if (endPage < totalPages) {
                if (endPage < totalPages - 1) {
                    controlsHtml += `<span class="pagination-ellipsis">...</span>`;
                }
                controlsHtml += `<button class="pagination-item" onclick="changeDocumentsPage(${totalPages})">${totalPages}</button>`;
            }

            controlsHtml += `
                <button class="pagination-item compact ${documentsCurrentPage === totalPages ? 'disabled' : ''}" ${documentsCurrentPage === totalPages ? 'disabled' : ''} onclick="changeDocumentsPage(${documentsCurrentPage + 1})" aria-label="Next page">
                    <i class="fa-solid fa-chevron-right"></i>
                </button>
                <button class="pagination-item compact ${documentsCurrentPage === totalPages ? 'disabled' : ''}" ${documentsCurrentPage === totalPages ? 'disabled' : ''} onclick="changeDocumentsPage(${totalPages})" aria-label="Last page">
                    <i class="fa-solid fa-chevrons-right"></i>
                </button>
            `;
            paginationControls.innerHTML = controlsHtml;
        }

        function changeDocumentsPage(page) {
            documentsCurrentPage = Math.max(1, page);
            renderDocumentsPagination();
        }

        function getSearchTokens(value) {
            return value.toLowerCase().split(/\s+/).filter(Boolean);
        }

        function applyFilters(showFeedback = true) {
            const typeFilter = document.getElementById('typeFilter').value.toLowerCase();
            const searchTokens = getSearchTokens(document.getElementById('searchInput').value);

            const rows = document.querySelectorAll('.document-row');
            let visibleCount = 0;

            rows.forEach(row => {
                const docType = row.getAttribute('data-type');
                const searchText = row.getAttribute('data-search') || '';

                // Type filter
                let typeMatch = !typeFilter || docType.includes(typeFilter);

                // Search filter
                let searchMatch = searchTokens.length === 0 ||
                    searchTokens.every(token => searchText.includes(token));

                if (typeMatch && searchMatch) {
                    row.dataset.filtered = 'true';
                    visibleCount++;
                } else {
                    row.dataset.filtered = 'false';
                    row.style.display = 'none';
                }
            });

            // Show/hide no results message
            const table = document.getElementById('documentsTable');
            const noResults = document.getElementById('noResultsMessage');

            if (visibleCount === 0) {
                table.classList.add('hidden');
                noResults.classList.remove('hidden');
            } else {
                table.classList.remove('hidden');
                noResults.classList.add('hidden');
            }

            document.getElementById('visibleCount').textContent = visibleCount;
            documentsCurrentPage = 1;
            renderDocumentsPagination();
            if (showFeedback) {
                showToast(`Showing ${visibleCount} document(s)`, 'info', 2000);
            }
        }

        function resetFilters() {
            document.getElementById('typeFilter').value = '';
            document.getElementById('searchInput').value = '';

            const rows = document.querySelectorAll('.document-row');
            rows.forEach(row => {
                row.dataset.filtered = 'true';
                row.style.display = '';
            });

            document.getElementById('documentsTable').classList.remove('hidden');
            document.getElementById('noResultsMessage').classList.add('hidden');
            document.getElementById('visibleCount').textContent = rows.length;
            documentsCurrentPage = 1;
            renderDocumentsPagination();

            showToast('Filters cleared', 'info', 2000);
        }

        // Table sorting
        let sortDirection = 'asc';
        let lastSortedColumn = -1;

        function sortTable(columnIndex) {
            const tbody = document.getElementById('tableBody');
            const rows = Array.from(tbody.querySelectorAll('tr'));

            // Toggle sort direction if same column
            if (lastSortedColumn === columnIndex) {
                sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                sortDirection = 'asc';
                lastSortedColumn = columnIndex;
            }

            // Sort rows
            rows.sort((a, b) => {
                const aCol = a.querySelectorAll('td')[columnIndex]?.textContent.trim() || '';
                const bCol = b.querySelectorAll('td')[columnIndex]?.textContent.trim() || '';

                // Check if numeric (for Total Copies column)
                if (columnIndex === 5) {
                    const aNum = parseInt(aCol) || 0;
                    const bNum = parseInt(bCol) || 0;
                    return sortDirection === 'asc' ? aNum - bNum : bNum - aNum;
                }

                // String comparison
                const comparison = aCol.localeCompare(bCol);
                return sortDirection === 'asc' ? comparison : -comparison;
            });

            // Reorder table
            tbody.innerHTML = '';
            rows.forEach(row => tbody.appendChild(row));
            renderDocumentsPagination();

            showToast(`Sorted by column`, 'info', 1500);
        }

        // Search on enter key
        document.getElementById('searchInput')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                applyFilters();
            }
        });

        document.getElementById('searchInput')?.addEventListener('input', function() {
            clearTimeout(filterDebounceTimer);
            filterDebounceTimer = setTimeout(() => applyFilters(false), 180);
        });

        document.getElementById('typeFilter')?.addEventListener('change', function() {
            applyFilters(false);
        });

        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.document-row').forEach(row => {
                row.dataset.filtered = 'true';
            });
            renderDocumentsPagination();
        });

        // View Modal Functions
        function openViewModal(id) {
            fetch('documents.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `ajax_action=get_document&id=${id}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const doc = data.document;
                    document.getElementById('view_id').textContent = '#' + doc.id;
                    document.getElementById('view_document_name').textContent = doc.document_name;
                    document.getElementById('view_type_badge').textContent = (doc.type_name || 'Uncategorized');
                    document.getElementById('view_origin').textContent = doc.origin || 'N/A';
                    document.getElementById('view_copies').textContent = doc.copies_received;
                    
                    // Format timestamp
                    if (doc.created_at || doc.date_received) {
                        const dateStr = doc.created_at || doc.date_received;
                        const dt = new Date(dateStr);
                        document.getElementById('view_date').textContent = dt.toLocaleString('en-US', { 
                            month: 'short', day: 'numeric', year: 'numeric', 
                            hour: 'numeric', minute: '2-digit', hour12: true 
                        });
                    } else {
                        document.getElementById('view_date').textContent = 'N/A';
                    }

                    // Serial Number (reusing logic from table generator if possible, or simple fallback)
                    const serial = doc.serial_number || ('DOC-' + doc.id.toString().padStart(6, '0'));
                    document.getElementById('view_serial_display').textContent = serial;

                    // Setup edit button in view modal
                    document.getElementById('view_edit_btn').onclick = function() {
                        closeViewModal();
                        openEditModal(doc.id);
                    };

                    document.getElementById('viewDocumentModal').style.display = 'flex';
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(error => showToast('Error fetching document details', 'error'));
        }

        function closeViewModal() {
            document.getElementById('viewDocumentModal').style.display = 'none';
        }

        // Edit Modal Functions
        function openEditModal(id) {
            fetch('documents.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `ajax_action=get_document&id=${id}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const doc = data.document;
                    document.getElementById('edit_id').value = doc.id;
                    document.getElementById('edit_document_name').value = doc.document_name;
                    document.getElementById('edit_type_id').value = doc.type_id;
                    document.getElementById('edit_origin').value = doc.origin || '';
                    document.getElementById('edit_copies_received').value = doc.copies_received;
                    
                    // Format timestamp for datetime-local
                    if (doc.created_at) {
                        const dt = new Date(doc.created_at);
                        const localDt = new Date(dt.getTime() - (dt.getTimezoneOffset() * 60000)).toISOString().slice(0, 16);
                        document.getElementById('edit_date_received').value = localDt;
                    } else if (doc.date_received) {
                        document.getElementById('edit_date_received').value = doc.date_received + 'T00:00';
                    }

                    document.getElementById('editDocumentModal').style.display = 'flex';
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(error => showToast('Error fetching document details', 'error'));
        }

        function closeEditModal() {
            document.getElementById('editDocumentModal').style.display = 'none';
        }

        function submitEditDocument() {
            const id = document.getElementById('edit_id').value;
            const document_name = document.getElementById('edit_document_name').value.trim();
            const type_id = document.getElementById('edit_type_id').value;
            const origin = document.getElementById('edit_origin').value.trim();
            const copies_received = parseInt(document.getElementById('edit_copies_received').value);
            const date_received = document.getElementById('edit_date_received').value;

            if (!id || !document_name || !type_id || !copies_received || !date_received) {
                showToast('Please fill all required fields', 'warning');
                return;
            }

            const submitBtn = document.getElementById('editDocumentSubmitBtn');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fa-regular fa-spinner fa-spin mr-1"></i> Updating...';
            submitBtn.disabled = true;

            fetch('documents.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `ajax_action=edit_document&id=${id}&document_name=${encodeURIComponent(document_name)}&type_id=${type_id}&origin=${encodeURIComponent(origin)}&copies_received=${copies_received}&date_received=${date_received}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    closeEditModal();
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.message, 'error');
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }
            })
            .catch(error => {
                showToast('An error occurred', 'error');
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        }

        // Delete Modal Functions
        function openDeleteModal(id, name) {
            document.getElementById('delete_id').value = id;
            document.getElementById('deleteDocNameDisplay').textContent = name;
            document.getElementById('deleteDocumentModal').style.display = 'flex';
        }

        function closeDeleteModal() {
            document.getElementById('deleteDocumentModal').style.display = 'none';
        }

        function confirmDeleteDocument() {
            const id = document.getElementById('delete_id').value;
            if (!id) return;

            const btn = document.getElementById('deleteDocumentBtn');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fa-regular fa-spinner fa-spin mr-1"></i> Deleting...';
            btn.disabled = true;

            fetch('documents.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `ajax_action=delete_document&id=${id}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    closeDeleteModal();
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.message, 'error');
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }
            })
            .catch(error => {
                showToast('An error occurred', 'error');
                btn.innerHTML = originalText;
                btn.disabled = false;
            });
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const addModal = document.getElementById('addDocumentModal');
            const viewModal = document.getElementById('viewDocumentModal');
            const editModal = document.getElementById('editDocumentModal');
            const deleteModal = document.getElementById('deleteDocumentModal');

            if (event.target == addModal) closeAddDocumentModal();
            if (event.target == viewModal) closeViewModal();
            if (event.target == editModal) closeEditModal();
            if (event.target == deleteModal) closeDeleteModal();
        }

        // ESC key to close modals
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeAddDocumentModal();
                closeViewModal();
                closeEditModal();
                closeDeleteModal();
            }
        });
    </script>
    <!-- Floating Action Button -->
    
</body>

</html>