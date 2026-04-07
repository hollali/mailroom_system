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

// Handle Delete Distribution
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];

    // Begin transaction
    $conn->begin_transaction();

    try {
        // Get distribution details before deleting
        $get_stmt = $conn->prepare("SELECT document_id FROM document_distribution WHERE id = ?");
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

            $conn->commit();
            $_SESSION['toast'] = [
                'type' => 'success',
                'message' => "Distribution record deleted successfully!"
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

// Get distribution records with document information
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

// Get comprehensive statistics
$stats = $conn->query("
    SELECT 
        COUNT(DISTINCT dd.id) as total_distributions,
        COUNT(DISTINCT d.id) as total_documents
    FROM documents d
    LEFT JOIN document_distribution dd ON d.id = dd.document_id
")->fetch_assoc();

$total_distributions = $stats['total_distributions'] ?? 0;

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
                    <h1 class="text-2xl font-medium text-[#1e1e1e]">Document Distribution History</h1>
                    <p class="text-sm text-[#6e6e6e] mt-1">Review document distributions recorded from the documents page</p>
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
                                    Date <i class="fa-solid fa-sort ml-1 text-[#9e9e9e]"></i>
                                </th>
                                <th class="p-3 cursor-pointer hover:bg-[#f0f0f0]" onclick="sortTable(3)">
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
                                        data-search="<?php echo strtolower(htmlspecialchars(trim(($row['document_name'] ?? '') . ' ' . ($row['document_type'] ?? '') . ' ' . ($row['date_distributed'] ?? '') . ' ' . ($row['created_at'] ?? '')))); ?>">
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
                                        <td class="p-3"><?php echo date('M j, Y', strtotime($row['date_distributed'])); ?></td>
                                        <td class="p-3 whitespace-nowrap"><?php echo formatTimestampDisplay($row['created_at'] ?? null); ?></td>
                                        <td class="p-3">
                                            <div class="flex gap-2">
                                                <button onclick="viewDistribution(<?php echo htmlspecialchars(json_encode($row)); ?>)"
                                                    class="action-btn" title="View Details">
                                                    <i class="fa-regular fa-eye"></i>
                                                </button>
                                                <button onclick="openDeleteModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars(addslashes($row['document_name'])); ?>')"
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
                                    <td colspan="5" class="p-8 text-center text-sm text-[#6e6e6e]">
                                        No distribution records found. Use the distribute action in documents to create one.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Table Footer with Record Count -->
                <div class="px-5 py-3 border-t border-[#e5e5e5] bg-[#fafafa] text-xs text-[#6e6e6e] flex justify-between items-center">
                    <span>Showing <span id="visibleCount"><?php echo $total_distributions; ?></span> records</span>
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
                </div>
                <p class="text-xs text-[#9e9e9e] mt-3">
                    <i class="fa-solid fa-circle-info mr-1"></i>
                    This will remove the history record and make the document available for redistribution again.
                </p>
            </div>

            <div class="flex justify-end gap-2 mt-6">
                <button onclick="closeDeleteModal()"
                    class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                    Cancel
                </button>
                <a href="#" id="confirmDeleteBtn"
                    class="px-4 py-2 text-sm bg-red-600 text-white rounded-md hover:bg-red-700">
                    Delete Record
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

        // ========== DELETE MODAL FUNCTIONS ==========
        let currentDeleteId = null;

        function openDeleteModal(id, documentName) {
            currentDeleteId = id;
            document.getElementById('deleteDocumentName').textContent = documentName;

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
                        <p class="text-xs text-[#6e6e6e] uppercase mb-1">Date Distributed</p>
                        <p class="text-sm">${data.date_distributed ? new Date(data.date_distributed).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : ''}</p>
                    </div>
                    <div>
                        <p class="text-xs text-[#6e6e6e] uppercase mb-1">Timestamp</p>
                        <p class="text-sm">${data.created_at ? new Date(data.created_at.replace(' ', 'T')).toLocaleString() : 'N/A'}</p>
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
            const viewModal = document.getElementById('viewModal');
            const deleteModal = document.getElementById('deleteModal');

            if (event.target == viewModal) closeViewModal();
            if (event.target == deleteModal) closeDeleteModal();
        }

        // ESC key to close modals
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeViewModal();
                closeDeleteModal();
            }
        });
    </script>
</body>

</html>
