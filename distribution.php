<?php
require_once './config/db.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session for toast messages
if (session_status() == PHP_SESSION_NONE) {
    session_start();
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

// Handle form submission
if (isset($_POST['submit'])) {
    $document_id = $_POST['document_id'];
    $date_distributed = $_POST['date_distributed'];
    $numbers = $_POST['number_distributed'] ?? [];

    $success_count = 0;
    $error_messages = [];

    // Begin transaction
    $conn->begin_transaction();

    try {
        // First, check if document has enough copies for all distributions
        $total_requested = array_sum($numbers);

        $check_stmt = $conn->prepare("SELECT copies_received, document_name FROM documents WHERE id = ?");
        $check_stmt->bind_param("i", $document_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $document = $check_result->fetch_assoc();
        $check_stmt->close();

        if (!$document) {
            throw new Exception("Document not found");
        }

        if ($document['copies_received'] < $total_requested) {
            throw new Exception("Insufficient copies. Available: " . $document['copies_received'] . ", Requested: " . $total_requested);
        }

        // Process each distribution entry
        for ($i = 0; $i < count($numbers); $i++) {
            $number = (int)$numbers[$i];

            if ($number > 0) {
                // Insert distribution record (without department and recipient name)
                $sql = "INSERT INTO document_distribution 
                        (document_id, number_received, number_distributed, date_distributed)
                        VALUES 
                        (?, ?, ?, ?)";

                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }

                $stmt->bind_param("iiis", $document_id, $number, $number, $date_distributed);

                if (!$stmt->execute()) {
                    throw new Exception("Error for entry " . ($i + 1) . ": " . $stmt->error);
                }
                $stmt->close();

                $success_count++;
            }
        }

        // Update document copies after successful distributions
        if ($success_count > 0) {
            $new_copies = $document['copies_received'] - $total_requested;
            $update_stmt = $conn->prepare("UPDATE documents SET copies_received = ? WHERE id = ?");
            $update_stmt->bind_param("ii", $new_copies, $document_id);

            if (!$update_stmt->execute()) {
                throw new Exception("Failed to update document copies: " . $update_stmt->error);
            }
            $update_stmt->close();
        }

        // Commit transaction
        $conn->commit();

        $_SESSION['toast'] = [
            'type' => 'success',
            'message' => "$success_count distribution record(s) saved successfully. " . $total_requested . " copies of \"" . $document['document_name'] . "\" distributed."
        ];
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => "Error: " . $e->getMessage()
        ];
    }

    if ($success_count == 0 && empty($error_messages)) {
        $_SESSION['toast'] = [
            'type' => 'warning',
            'message' => "No valid records to save"
        ];
    }

    header('Location: distribution.php');
    exit();
}

// Handle Delete Distribution
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];

    // Begin transaction
    $conn->begin_transaction();

    try {
        // Get distribution details before deleting
        $get_stmt = $conn->prepare("SELECT document_id, number_distributed FROM document_distribution WHERE id = ?");
        $get_stmt->bind_param("i", $id);
        $get_stmt->execute();
        $result = $get_stmt->get_result();
        $distribution = $result->fetch_assoc();
        $get_stmt->close();

        if ($distribution) {
            // Delete the distribution record
            $delete_stmt = $conn->prepare("DELETE FROM document_distribution WHERE id = ?");
            $delete_stmt->bind_param("i", $id);

            if (!$delete_stmt->execute()) {
                throw new Exception("Error deleting record: " . $conn->error);
            }
            $delete_stmt->close();

            // Restore copies to document
            $update_stmt = $conn->prepare("UPDATE documents SET copies_received = copies_received + ? WHERE id = ?");
            $update_stmt->bind_param("ii", $distribution['number_distributed'], $distribution['document_id']);

            if (!$update_stmt->execute()) {
                throw new Exception("Error restoring copies: " . $conn->error);
            }
            $update_stmt->close();

            $conn->commit();
            $_SESSION['toast'] = [
                'type' => 'success',
                'message' => "Distribution record deleted and copies restored successfully!"
            ];
        } else {
            throw new Exception("Distribution record not found");
        }
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => "Error: " . $e->getMessage()
        ];
    }

    // Preserve any query parameters
    $query_params = $_GET;
    unset($query_params['delete']);
    $redirect_url = 'distribution.php' . (!empty($query_params) ? '?' . http_build_query($query_params) : '');
    header('Location: ' . $redirect_url);
    exit();
}

