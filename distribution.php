<?php
require_once './config/db.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session for toast messages
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Handle form submission
if (isset($_POST['submit'])) {

    $document_id = $_POST['document_id'];
    $date_distributed = $_POST['date_distributed'];

    $departments = $_POST['department'];
    $recipients = $_POST['recipient_name'];
    $numbers = $_POST['number_distributed'];

    $success_count = 0;
    $error_messages = [];

    for ($i = 0; $i < count($departments); $i++) {

        $department = trim($departments[$i]);
        $recipient = trim($recipients[$i]);
        $number = (int)$numbers[$i];

        if ($department != "" && $recipient != "" && $number > 0) {

            // Added number_received field (using same value as number_distributed)
            $sql = "INSERT INTO document_distribution 
                    (document_id, department, recipient_name, number_received, number_distributed, date_distributed)
                    VALUES 
                    (?, ?, ?, ?, ?, ?)";

            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                $error_messages[] = "Prepare failed: " . $conn->error;
                continue;
            }

            // Using same number for both received and distributed
            $stmt->bind_param("issiis", $document_id, $department, $recipient, $number, $number, $date_distributed);

            if ($stmt->execute()) {
                $success_count++;
            } else {
                $error_messages[] = "Error for row " . ($i + 1) . ": " . $stmt->error;
            }
            $stmt->close();
        }
    }

    if ($success_count > 0) {
        $_SESSION['toast'] = [
            'type' => 'success',
            'message' => "$success_count distribution record(s) saved successfully"
        ];
    }

    if (!empty($error_messages)) {
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => implode("<br>", array_slice($error_messages, 0, 3))
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

    $stmt = $conn->prepare("DELETE FROM document_distribution WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        $_SESSION['toast'] = [
            'type' => 'success',
            'message' => "Distribution record deleted successfully!"
        ];
    } else {
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => "Error deleting record: " . $conn->error
        ];
    }
    $stmt->close();

    // Preserve any query parameters
    $query_params = $_GET;
    unset($query_params['delete']);
    $redirect_url = 'distribution.php' . (!empty($query_params) ? '?' . http_build_query($query_params) : '');
    header('Location: ' . $redirect_url);
    exit();
}

// Get all documents with their types
$documents = $conn->query("
    SELECT d.*, dt.type_name as document_type 
    FROM documents d 
    LEFT JOIN document_types dt ON d.type_id = dt.id 
    ORDER BY d.document_name ASC
");

// Get all document types for option grouping
$document_types = $conn->query("
    SELECT * FROM document_types 
    ORDER BY type_name ASC
");

// Get distribution records with document and type information
$result = $conn->query("
    SELECT dd.*, d.document_name, d.type_id, dt.type_name as document_type
    FROM document_distribution dd
    JOIN documents d ON dd.document_id = d.id
    LEFT JOIN document_types dt ON d.type_id = dt.id
    ORDER BY dd.date_distributed DESC, dd.id DESC
");

// Check if query failed
if (!$result) {
    $_SESSION['toast'] = ['type' => 'error', 'message' => "Database error: " . $conn->error];
}

// Get statistics for header
$total_distributions = 0;
$total_result = $conn->query("SELECT COUNT(*) as count FROM document_distribution");
if ($total_result) {
    $total_distributions = $total_result->fetch_assoc()['count'];
}

$today = date('Y-m-d');
$today_distributions = 0;
$today_result = $conn->query("SELECT COUNT(*) as count FROM document_distribution WHERE date_distributed = '$today'");
if ($today_result) {
    $today_distributions = $today_result->fetch_assoc()['count'];
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

        .newspaper-grid {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #e5e5e5;
            border-radius: 0.375rem;
            padding: 0.5rem;
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
                    <p class="text-sm text-[#6e6e6e] mt-1">Track document distribution across departments</p>
                </div>
                <div class="flex gap-2">
                    <button onclick="openDistributionModal()" class="new-distribution-btn">
                        <i class="fa-regular fa-plus"></i>
                        <span>New Distribution</span>
                    </button>
                    <a href="./document_types.php" class="px-3 py-1.5 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e] flex items-center">
                        <i class="fa-solid fa-tags mr-1 text-[#6e6e6e]"></i> Manage Types
                    </a>
                </div>
            </div>
        </div>

        <div class="p-8">

            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="stat-card bg-white border border-[#e5e5e5] rounded-md p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs text-[#6e6e6e] uppercase tracking-wide">Total Distributions</p>
                            <p class="text-2xl font-medium text-[#1e1e1e] mt-1"><?php echo $total_distributions; ?></p>
                        </div>
                        <div class="w-10 h-10 bg-[#f5f5f4] rounded-full flex items-center justify-center">
                            <i class="fa-solid fa-share-from-square text-[#6e6e6e]"></i>
                        </div>
                    </div>
                </div>
                <div class="stat-card bg-white border border-[#e5e5e5] rounded-md p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs text-[#6e6e6e] uppercase tracking-wide">Today</p>
                            <p class="text-2xl font-medium text-[#1e1e1e] mt-1"><?php echo $today_distributions; ?></p>
                        </div>
                        <div class="w-10 h-10 bg-[#f5f5f4] rounded-full flex items-center justify-center">
                            <i class="fa-regular fa-calendar text-[#6e6e6e]"></i>
                        </div>
                    </div>
                </div>
                <div class="stat-card bg-white border border-[#e5e5e5] rounded-md p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs text-[#6e6e6e] uppercase tracking-wide">Document Types</p>
                            <p class="text-2xl font-medium text-[#1e1e1e] mt-1"><?php echo $type_count; ?></p>
                        </div>
                        <div class="w-10 h-10 bg-[#f5f5f4] rounded-full flex items-center justify-center">
                            <i class="fa-solid fa-tags text-[#6e6e6e]"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Links -->
            <div class="bg-white border border-[#e5e5e5] rounded-md p-4 mb-6">
                <div class="flex flex-wrap gap-3">
                    <span class="text-sm text-[#6e6e6e] mr-2">Quick Links:</span>
                    <a href="document_types.php?action=create" class="text-sm text-[#1e1e1e] hover:underline flex items-center">
                        <i class="fa-regular fa-plus mr-1 text-[#6e6e6e]"></i> New Type
                    </a>
                    <span class="text-[#e5e5e5]">|</span>
                    <a href="document_types.php" class="text-sm text-[#1e1e1e] hover:underline flex items-center">
                        <i class="fa-solid fa-list mr-1 text-[#6e6e6e]"></i> View All Types
                    </a>
                </div>
            </div>

            <!-- DISTRIBUTION TABLE -->
            <div class="bg-white border border-[#e5e5e5] rounded-md overflow-hidden">
                <div class="px-5 py-4 border-b border-[#e5e5e5] bg-[#fafafa] flex justify-between items-center">
                    <h2 class="text-sm font-medium text-[#1e1e1e]">Distribution History</h2>
                    <div class="flex items-center gap-3">
                        <div class="relative">
                            <i class="fa-solid fa-magnifying-glass absolute left-3 top-2.5 text-sm text-[#9e9e9e]"></i>
                            <input type="text" id="tableSearch" placeholder="Search records..."
                                class="pl-9 pr-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e] w-64">
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
                                    Department <i class="fa-solid fa-sort ml-1 text-[#9e9e9e]"></i>
                                </th>
                                <th class="p-3 cursor-pointer hover:bg-[#f0f0f0]" onclick="sortTable(3)">
                                    Recipient <i class="fa-solid fa-sort ml-1 text-[#9e9e9e]"></i>
                                </th>
                                <th class="p-3 cursor-pointer hover:bg-[#f0f0f0]" onclick="sortTable(4)">
                                    Received <i class="fa-solid fa-sort ml-1 text-[#9e9e9e]"></i>
                                </th>
                                <th class="p-3 cursor-pointer hover:bg-[#f0f0f0]" onclick="sortTable(5)">
                                    Distributed <i class="fa-solid fa-sort ml-1 text-[#9e9e9e]"></i>
                                </th>
                                <th class="p-3 cursor-pointer hover:bg-[#f0f0f0]" onclick="sortTable(6)">
                                    Date <i class="fa-solid fa-sort ml-1 text-[#9e9e9e]"></i>
                                </th>
                                <th class="p-3">Actions</th>
                            </tr>
                        </thead>

                        <tbody id="tableBody">
                            <?php
                            if ($result && $result->num_rows > 0):
                                while ($row = $result->fetch_assoc()):
                            ?>
                                    <tr class="border-t text-sm hover:bg-[#fafafa]" id="row-<?php echo $row['id']; ?>">
                                        <td class="p-3">
                                            <a href="list.php?search=<?php echo urlencode($row['document_name']); ?>"
                                                class="text-[#1e1e1e] hover:underline font-medium">
                                                <?php echo htmlspecialchars($row['document_name']); ?>
                                            </a>
                                        </td>

                                        <td class="p-3">
                                            <?php if (!empty($row['document_type'])): ?>
                                                <a href="document_types.php?action=view&id=<?php echo $row['type_id']; ?>"
                                                    class="badge badge-info hover:underline">
                                                    <i class="fa-solid fa-tag mr-1"></i>
                                                    <?php echo htmlspecialchars($row['document_type']); ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-[#9e9e9e]">—</span>
                                            <?php endif; ?>
                                        </td>

                                        <td class="p-3"><?php echo htmlspecialchars($row['department'] ?? ''); ?></td>
                                        <td class="p-3"><?php echo htmlspecialchars($row['recipient_name'] ?? ''); ?></td>
                                        <td class="p-3 font-mono"><?php echo $row['number_received'] ?? 0; ?></td>
                                        <td class="p-3 font-mono"><?php echo $row['number_distributed'] ?? 0; ?></td>
                                        <td class="p-3"><?php echo date('M j, Y', strtotime($row['date_distributed'])); ?></td>

                                        <td class="p-3">
                                            <div class="flex gap-2">
                                                <button onclick="viewDistribution(<?php echo htmlspecialchars(json_encode($row)); ?>)"
                                                    class="action-btn" title="View Details">
                                                    <i class="fa-regular fa-eye"></i>
                                                </button>
                                                <?php if (!empty($row['type_id'])): ?>
                                                    <!--<a href="document_type.php?action=view&id=<?/*php echo $row['type_id']; */ ?>"
                                                        class="action-btn" title="View Document Type">
                                                        <i class="fa-solid fa-tags"></i>
                                                    </a>-->
                                                <?php endif; ?>
                                                <button onclick="openDeleteModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['document_name']); ?>', '<?php echo htmlspecialchars($row['recipient_name']); ?>')"
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
                                    <td colspan="8" class="p-8 text-center text-sm text-[#6e6e6e]">
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
                    <span>Document Types: <?php echo $type_count; ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Distribution Modal -->
    <div id="distributionModal" class="fixed inset-0 bg-[#000000] bg-opacity-20 hidden items-center justify-center z-50 modal">
        <div class="bg-white border border-[#e5e5e5] rounded-md w-full max-w-4xl p-6 modal-content">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-medium text-[#1e1e1e]">New Distribution</h2>
                <button type="button" onclick="closeDistributionModal()" class="text-[#9e9e9e] hover:text-[#1e1e1e]">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>

            <form method="POST" action="distribution.php" id="distributionForm" onsubmit="return validateForm()">

                <div class="grid grid-cols-2 gap-4 mb-4">

                    <div>
                        <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">
                            Document <span class="text-red-400">*</span>
                        </label>
                        <select name="document_id" id="modalDocumentSelect" required class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e] bg-white">
                            <option value="">-- Select Document --</option>

                            <?php
                            // Group documents by type
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
                            ?>
                                <optgroup label="<?php echo htmlspecialchars($type_name); ?>" class="font-semibold bg-gray-50">
                                    <?php foreach ($docs as $doc): ?>
                                        <option value="<?php echo $doc['id']; ?>" data-copies="<?php echo $doc['copies_received'] ?? 0; ?>">
                                            <?php echo htmlspecialchars($doc['document_name']); ?>
                                            (Available: <?php echo $doc['copies_received'] ?? 0; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>

                            <!-- Show document types without documents -->
                            <?php
                            $document_types->data_seek(0);
                            while ($type = $document_types->fetch_assoc()) {
                                $has_documents = false;
                                foreach ($grouped_documents as $type_name => $docs) {
                                    if ($type_name == $type['type_name']) {
                                        $has_documents = true;
                                        break;
                                    }
                                }
                                if (!$has_documents) {
                                    echo '<optgroup label="' . htmlspecialchars($type['type_name']) . ' (No documents)" class="font-semibold bg-gray-50 text-gray-400">';
                                    echo '<option value="" disabled>─ No documents available ─</option>';
                                    echo '</optgroup>';
                                }
                            }
                            ?>
                        </select>

                        <div class="mt-2 flex gap-2 text-xs">
                            <a href="list.php" target="_blank" class="text-[#6e6e6e] hover:text-[#1e1e1e] hover:underline flex items-center">
                                <i class="fa-regular fa-folder mr-1"></i> Manage Documents
                            </a>
                            <span class="text-[#e5e5e5]">|</span>
                            <a href="document_types.php?action=create" target="_blank" class="text-[#6e6e6e] hover:text-[#1e1e1e] hover:underline flex items-center">
                                <i class="fa-regular fa-plus mr-1"></i> New Type
                            </a>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">
                            Date Distributed <span class="text-red-400">*</span>
                        </label>
                        <input type="date" name="date_distributed" id="modalDateDistributed" required value="<?php echo date('Y-m-d'); ?>"
                            class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]">
                    </div>

                </div>

                <!-- MULTIPLE DISTRIBUTION ROWS -->
                <div class="mb-3">
                    <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-2">
                        Distribution Details <span class="text-red-400">*</span>
                    </label>
                </div>

                <div id="modalDistributionRows">

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-3">
                        <div>
                            <input type="text" name="department[]" placeholder="Department (e.g., IT, HR, Finance)"
                                class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]"
                                autocomplete="off">
                        </div>
                        <div>
                            <input type="text" name="recipient_name[]" placeholder="Recipient Name"
                                class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]"
                                autocomplete="off">
                        </div>
                        <div class="flex gap-2">
                            <input type="number" name="number_distributed[]" placeholder="Number of Copies" min="1" value="1"
                                class="flex-1 px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]">
                            <button type="button" onclick="removeModalRow(this)" class="px-2 text-[#9e9e9e] hover:text-[#dc2626]">
                                <i class="fa-regular fa-trash-can"></i>
                            </button>
                        </div>
                    </div>

                </div>

                <div class="flex gap-3 mb-4">
                    <button type="button" onclick="addModalRow()"
                        class="px-3 py-1.5 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e] flex items-center">
                        <i class="fa-regular fa-plus mr-1 text-[#6e6e6e]"></i> Add Row
                    </button>

                    <button type="button" onclick="addModalBulkRows()"
                        class="px-3 py-1.5 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e] flex items-center">
                        <i class="fa-solid fa-layer-group mr-1 text-[#6e6e6e]"></i> Add 5 Rows
                    </button>
                </div>

                <div class="flex justify-end gap-3 mt-4 pt-4 border-t border-[#e5e5e5]">
                    <button type="button" onclick="closeDistributionModal()"
                        class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                        Cancel
                    </button>
                    <button type="submit" name="submit"
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
                    <p class="text-xs text-red-600 mt-1" id="deleteRecipientName"></p>
                </div>
                <p class="text-xs text-[#9e9e9e] mt-3">
                    <i class="fa-solid fa-circle-info mr-1"></i>
                    This action cannot be undone.
                </p>
            </div>

            <div class="flex justify-end gap-2 mt-6">
                <button onclick="closeDeleteModal()"
                    class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                    Cancel
                </button>
                <a href="#" id="confirmDeleteBtn"
                    class="px-4 py-2 text-sm bg-red-600 text-white rounded-md hover:bg-red-700">
                    Delete Permanently
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

            // Create toast element
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;

            // Set icon based on type
            let icon = 'fa-circle-check';
            if (type === 'error') icon = 'fa-circle-exclamation';
            if (type === 'warning') icon = 'fa-triangle-exclamation';
            if (type === 'info') icon = 'fa-circle-info';

            // Set color based on type
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

            // Auto remove after duration
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
            // Reset form when opening
            resetModalForm();
            document.getElementById('distributionModal').style.display = 'flex';
        }

        function closeDistributionModal() {
            document.getElementById('distributionModal').style.display = 'none';
        }

        // Reset modal form to initial state
        function resetModalForm() {
            // Clear all rows except first
            const container = document.getElementById('modalDistributionRows');
            const rows = container.querySelectorAll('.grid');
            for (let i = 1; i < rows.length; i++) {
                rows[i].remove();
            }

            // Reset first row
            const firstRow = rows[0];
            if (firstRow) {
                firstRow.querySelectorAll('input').forEach(input => {
                    if (input.name.includes('number')) {
                        input.value = '1';
                    } else {
                        input.value = '';
                    }
                });
            }

            // Reset selects
            document.getElementById('modalDocumentSelect').value = '';
            document.getElementById('modalDateDistributed').value = '<?php echo date('Y-m-d'); ?>';
        }

        // Add a new row to the distribution form in modal
        function addModalRow() {
            let row = `
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-3">
                    <div>
                        <input type="text" name="department[]" placeholder="Department (e.g., IT, HR, Finance)"
                            class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]">
                    </div>
                    <div>
                        <input type="text" name="recipient_name[]" placeholder="Recipient Name"
                            class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]">
                    </div>
                    <div class="flex gap-2">
                        <input type="number" name="number_distributed[]" placeholder="Number of Copies" min="1" value="1"
                            class="flex-1 px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]">
                        <button type="button" onclick="removeModalRow(this)" class="px-2 text-[#9e9e9e] hover:text-[#dc2626]">
                            <i class="fa-regular fa-trash-can"></i>
                        </button>
                    </div>
                </div>
            `;

            document.getElementById("modalDistributionRows").insertAdjacentHTML("beforeend", row);
            showToast('New row added', 'info', 2000);
        }

        // Add multiple rows at once in modal
        function addModalBulkRows() {
            for (let i = 0; i < 5; i++) {
                addModalRow();
            }
            showToast('5 rows added', 'success', 2000);
        }

        // Remove a specific row in modal
        function removeModalRow(button) {
            const row = button.closest('.grid');
            if (row && document.querySelectorAll('#modalDistributionRows .grid').length > 1) {
                row.remove();
                showToast('Row removed', 'info', 2000);
            } else {
                showToast('You must keep at least one row', 'warning', 3000);
            }
        }

        // Validate form before submission
        function validateForm() {
            const documentId = document.getElementById('modalDocumentSelect').value;
            if (!documentId) {
                showToast('Please select a document', 'warning');
                return false;
            }

            const rows = document.querySelectorAll('#modalDistributionRows .grid');
            let hasValidRow = false;

            rows.forEach(row => {
                const dept = row.querySelector('input[name="department[]"]').value.trim();
                const recipient = row.querySelector('input[name="recipient_name[]"]').value.trim();
                const copies = row.querySelector('input[name="number_distributed[]"]').value;

                if (dept && recipient && copies > 0) {
                    hasValidRow = true;
                }
            });

            if (!hasValidRow) {
                showToast('Please fill in at least one valid distribution row', 'warning');
                return false;
            }

            return true;
        }

        // ========== DELETE MODAL FUNCTIONS ==========
        let currentDeleteId = null;

        function openDeleteModal(id, documentName, recipientName) {
            currentDeleteId = id;
            document.getElementById('deleteDocumentName').textContent = documentName;
            document.getElementById('deleteRecipientName').textContent = 'Recipient: ' + recipientName;

            // Build the delete URL with current query parameters
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
                        <p class="text-xs text-[#6e6e6e] uppercase mb-1">Department</p>
                        <p class="text-sm">${escapeHtml(data.department || '')}</p>
                    </div>
                    <div>
                        <p class="text-xs text-[#6e6e6e] uppercase mb-1">Recipient</p>
                        <p class="text-sm">${escapeHtml(data.recipient_name || '')}</p>
                    </div>
                    <div>
                        <p class="text-xs text-[#6e6e6e] uppercase mb-1">Copies Received</p>
                        <p class="text-sm font-mono">${data.number_received || 0}</p>
                    </div>
                    <div>
                        <p class="text-xs text-[#6e6e6e] uppercase mb-1">Copies Distributed</p>
                        <p class="text-sm font-mono">${data.number_distributed || 0}</p>
                    </div>
                    <div>
                        <p class="text-xs text-[#6e6e6e] uppercase mb-1">Date Distributed</p>
                        <p class="text-sm">${data.date_distributed ? new Date(data.date_distributed).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : ''}</p>
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
        document.getElementById('tableSearch')?.addEventListener('keyup', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = document.querySelectorAll('#tableBody tr');
            let visibleCount = 0;

            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                const matches = text.includes(searchTerm);
                row.style.display = matches ? '' : 'none';
                if (matches) visibleCount++;
            });

            const countEl = document.getElementById('visibleCount');
            if (countEl) countEl.textContent = visibleCount;

            if (visibleCount === 0) {
                showToast('No matching records found', 'info', 2000);
            }
        });

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

                // Check if numeric
                if (!isNaN(aCol) && !isNaN(bCol)) {
                    return sortDirection === 'asc' ?
                        parseFloat(aCol) - parseFloat(bCol) :
                        parseFloat(bCol) - parseFloat(aCol);
                }

                // String comparison
                const comparison = aCol.localeCompare(bCol);
                return sortDirection === 'asc' ? comparison : -comparison;
            });

            // Reorder table
            tbody.innerHTML = '';
            rows.forEach(row => tbody.appendChild(row));

            showToast(`Sorted by column ${columnIndex + 1} (${sortDirection})`, 'info', 1500);
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const distributionModal = document.getElementById('distributionModal');
            const viewModal = document.getElementById('viewModal');
            const deleteModal = document.getElementById('deleteModal');

            if (event.target == distributionModal) {
                closeDistributionModal();
            }
            if (event.target == viewModal) {
                closeViewModal();
            }
            if (event.target == deleteModal) {
                closeDeleteModal();
            }
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