// Get all documents with their current available copies
$documents = $conn->query("
    SELECT d.*, dt.type_name as document_type,
           (d.copies_received - COALESCE((
                SELECT SUM(number_distributed) 
                FROM document_distribution 
                WHERE document_id = d.id
           ), 0)) as available_copies
    FROM documents d 
    LEFT JOIN document_types dt ON d.type_id = dt.id 
    ORDER BY 
        CASE 
            WHEN (d.copies_received - COALESCE((
                SELECT SUM(number_distributed) 
                FROM document_distribution 
                WHERE document_id = d.id
            ), 0)) > 0 THEN 0 
            ELSE 1 
        END,
        d.document_name ASC
");

// Get all document types for option grouping
$document_types = $conn->query("
    SELECT * FROM document_types 
    ORDER BY type_name ASC
");

// Get distribution records with document information
$result = $conn->query("
    SELECT dd.*, d.document_name, d.type_id, dt.type_name as document_type,
           d.copies_received as total_copies
    FROM document_distribution dd
    JOIN documents d ON dd.document_id = d.id
    LEFT JOIN document_types dt ON d.type_id = dt.id
    ORDER BY dd.date_distributed DESC, dd.id DESC
");

// Check if query failed
if (!$result) {
    $_SESSION['toast'] = ['type' => 'error', 'message' => "Database error: " . $conn->error];
}

// Get comprehensive statistics
$stats = $conn->query("
    SELECT 
        COUNT(DISTINCT dd.id) as total_distributions,
        COALESCE(SUM(dd.number_distributed), 0) as total_copies_distributed,
        COUNT(DISTINCT d.id) as total_documents,
        COALESCE(SUM(d.copies_received), 0) as total_copies_received,
        COALESCE(SUM(d.copies_received - COALESCE(dd_dist.total_distributed, 0)), 0) as available_copies,
        COUNT(DISTINCT CASE 
            WHEN (d.copies_received - COALESCE(dd_dist.total_distributed, 0)) > 0 
            THEN d.id END) as documents_in_stock,
        COUNT(DISTINCT CASE 
            WHEN (d.copies_received - COALESCE(dd_dist.total_distributed, 0)) <= 0 
            THEN d.id END) as documents_out_of_stock
    FROM documents d
    LEFT JOIN (
        SELECT document_id, SUM(number_distributed) as total_distributed
        FROM document_distribution
        GROUP BY document_id
    ) dd_dist ON d.id = dd_dist.document_id
    LEFT JOIN document_distribution dd ON d.id = dd.document_id
")->fetch_assoc();

$total_distributions = $stats['total_distributions'] ?? 0;
$total_copies_distributed = $stats['total_copies_distributed'] ?? 0;
$available_copies = $stats['available_copies'] ?? 0;

$today = date('Y-m-d');
$today_distributions = 0;
$today_result = $conn->query("SELECT COUNT(*) as count, COALESCE(SUM(number_distributed), 0) as total FROM document_distribution WHERE date_distributed = '$today'");
if ($today_result) {
    $today_data = $today_result->fetch_assoc();
    $today_distributions = $today_data['count'];
    $today_copies = $today_data['total'];
}

// Get document types count
$type_count = 0;
$type_result = $conn->query("SELECT COUNT(*) as count FROM document_types");
if ($type_result) {
    $type_count = $type_result->fetch_assoc()['count'];
}

// Get toast message from session
$toast = null;
if (isset($_SESSION['toast'])) {
    $toast = $_SESSION['toast'];
    unset($_SESSION['toast']);
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>Document Distribution - Mailroom</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/png" href="./images/logo.png">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #f5f5f4;
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

        .badge-info {
            background-color: #e3f2fd;
            color: #0b5e8a;
        }

        .badge-success {
            background-color: #e8f0e8;
            color: #2c5e2c;
        }

        .badge-warning {
            background-color: #fff3e0;
            color: #b45b0b;
        }

        .badge-danger {
            background-color: #fee9e7;
            color: #c73b2b;
        }

        .stock-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 4px;
        }

        .stock-high {
            background-color: #10b981;
        }

        .stock-medium {
            background-color: #f59e0b;
        }

        .stock-low {
            background-color: #ef4444;
        }

        .stock-out {
            background-color: #9e9e9e;
        }

        .option-group {
            font-weight: 600;
            background-color: #f5f5f4;
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
            max-width: 400px;
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
            position: relative;
            overflow: hidden;
        }

        .toast.success {
            border-left-color: #10b981;
        }

        .toast.error {
            border-left-color: #ef4444;
        }

        .toast.warning {
            border-left-color: #f59e0b;
        }

        .toast.info {
            border-left-color: #3b82f6;
        }

        .toast-content {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 1;
        }

        .toast-close {
            cursor: pointer;
            color: #9e9e9e;
            font-size: 18px;
            padding: 0 5px;
        }

        .toast-close:hover {
            color: #1e1e1e;
        }

        .toast-progress {
            position: absolute;
            bottom: 0;
            left: 0;
            height: 3px;
            background-color: rgba(0, 0, 0, 0.1);
            width: 100%;
            animation: progress 5s linear forwards;
        }

        .toast.success .toast-progress {
            background-color: #10b981;
        }

        .toast.error .toast-progress {
            background-color: #ef4444;
        }

        .toast.warning .toast-progress {
            background-color: #f59e0b;
        }

        .toast.info .toast-progress {
            background-color: #3b82f6;
        }

        /* Modal styles */
        .modal {
            transition: opacity 0.3s ease;
        }

        .modal-content {
            max-height: 90vh;
            overflow-y: auto;
        }

        .action-btn {
            color: #9e9e9e;
            transition: color 0.2s;
            margin: 0 0.25rem;
            background: none;
            border: none;
            cursor: pointer;
        }

        .action-btn:hover {
            color: #1e1e1e;
        }

        .delete-btn:hover {
            color: #dc2626;
        }

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
            border-radius: 0.8rem;
            background-color: white;
            color: #292524;
            font-size: 0.875rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 1px 2px rgba(28, 25, 23, 0.04);
            transition: all 0.2s ease;
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

        @keyframes progress {
            from {
                width: 100%;
            }

            to {
                width: 0%;
            }
        }

        .new-distribution-btn {
            background-color: #1e1e1e;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            font-size: 0.875rem;
        }

        .new-distribution-btn:hover {
            background-color: #2d2d2d;
        }

        .warning-message {
            background-color: #fff3e0;
            border-left: 4px solid #f59e0b;
            color: #b45b0b;
        }
    </style>
</head>

<body class="bg-[#f5f5f4]">
    <?php include 'sidebar.php'; ?>

    <!-- Toast Container -->
    <div id="toastContainer" class="toast-container"></div>

    <div class="ml-60 min-h-screen">
        <!-- Header -->
        <div class="px-8 py-6 border-b border-[#e5e5e5] bg-white">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-medium text-[#1e1e1e]">Document Distribution</h1>
                    <p class="text-sm text-[#6e6e6e] mt-1">Track document distribution across the organization</p>
                </div>
                <div class="flex gap-2">
                    <button onclick="openDistributionModal()" class="new-distribution-btn">
                        <i class="fa-regular fa-plus"></i>
                        <span>New Distribution</span>
                    </button>
                </div>
            </div>
        </div>

        <div class="p-8">

            <!-- DISTRIBUTION TABLE -->
            <div class="bg-white border border-[#e5e5e5] rounded-md overflow-hidden">
                <div class="px-5 py-4 border-b border-[#e5e5e5] bg-[#fafafa] flex justify-between items-center">
                    <h2 class="text-sm font-medium text-[#1e1e1e]">Distribution History</h2>
                    <div class="flex items-center gap-3">
                        <div class="relative flex items-center gap-2">
                            <i class="fa-solid fa-magnifying-glass absolute left-3 top-2.5 text-sm text-[#9e9e9e]"></i>
                            <input type="text" id="tableSearch" placeholder="Search records..."
                                class="pl-9 pr-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e] w-64"
                                autocomplete="off">
                            <button onclick="filterDistributionTable(true)" class="px-4 py-2 text-sm bg-[#1e1e1e] text-white rounded-md hover:bg-[#2d2d2d] whitespace-nowrap">
                                Search
                            </button>
                        </div>
                        <span class="text-xs text-[#6e6e6e]">Total: <?php echo $total_distributions; ?> records</span>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full" id="distributionTable">
                        <thead class="bg-[#fafafa]">
                            <tr class="text-left text-xs text-[#4a4a4a]">
                                <th class="p-3 cursor-pointer hover:bg-[#f0f0f0]" onclick="sortTable(0)">
                                    Document <i class="fa-solid fa-sort ml-1 text-[#9e9e9e]"></i>
                                </th>
                                <th class="p-3 cursor-pointer hover:bg-[#f0f0f0]" onclick="sortTable(1)">
                                    Type <i class="fa-solid fa-sort ml-1 text-[#9e9e9e]"></i>
                                </th>
                                <th class="p-3 cursor-pointer hover:bg-[#f0f0f0]" onclick="sortTable(2)">
                                    Copies Distributed <i class="fa-solid fa-sort ml-1 text-[#9e9e9e]"></i>
                                </th>
                                <th class="p-3 cursor-pointer hover:bg-[#f0f0f0]" onclick="sortTable(3)">
                                    Date <i class="fa-solid fa-sort ml-1 text-[#9e9e9e]"></i>
                                </th>
                                <th class="p-3 cursor-pointer hover:bg-[#f0f0f0]" onclick="sortTable(4)">
                                    Timestamp <i class="fa-solid fa-sort ml-1 text-[#9e9e9e]"></i>
                                </th>
                                <th class="p-3">Actions</th>
                            </tr>
                        </thead>

                        <tbody id="tableBody">
                            <?php
                            if ($result && $result->num_rows > 0):
                                while ($row = $result->fetch_assoc()):
                            ?>
                                    <tr class="border-t text-sm hover:bg-[#fafafa] distribution-row" id="row-<?php echo $row['id']; ?>"
                                        data-search="<?php echo strtolower(htmlspecialchars(trim(($row['document_name'] ?? '') . ' ' . ($row['document_type'] ?? '') . ' ' . ($row['number_distributed'] ?? 0) . ' ' . ($row['date_distributed'] ?? '') . ' ' . ($row['created_at'] ?? '')))); ?>">
                                        <td class="p-3">
                                            <a href="list.php?search=<?php echo urlencode($row['document_name']); ?>"
                                                class="text-[#1e1e1e] hover:underline font-medium">
                                                <?php echo htmlspecialchars($row['document_name']); ?>
                                            </a>
                                        </td>
                                        <td class="p-3">
                                            <?php if (!empty($row['document_type'])): ?>
                                                <span class="badge badge-info">
                                                    <i class="fa-solid fa-tag mr-1"></i>
                                                    <?php echo htmlspecialchars($row['document_type']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-[#9e9e9e]">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-3 font-mono"><?php echo $row['number_distributed'] ?? 0; ?></td>
                                        <td class="p-3"><?php echo date('M j, Y', strtotime($row['date_distributed'])); ?></td>
                                        <td class="p-3 whitespace-nowrap"><?php echo formatTimestampDisplay($row['created_at'] ?? null); ?></td>
                                        <td class="p-3">
                                            <div class="flex gap-2">
                                                <button onclick="viewDistribution(<?php echo htmlspecialchars(json_encode($row)); ?>)"
                                                    class="action-btn" title="View Details">
                                                    <i class="fa-regular fa-eye"></i>
                                                </button>
                                                <button onclick="openDeleteModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars(addslashes($row['document_name'])); ?>', <?php echo $row['number_distributed']; ?>)"
                                                    class="action-btn delete-btn" title="Delete">
                                                    <i class="fa-regular fa-trash-can"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php
                                endwhile;
                            else:
                                ?>
                                <tr>
                                    <td colspan="6" class="p-8 text-center text-sm text-[#6e6e6e]">
                                        No distribution records found. Click "New Distribution" to get started.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Table Footer with Record Count -->
                <div class="px-5 py-3 border-t border-[#e5e5e5] bg-[#fafafa] text-xs text-[#6e6e6e] flex justify-between items-center">
                    <span>Showing <span id="visibleCount"><?php echo $total_distributions; ?></span> records</span>
                    <span>Total Copies Distributed: <?php echo $total_copies_distributed; ?></span>
                </div>
            </div>
            <div id="distributionPagination" class="pagination-shell mt-4 <?php echo (!$result || $result->num_rows === 0) ? 'hidden' : ''; ?>">
                <div class="pagination-meta">
                    <div id="distributionPaginationTitle" class="pagination-title"></div>
                    <div id="distributionPaginationInfo" class="pagination-subtitle"></div>
                </div>
                <div class="pagination-controls">
                    <div id="distributionPaginationPage" class="pagination-page-indicator"></div>
                    <div class="pagination" id="distributionPaginationControls"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Distribution Modal - Simplified (No department/recipient fields) -->
    <div id="distributionModal" class="fixed inset-0 bg-[#000000] bg-opacity-20 hidden items-center justify-center z-50 modal">
        <div class="bg-white border border-[#e5e5e5] rounded-md w-full max-w-2xl p-6 modal-content">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-medium text-[#1e1e1e]">New Distribution</h2>
                <button type="button" onclick="closeDistributionModal()" class="text-[#9e9e9e] hover:text-[#1e1e1e]">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>

            <div id="stockWarning" class="hidden mb-4 p-3 warning-message rounded-md text-sm">
                <i class="fa-regular fa-triangle-exclamation mr-2"></i>
                <span id="warningMessage"></span>
            </div>

            <form method="POST" action="distribution.php" id="distributionForm" onsubmit="return validateForm()">
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">
                            Document <span class="text-red-400">*</span>
                        </label>
                        <select name="document_id" id="modalDocumentSelect" required onchange="updateAvailableCopies()" class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e] bg-white">
                            <option value="">-- Select Document --</option>
                            <?php
                            // Group documents by type and show available copies
                            $documents->data_seek(0);
                            $grouped_documents = [];
                            while ($doc = $documents->fetch_assoc()) {
                                $type_name = $doc['document_type'] ?? 'Uncategorized';
                                if (!isset($grouped_documents[$type_name])) {
                                    $grouped_documents[$type_name] = [];
                                }
                                $grouped_documents[$type_name][] = $doc;
                            }

                            // Display documents grouped by type
                            foreach ($grouped_documents as $type_name => $docs):
                                $has_available = false;
                                foreach ($docs as $doc) {
                                    if ($doc['available_copies'] > 0) {
                                        $has_available = true;
                                        break;
                                    }
                                }
                            ?>
                                <optgroup label="<?php echo htmlspecialchars($type_name); ?>" class="font-semibold bg-gray-50">
                                    <?php foreach ($docs as $doc):
                                        $available = $doc['available_copies'];
                                    ?>
                                        <option value="<?php echo $doc['id']; ?>"
                                            data-available="<?php echo $available; ?>"
                                            data-total="<?php echo $doc['copies_received']; ?>"
                                            <?php echo $available <= 0 ? 'disabled class="text-gray-400"' : ''; ?>>
                                            <?php echo htmlspecialchars($doc['document_name']); ?>
                                            (<?php echo $available; ?> of <?php echo $doc['copies_received']; ?> available)
                                            <?php echo $available <= 0 ? ' - OUT OF STOCK' : ''; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">
                            Date Distributed <span class="text-red-400">*</span>
                        </label>
                        <input type="date" name="date_distributed" id="modalDateDistributed" required value="<?php echo date('Y-m-d'); ?>"
                            class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]" autocomplete="off">
                    </div>
                </div>

                <!-- Distribution Summary -->
                <div id="distributionSummary" class="hidden mb-4 p-3 bg-[#f5f5f4] rounded-md text-sm">
                    <div class="flex justify-between items-center">
                        <span class="text-[#6e6e6e]">Total copies to distribute:</span>
                        <span class="font-medium" id="totalCopiesToDistribute">0</span>
                    </div>
                    <div class="flex justify-between items-center mt-1">
                        <span class="text-[#6e6e6e]">Available copies:</span>
                        <span class="font-medium" id="availableCopiesDisplay">0</span>
                    </div>
                    <div class="flex justify-between items-center mt-1 text-xs" id="balanceWarning">
                        <!-- Will show warning if exceeding available -->
                    </div>
                </div>

                <!-- MULTIPLE DISTRIBUTION ROWS (Only copy count needed) -->
                <div class="mb-3">
                    <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-2">
                        Distribution Entries
                    </label>
                    <p class="text-xs text-[#6e6e6e] mb-2">Add multiple distribution entries if distributing to multiple recipients</p>
                </div>

                <div id="modalDistributionRows">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-3">
                        <div class="flex gap-2">
                            <input type="number" name="number_distributed[]" placeholder="Number of Copies" min="1" value="1"
                                class="flex-1 px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e] distribution-copies"
                                onchange="updateDistributionSummary()" onkeyup="updateDistributionSummary()" autocomplete="off">
                            <button type="button" onclick="removeModalRow(this)" class="px-2 text-[#9e9e9e] hover:text-[#dc2626]">
                                <i class="fa-regular fa-trash-can"></i>
                            </button>
                        </div>
                        <div class="text-xs text-[#6e6e6e] flex items-center">
                            <i class="fa-regular fa-info-circle mr-1"></i> Enter number of copies to distribute
                        </div>
                    </div>
                </div>

                <div class="flex gap-3 mb-4">
                    <button type="button" onclick="addModalRow()"
                        class="px-3 py-1.5 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e] flex items-center">
                        <i class="fa-regular fa-plus mr-1 text-[#6e6e6e]"></i> Add Another Entry
                    </button>

                    <button type="button" onclick="addModalBulkRows()"
                        class="px-3 py-1.5 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e] flex items-center">
                        <i class="fa-solid fa-layer-group mr-1 text-[#6e6e6e]"></i> Add 5 Entries
                    </button>

                    <button type="button" onclick="setMaxDistribution()"
                        class="px-3 py-1.5 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e] flex items-center">
                        <i class="fa-solid fa-gauge-high mr-1 text-[#6e6e6e]"></i> Use All Available Copies
                    </button>
                </div>

                <div class="flex justify-end gap-3 mt-4 pt-4 border-t border-[#e5e5e5]">
                    <button type="button" onclick="closeDistributionModal()"
                        class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                        Cancel
                    </button>
                    <button type="submit" name="submit" id="submitBtn"
                        class="px-4 py-2 text-sm bg-[#1e1e1e] text-white rounded-md hover:bg-[#2d2d2d]">
                        <i class="fa-regular fa-floppy-disk mr-1"></i>
                        Save Distribution
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Distribution Modal -->
    <div id="viewModal" class="fixed inset-0 bg-[#000000] bg-opacity-20 hidden items-center justify-center z-50" style="display: none;">
        <div class="bg-white border border-[#e5e5e5] rounded-md w-full max-w-md p-5">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-base font-medium text-[#1e1e1e]">Distribution Details</h3>
                <button onclick="closeViewModal()" class="text-[#6e6e6e] hover:text-[#1e1e1e]">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>

            <div id="viewContent" class="space-y-3">
                <!-- Filled by JavaScript -->
            </div>

            <div class="flex justify-end gap-2 mt-4">
                <button onclick="closeViewModal()"
                    class="px-3 py-1.5 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                    Close
                </button>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="fixed inset-0 bg-[#000000] bg-opacity-20 hidden items-center justify-center z-50 modal">
        <div class="bg-white border border-[#e5e5e5] rounded-md w-full max-w-md p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-medium text-[#1e1e1e]">Confirm Delete</h2>
                <button type="button" onclick="closeDeleteModal()" class="text-[#9e9e9e] hover:text-[#1e1e1e]">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>

            <div class="py-2">
                <p class="text-sm text-[#6e6e6e]">Are you sure you want to delete this distribution record?</p>
                <div class="mt-4 p-3 bg-red-50 border border-red-200 rounded-md">
                    <p class="text-sm font-medium text-red-800" id="deleteDocumentName"></p>
                    <p class="text-xs text-red-600 mt-1" id="deleteCopiesCount"></p>
                </div>
                <p class="text-xs text-[#9e9e9e] mt-3">
                    <i class="fa-solid fa-circle-info mr-1"></i>
                    This will restore the copies back to the document inventory.
                </p>
            </div>

            <div class="flex justify-end gap-2 mt-6">
                <button onclick="closeDeleteModal()"
                    class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                    Cancel
                </button>
                <a href="#" id="confirmDeleteBtn"
                    class="px-4 py-2 text-sm bg-red-600 text-white rounded-md hover:bg-red-700">
                    Delete & Restore Copies
                </a>
            </div>
        </div>
    </div>

    <script>
        // Show toast notification from PHP session
        <?php if ($toast): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showToast('<?php echo addslashes($toast['message']); ?>', '<?php echo $toast['type']; ?>');
            });
        <?php endif; ?>

        // Toast notification function
        function showToast(message, type = 'info', duration = 5000) {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;

            let icon = 'fa-circle-check';
            if (type === 'error') icon = 'fa-circle-exclamation';
            if (type === 'warning') icon = 'fa-triangle-exclamation';
            if (type === 'info') icon = 'fa-circle-info';

            let iconColor = '#10b981';
            if (type === 'error') iconColor = '#ef4444';
            if (type === 'warning') iconColor = '#f59e0b';
            if (type === 'info') iconColor = '#3b82f6';

            toast.innerHTML = `
                <div class="toast-content">
                    <i class="fa-regular ${icon}" style="color: ${iconColor};"></i>
                    <span class="text-sm">${message}</span>
                </div>
                <span class="toast-close" onclick="this.parentElement.remove()">&times;</span>
                <div class="toast-progress"></div>
            `;

            container.appendChild(toast);

            setTimeout(() => {
                if (toast.parentElement) {
                    toast.style.animation = 'slideOut 0.3s ease';
                    setTimeout(() => {
                        if (toast.parentElement) {
                            toast.remove();
                        }
                    }, 300);
                }
            }, duration);
        }

        // ========== MODAL FUNCTIONS ==========
        function openDistributionModal() {
            resetModalForm();
            document.getElementById('distributionModal').style.display = 'flex';
            updateAvailableCopies();
        }

        function closeDistributionModal() {
            document.getElementById('distributionModal').style.display = 'none';
        }

        function resetModalForm() {
            const container = document.getElementById('modalDistributionRows');
            const rows = container.querySelectorAll('.grid');
            for (let i = 1; i < rows.length; i++) {
                rows[i].remove();
            }

            const firstRow = rows[0];
            if (firstRow) {
                const copiesInput = firstRow.querySelector('input[name="number_distributed[]"]');
                if (copiesInput) copiesInput.value = '1';
            }

            document.getElementById('modalDocumentSelect').value = '';
            document.getElementById('modalDateDistributed').value = '<?php echo date('Y-m-d'); ?>';
            document.getElementById('distributionSummary').classList.add('hidden');
            document.getElementById('stockWarning').classList.add('hidden');
        }

        function updateAvailableCopies() {
            const select = document.getElementById('modalDocumentSelect');
            const selectedOption = select.options[select.selectedIndex];

            if (selectedOption && selectedOption.value) {
                const available = selectedOption.dataset.available || 0;
                document.getElementById('availableCopiesDisplay').textContent = available;
                document.getElementById('distributionSummary').classList.remove('hidden');
                updateDistributionSummary();
            } else {
                document.getElementById('distributionSummary').classList.add('hidden');
            }
        }

        function updateDistributionSummary() {
            const select = document.getElementById('modalDocumentSelect');
            const selectedOption = select.options[select.selectedIndex];

            if (!selectedOption || !selectedOption.value) {
                return;
            }

            const available = parseInt(selectedOption.dataset.available || 0);
            const copies = document.querySelectorAll('.distribution-copies');
            let total = 0;

            copies.forEach(input => {
                const val = parseInt(input.value);
                if (!isNaN(val) && val > 0) {
                    total += val;
                }
            });

            document.getElementById('totalCopiesToDistribute').textContent = total;

            const balanceWarning = document.getElementById('balanceWarning');
            const submitBtn = document.getElementById('submitBtn');
            const stockWarning = document.getElementById('stockWarning');
            const warningMessage = document.getElementById('warningMessage');

            if (total > available) {
                balanceWarning.innerHTML = `<span class="text-red-600"><i class="fa-regular fa-circle-exclamation mr-1"></i>Exceeds available by ${total - available} copies</span>`;
                submitBtn.disabled = true;
                submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
                stockWarning.classList.remove('hidden');
                warningMessage.textContent = `Warning: You are trying to distribute ${total} copies but only ${available} are available.`;
            } else {
                balanceWarning.innerHTML = '';
                submitBtn.disabled = false;
                submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                stockWarning.classList.add('hidden');
            }
        }

        function setMaxDistribution() {
            const select = document.getElementById('modalDocumentSelect');
            const selectedOption = select.options[select.selectedIndex];

            if (!selectedOption || !selectedOption.value) {
                showToast('Please select a document first', 'warning');
                return;
            }

            const available = parseInt(selectedOption.dataset.available || 0);
            const rows = document.querySelectorAll('.distribution-copies');

            if (rows.length === 0) return;

            const perRow = Math.floor(available / rows.length);
            const remainder = available % rows.length;

            rows.forEach((input, index) => {
                if (index < remainder) {
                    input.value = perRow + 1;
                } else {
                    input.value = perRow;
                }
            });

            updateDistributionSummary();
            showToast(`Set to distribute all ${available} available copies`, 'info', 2000);
        }

        function addModalRow() {
            const container = document.getElementById('modalDistributionRows');
            const row = `
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-3">
                    <div class="flex gap-2">
                        <input type="number" name="number_distributed[]" placeholder="Number of Copies" min="1" value="1"
                            class="flex-1 px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e] distribution-copies"
                            onchange="updateDistributionSummary()" onkeyup="updateDistributionSummary()" autocomplete="off">
                        <button type="button" onclick="removeModalRow(this)" class="px-2 text-[#9e9e9e] hover:text-[#dc2626]">
                            <i class="fa-regular fa-trash-can"></i>
                        </button>
                    </div>
                    <div class="text-xs text-[#6e6e6e] flex items-center">
                        <i class="fa-regular fa-info-circle mr-1"></i> Enter number of copies to distribute
                    </div>
                </div>
            `;
            container.insertAdjacentHTML("beforeend", row);
            updateDistributionSummary();
            showToast('New distribution entry added', 'info', 2000);
        }

        function addModalBulkRows() {
            for (let i = 0; i < 5; i++) {
                addModalRow();
            }
            showToast('5 distribution entries added', 'success', 2000);
        }

        function removeModalRow(button) {
            const row = button.closest('.grid');
            if (row && document.querySelectorAll('#modalDistributionRows .grid').length > 1) {
                row.remove();
                updateDistributionSummary();
                showToast('Entry removed', 'info', 2000);
            } else {
                showToast('You must keep at least one distribution entry', 'warning', 3000);
            }
        }

        function validateForm() {
            const documentId = document.getElementById('modalDocumentSelect').value;
            if (!documentId) {
                showToast('Please select a document', 'warning');
                return false;
            }

            const select = document.getElementById('modalDocumentSelect');
            const selectedOption = select.options[select.selectedIndex];
            const available = parseInt(selectedOption.dataset.available || 0);

            const copies = document.querySelectorAll('.distribution-copies');
            let totalCopies = 0;
            let hasValidEntry = false;

            copies.forEach(input => {
                const val = parseInt(input.value);
                if (!isNaN(val) && val > 0) {
                    hasValidEntry = true;
                    totalCopies += val;
                }
            });

            if (!hasValidEntry) {
                showToast('Please enter at least one valid number of copies', 'warning');
                return false;
            }

            if (totalCopies > available) {
                showToast(`Cannot distribute ${totalCopies} copies. Only ${available} available.`, 'warning');
                return false;
            }

            return confirm(`You are about to distribute ${totalCopies} copy(ies). Continue?`);
        }

        // ========== DELETE MODAL FUNCTIONS ==========
        let currentDeleteId = null;

        function openDeleteModal(id, documentName, copies) {
            currentDeleteId = id;
            document.getElementById('deleteDocumentName').textContent = documentName;
            document.getElementById('deleteCopiesCount').textContent = `Copies: ${copies}`;

            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('delete', id);
            document.getElementById('confirmDeleteBtn').href = '?' + urlParams.toString();

            document.getElementById('deleteModal').style.display = 'flex';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
            currentDeleteId = null;
        }

        // View distribution details
        function viewDistribution(data) {
            const content = document.getElementById('viewContent');
            content.innerHTML = `
                <div class="grid grid-cols-2 gap-3">
                    <div class="col-span-2">
                        <p class="text-xs text-[#6e6e6e] uppercase mb-1">Document</p>
                        <p class="text-sm font-medium">${escapeHtml(data.document_name || '')}</p>
                    </div>
                    <div>
                        <p class="text-xs text-[#6e6e6e] uppercase mb-1">Document Type</p>
                        <p class="text-sm">${escapeHtml(data.document_type || 'Not specified')}</p>
                    </div>
                    <div>
                        <p class="text-xs text-[#6e6e6e] uppercase mb-1">Copies Distributed</p>
                        <p class="text-sm font-mono">${data.number_distributed || 0}</p>
                    </div>
                    <div>
                        <p class="text-xs text-[#6e6e6e] uppercase mb-1">Date Distributed</p>
                        <p class="text-sm">${data.date_distributed ? new Date(data.date_distributed).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : ''}</p>
                    </div>
                    <div>
                        <p class="text-xs text-[#6e6e6e] uppercase mb-1">Timestamp</p>
                        <p class="text-sm">${data.created_at ? new Date(data.created_at.replace(' ', 'T')).toLocaleString() : 'N/A'}</p>
                    </div>
                    <div class="col-span-2">
                        <p class="text-xs text-[#6e6e6e] uppercase mb-1">Total Copies of Document</p>
                        <p class="text-sm">${data.total_copies || 0}</p>
                    </div>
                </div>
            `;
            document.getElementById('viewModal').style.display = 'flex';
        }

        function closeViewModal() {
            document.getElementById('viewModal').style.display = 'none';
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Table search functionality
        const distributionPageSize = 10;
        let distributionCurrentPage = 1;
        let distributionSearchTimer;

        function getVisibleDistributionRows() {
            return Array.from(document.querySelectorAll('.distribution-row')).filter(row => row.dataset.filtered !== 'false');
        }

        function renderDistributionPagination() {
            const visibleRows = getVisibleDistributionRows();
            const totalRows = visibleRows.length;
            const totalPages = Math.max(1, Math.ceil(totalRows / distributionPageSize));
            const wrapper = document.getElementById('distributionPagination');
            const title = document.getElementById('distributionPaginationTitle');
            const info = document.getElementById('distributionPaginationInfo');
            const pageIndicator = document.getElementById('distributionPaginationPage');
            const controls = document.getElementById('distributionPaginationControls');

            if (!wrapper || !info || !controls) return;

            if (distributionCurrentPage > totalPages) distributionCurrentPage = totalPages;

            const startIndex = (distributionCurrentPage - 1) * distributionPageSize;
            const endIndex = startIndex + distributionPageSize;

            document.querySelectorAll('.distribution-row').forEach(row => {
                row.style.display = 'none';
            });

            visibleRows.forEach((row, index) => {
                row.style.display = index >= startIndex && index < endIndex ? '' : 'none';
            });

            if (totalRows === 0) {
                if (title) title.textContent = '';
                info.textContent = 'No matching records';
                if (pageIndicator) pageIndicator.textContent = '';
                controls.innerHTML = '';
                wrapper.classList.add('hidden');
                return;
            }

            const from = startIndex + 1;
            const to = Math.min(endIndex, totalRows);
            const visibleCount = Math.max(0, to - startIndex);
            if (title) title.textContent = `Showing ${visibleCount} ${visibleCount === 1 ? 'record' : 'records'} on this page`;
            info.textContent = `Records ${from}-${to} of ${totalRows} total`;
            if (pageIndicator) pageIndicator.textContent = `Page ${distributionCurrentPage} of ${totalPages}`;
            wrapper.classList.toggle('hidden', totalRows <= distributionPageSize);

            const startPage = Math.max(1, distributionCurrentPage - 2);
            const endPage = Math.min(totalPages, distributionCurrentPage + 2);
            let controlsHtml = `
                <button class="pagination-item compact ${distributionCurrentPage === 1 ? 'disabled' : ''}" ${distributionCurrentPage === 1 ? 'disabled' : ''} onclick="changeDistributionPage(1)">
                    <i class="fa-solid fa-chevrons-left"></i>
                </button>
                <button class="pagination-item compact ${distributionCurrentPage === 1 ? 'disabled' : ''}" ${distributionCurrentPage === 1 ? 'disabled' : ''} onclick="changeDistributionPage(${distributionCurrentPage - 1})">
                    <i class="fa-solid fa-chevron-left"></i>
                </button>
            `;

            if (startPage > 1) {
                controlsHtml += `<button class="pagination-item" onclick="changeDistributionPage(1)">1</button>`;
                if (startPage > 2) controlsHtml += `<span class="pagination-ellipsis">...</span>`;
            }

            for (let i = startPage; i <= endPage; i++) {
                controlsHtml += `<button class="pagination-item ${i === distributionCurrentPage ? 'active' : ''}" onclick="changeDistributionPage(${i})">${i}</button>`;
            }

            if (endPage < totalPages) {
                if (endPage < totalPages - 1) controlsHtml += `<span class="pagination-ellipsis">...</span>`;
                controlsHtml += `<button class="pagination-item" onclick="changeDistributionPage(${totalPages})">${totalPages}</button>`;
            }

            controlsHtml += `
                <button class="pagination-item compact ${distributionCurrentPage === totalPages ? 'disabled' : ''}" ${distributionCurrentPage === totalPages ? 'disabled' : ''} onclick="changeDistributionPage(${distributionCurrentPage + 1})">
                    <i class="fa-solid fa-chevron-right"></i>
                </button>
                <button class="pagination-item compact ${distributionCurrentPage === totalPages ? 'disabled' : ''}" ${distributionCurrentPage === totalPages ? 'disabled' : ''} onclick="changeDistributionPage(${totalPages})">
                    <i class="fa-solid fa-chevrons-right"></i>
                </button>
            `;
            controls.innerHTML = controlsHtml;
        }

        function changeDistributionPage(page) {
            distributionCurrentPage = Math.max(1, page);
            renderDistributionPagination();
        }

        function filterDistributionTable(showFeedback = false) {
            const searchTokens = (document.getElementById('tableSearch')?.value || '')
                .toLowerCase()
                .split(/\s+/)
                .filter(Boolean);
            const rows = document.querySelectorAll('.distribution-row');
            let visibleCount = 0;

            rows.forEach(row => {
                const text = row.getAttribute('data-search') || row.textContent.toLowerCase();
                const matches = searchTokens.length === 0 || searchTokens.every(token => text.includes(token));
                row.dataset.filtered = matches ? 'true' : 'false';
                row.style.display = matches ? '' : 'none';
                if (matches) visibleCount++;
            });

            const countEl = document.getElementById('visibleCount');
            if (countEl) countEl.textContent = visibleCount;
            distributionCurrentPage = 1;
            renderDistributionPagination();

            if (showFeedback && visibleCount === 0) showToast('No matching records found', 'info', 2000);
        }

        document.getElementById('tableSearch')?.addEventListener('input', function() {
            clearTimeout(distributionSearchTimer);
            distributionSearchTimer = setTimeout(() => filterDistributionTable(false), 150);
        });

        // Table sorting
        let sortDirection = 'asc';
        let lastSortedColumn = -1;

        function sortTable(columnIndex) {
            const tbody = document.getElementById('tableBody');
            const rows = Array.from(tbody.querySelectorAll('tr'));

            if (lastSortedColumn === columnIndex) {
                sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                sortDirection = 'asc';
                lastSortedColumn = columnIndex;
            }

            rows.sort((a, b) => {
                const aCol = a.querySelectorAll('td')[columnIndex]?.textContent.trim() || '';
                const bCol = b.querySelectorAll('td')[columnIndex]?.textContent.trim() || '';

                if (!isNaN(aCol) && !isNaN(bCol)) {
                    return sortDirection === 'asc' ? parseFloat(aCol) - parseFloat(bCol) : parseFloat(bCol) - parseFloat(aCol);
                }

                const comparison = aCol.localeCompare(bCol);
                return sortDirection === 'asc' ? comparison : -comparison;
            });

            tbody.innerHTML = '';
            rows.forEach(row => tbody.appendChild(row));
            renderDistributionPagination();
            showToast(`Sorted by column ${columnIndex + 1} (${sortDirection})`, 'info', 1500);
        }

        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.distribution-row').forEach(row => {
                row.dataset.filtered = 'true';
            });
            renderDistributionPagination();
        });

        // Close modals when clicking outside
        window.onclick = function(event) {
            const distributionModal = document.getElementById('distributionModal');
            const viewModal = document.getElementById('viewModal');
            const deleteModal = document.getElementById('deleteModal');

            if (event.target == distributionModal) closeDistributionModal();
            if (event.target == viewModal) closeViewModal();
            if (event.target == deleteModal) closeDeleteModal();
        }

        // ESC key to close modals
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeDistributionModal();
                closeViewModal();
                closeDeleteModal();
            }
        });
    </script>
</body>

</html>
