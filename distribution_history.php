<?php
// distribution_history.php - View all distribution records
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once './config/db.php';
session_start();

// Helper function to format categories list for display
function formatCategoriesList($categories_list)
{
    if (empty($categories_list)) {
        return '—';
    }

    $categories = explode(', ', $categories_list);
    $html = '';
    foreach ($categories as $category) {
        $html .= '<span class="category-badge">' . htmlspecialchars(trim($category)) . '</span>';
    }
    return $html;
}

// Handle Delete Distribution
if (isset($_GET['delete_distribution'])) {
    $id = (int)$_GET['delete_distribution'];

    $stmt = $conn->prepare("DELETE FROM distribution WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        $_SESSION['toast'] = [
            'type' => 'success',
            'message' => 'Distribution record deleted successfully'
        ];
    } else {
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => 'Error deleting distribution record'
        ];
    }
    $stmt->close();

    header('Location: distribution_history.php');
    exit();
}

// AJAX: Get single distribution for view modal
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_distribution' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $id = (int)$_GET['id'];

    $stmt = $conn->prepare("SELECT * FROM distribution WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        echo json_encode(['success' => true, 'distribution' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Record not found']);
    }
    $stmt->close();
    exit();
}

// AJAX: Dismiss last distribution notification
if (isset($_POST['ajax']) && $_POST['ajax'] === 'dismiss_last_distribution') {
    unset($_SESSION['last_distribution']);
    echo json_encode(['success' => true]);
    exit();
}

// Pagination settings
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$department_filter = isset($_GET['department']) ? trim($_GET['department']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

// Build where clause
$where_clauses = [];
$params = [];
$types = "";

if (!empty($search)) {
    $where_clauses[] = "(distributed_to LIKE ? OR department LIKE ? OR distributed_by LIKE ? OR categories_list LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ssss";
}

if ($department_filter !== '') {
    $where_clauses[] = "department = ?";
    $params[] = $department_filter;
    $types .= "s";
}

if (!empty($date_from)) {
    $where_clauses[] = "date_distributed >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    $where_clauses[] = "date_distributed <= ?";
    $params[] = $date_to;
    $types .= "s";
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM distribution $where_sql";
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_distributions = $count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();

$total_pages = ceil($total_distributions / $limit);

// Get distribution records
$sql = "SELECT * FROM distribution $where_sql ORDER BY date_distributed DESC, id DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);

// Add limit and offset to params
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$distribution_history = $stmt->get_result();
$stmt->close();

$departments_result = $conn->query("SELECT DISTINCT department FROM distribution WHERE department IS NOT NULL AND department != '' ORDER BY department ASC");
$departments = [];
while ($department_row = $departments_result->fetch_assoc()) {
    $departments[] = $department_row['department'];
}

// Get last distribution notification
$last_distribution = $_SESSION['last_distribution'] ?? null;

// Get toast message
$toast = null;
if (isset($_SESSION['toast'])) {
    $toast = $_SESSION['toast'];
    unset($_SESSION['toast']);
}

include './sidebar.php';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Distribution History - Mailroom</title>
    <link rel="icon" type="image/png" href="./images/logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #f5f5f4;
            color: #1c1917;
        }

        .category-badge {
            display: inline-block;
            background: #f0f0f0;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
            margin: 2px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 12px 16px;
            background: #fafaf9;
            border-bottom: 1px solid #e5e5e5;
            font-weight: 500;
            font-size: 13px;
            color: #57534e;
        }

        td {
            padding: 12px 16px;
            border-bottom: 1px solid #e5e5e5;
            font-size: 14px;
            color: #1c1917;
        }

        tr:hover td {
            background: #fafaf9;
        }

        .action-btn {
            color: #9e9e9e;
            background: none;
            border: none;
            cursor: pointer;
            padding: 4px 8px;
            font-size: 14px;
        }

        .action-btn:hover {
            color: #1c1917;
        }

        .delete-btn:hover {
            color: #dc2626;
        }

        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.4);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .modal-content {
            background: white;
            max-width: 500px;
            width: 90%;
        }

        .modal-header {
            padding: 16px 20px;
            border-bottom: 1px solid #e5e5e5;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 16px;
            font-weight: 500;
        }

        .modal-body {
            padding: 20px;
        }

        .modal-footer {
            padding: 12px 20px;
            border-top: 1px solid #e5e5e5;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        .detail-row {
            display: flex;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .detail-label {
            width: 120px;
            font-weight: 500;
            font-size: 13px;
            color: #78716c;
        }

        .detail-value {
            flex: 1;
            font-size: 14px;
            color: #1c1917;
        }

        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 2000;
        }

        .toast {
            background: white;
            border: 1px solid #e5e5e5;
            padding: 10px 16px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 260px;
        }

        .toast-success {
            border-left: 3px solid #10b981;
        }

        .toast-error {
            border-left: 3px solid #ef4444;
        }

        .notification {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            padding: 12px 16px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .pagination {
            display: flex;
            gap: 4px;
            align-items: center;
        }

        .page-link {
            padding: 6px 12px;
            border: 1px solid #e5e5e5;
            background: white;
            font-size: 13px;
            text-decoration: none;
            color: #1c1917;
        }

        .page-link:hover {
            background: #fafaf9;
        }

        .page-link.active {
            background: #1c1917;
            color: white;
            border-color: #1c1917;
        }

        .filter-input {
            padding: 6px 10px;
            border: 1px solid #e5e5e5;
            font-size: 13px;
            background: white;
        }

        .btn-primary {
            background: #1c1917;
            color: white;
            padding: 6px 12px;
            border: none;
            font-size: 13px;
            cursor: pointer;
            display: inline-block;
            text-decoration: none;
        }

        .btn-primary:hover {
            background: #292524;
        }

        .btn-secondary {
            background: white;
            color: #1c1917;
            padding: 6px 12px;
            border: 1px solid #e5e5e5;
            font-size: 13px;
            cursor: pointer;
            display: inline-block;
            text-decoration: none;
        }

        .btn-secondary:hover {
            background: #fafaf9;
        }

        .btn-danger {
            background: #dc2626;
            color: white;
            padding: 6px 12px;
            border: none;
            font-size: 13px;
            cursor: pointer;
        }

        .btn-danger:hover {
            background: #b91c1c;
        }

        @media print {

            #toastContainer,
            #sidebar,
            #mobileMenuBtn,
            #sidebarOverlay,
            .modal,
            .notification,
            .no-print {
                display: none !important;
            }

            main {
                margin-left: 0 !important;
            }

            th:last-child,
            td:last-child {
                display: none !important;
            }
        }
    </style>
</head>

<body>
    <div id="toastContainer" class="toast-container"></div>

    <div class="flex">
        <?php include './sidebar.php'; ?>

        <main class="flex-1 ml-60">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-xl font-medium">Distribution History</h1>
                        <p class="text-sm text-gray-500 mt-1">View and manage distribution records</p>
                    </div>
                </div>

                <?php if ($last_distribution): ?>
                    <div class="notification" id="lastDistributionNotification">
                        <div>
                            <i class="fa-regular fa-circle-check text-green-600 mr-2"></i>
                            <span class="text-sm">
                                <?php echo $last_distribution['count']; ?> subscription(s) distributed to
                                <?php echo htmlspecialchars($last_distribution['individual']); ?>
                            </span>
                            <span class="text-xs text-gray-500 ml-2">
                                <?php echo date('M j, Y', strtotime($last_distribution['date'])); ?>
                            </span>
                        </div>
                        <button onclick="dismissLastDistribution()" class="text-gray-400 hover:text-gray-600">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                <?php endif; ?>

                <div class="bg-white border border-gray-200">
                    <div class="p-4 border-b border-gray-200 bg-gray-50 no-print">
                        <form method="GET" id="filterForm" class="flex flex-wrap gap-3 items-end">
                            <div class="flex-1 min-w-[180px]">
                                <label class="block text-xs text-gray-600 mb-1">Search</label>
                                <input type="text" id="searchInput" name="search" class="filter-input w-full"
                                    autocomplete="off"
                                    placeholder="Recipient, department, subscriptions..."
                                    value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="w-[180px]">
                                <label class="block text-xs text-gray-600 mb-1">Department</label>
                                <select id="departmentFilter" name="department" class="filter-input w-full">
                                    <option value="">All Departments</option>
                                    <?php foreach ($departments as $department): ?>
                                        <option value="<?php echo htmlspecialchars($department); ?>" <?php echo $department_filter === $department ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($department); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="w-[140px]">
                                <label class="block text-xs text-gray-600 mb-1">From Date</label>
                                <input type="date" name="date_from" class="filter-input w-full"
                                    value="<?php echo htmlspecialchars($date_from); ?>">
                            </div>
                            <div class="w-[140px]">
                                <label class="block text-xs text-gray-600 mb-1">To Date</label>
                                <input type="date" name="date_to" class="filter-input w-full"
                                    value="<?php echo htmlspecialchars($date_to); ?>">
                            </div>
                            <div>
                                <button type="submit" class="btn-primary">Filter</button>
                                <button type="button" onclick="printDistributionHistory()" class="btn-secondary ml-2 no-print">
                                    <i class="fa-solid fa-print mr-1"></i> Print
                                </button>
                                <a href="distribution_history.php" class="btn-secondary ml-2">Reset</a>
                            </div>
                        </form>
                    </div>

                    <div class="overflow-x-auto">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Date</th>
                                    <th>Recipient</th>
                                    <th>Department</th>
                                    <th>Subscriptions</th>
                                    <th>Count</th>
                                    <th>Distributed By</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($distribution_history && $distribution_history->num_rows > 0): ?>
                                    <?php while ($row = $distribution_history->fetch_assoc()): ?>
                                        <tr>
                                            <td class="text-gray-500"><?php echo $row['id']; ?></td>
                                            <td><?php echo date('M j, Y', strtotime($row['date_distributed'])); ?></td>
                                            <td class="font-medium"><?php echo htmlspecialchars($row['distributed_to']); ?></td>
                                            <td><?php echo htmlspecialchars($row['department'] ?? '—'); ?></td>
                                            <td><?php echo formatCategoriesList($row['categories_list']); ?></td>
                                            <td><?php echo (int)$row['copies']; ?></td>
                                            <td><?php echo htmlspecialchars($row['distributed_by'] ?? '—'); ?></td>
                                            <td class="whitespace-nowrap">
                                                <button onclick="viewDistribution(<?php echo $row['id']; ?>)"
                                                    class="action-btn" title="View">
                                                    <i class="fa-regular fa-eye"></i>
                                                </button>
                                                <button onclick="confirmDelete(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['distributed_to']); ?>')"
                                                    class="action-btn delete-btn" title="Delete">
                                                    <i class="fa-regular fa-trash-can"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-12 text-gray-500">
                                            <i class="fa-regular fa-inbox text-3xl mb-2 block"></i>
                                            <p>No distribution records found</p>
                                            <a href="newspaper_distribution.php" class="text-blue-600 hover:underline text-sm mt-2 inline-block">
                                                Start distributing
                                            </a>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($total_pages > 1): ?>
                        <div class="p-4 border-t border-gray-200 flex justify-between items-center">
                            <div class="text-sm text-gray-500">
                                Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $total_distributions); ?> of <?php echo $total_distributions; ?>
                            </div>
                            <div class="pagination">
                                <a href="?page=1<?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $department_filter ? '&department=' . urlencode($department_filter) : ''; ?><?php echo $date_from ? '&date_from=' . urlencode($date_from) : ''; ?><?php echo $date_to ? '&date_to=' . urlencode($date_to) : ''; ?>"
                                    class="page-link">First</a>
                                <a href="?page=<?php echo max(1, $page - 1); ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $department_filter ? '&department=' . urlencode($department_filter) : ''; ?><?php echo $date_from ? '&date_from=' . urlencode($date_from) : ''; ?><?php echo $date_to ? '&date_to=' . urlencode($date_to) : ''; ?>"
                                    class="page-link">Previous</a>

                                <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                for ($i = $start_page; $i <= $end_page; $i++):
                                ?>
                                    <a href="?page=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $department_filter ? '&department=' . urlencode($department_filter) : ''; ?><?php echo $date_from ? '&date_from=' . urlencode($date_from) : ''; ?><?php echo $date_to ? '&date_to=' . urlencode($date_to) : ''; ?>"
                                        class="page-link <?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                                <?php endfor; ?>

                                <a href="?page=<?php echo min($total_pages, $page + 1); ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $department_filter ? '&department=' . urlencode($department_filter) : ''; ?><?php echo $date_from ? '&date_from=' . urlencode($date_from) : ''; ?><?php echo $date_to ? '&date_to=' . urlencode($date_to) : ''; ?>"
                                    class="page-link">Next</a>
                                <a href="?page=<?php echo $total_pages; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $department_filter ? '&department=' . urlencode($department_filter) : ''; ?><?php echo $date_from ? '&date_from=' . urlencode($date_from) : ''; ?><?php echo $date_to ? '&date_to=' . urlencode($date_to) : ''; ?>"
                                    class="page-link">Last</a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- View Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Distribution Details</h3>
                <button onclick="closeModal('viewModal')" class="text-gray-400 hover:text-gray-600">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>
            <div class="modal-body" id="viewModalBody">
                <div class="text-center py-8">
                    <i class="fa-solid fa-spinner fa-spin text-gray-400"></i>
                    <p class="text-gray-500 mt-2">Loading...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button onclick="closeModal('viewModal')" class="btn-secondary">Close</button>
            </div>
        </div>
    </div>

    <!-- Delete Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Confirm Delete</h3>
                <button onclick="closeModal('deleteModal')" class="text-gray-400 hover:text-gray-600">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>
            <div class="modal-body">
                <p class="text-gray-700 mb-4">Delete this distribution record?</p>
                <div class="bg-red-50 border border-red-200 p-3">
                    <p class="font-medium text-red-800" id="deleteRecipientName"></p>
                    <p class="text-sm text-red-600 mt-1">This action cannot be undone.</p>
                </div>
            </div>
            <div class="modal-footer">
                <button onclick="closeModal('deleteModal')" class="btn-secondary">Cancel</button>
                <a href="#" id="confirmDeleteBtn" class="btn-danger">Delete</a>
            </div>
        </div>
    </div>

    <script>
        function showToast(type, message) {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.innerHTML = `
                <i class="fa-regular ${type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'}"></i>
                <span class="flex-1 text-sm">${message}</span>
                <button onclick="this.parentElement.remove()" class="text-gray-400 hover:text-gray-600">×</button>
            `;
            container.appendChild(toast);
            setTimeout(() => toast.remove(), 5000);
        }

        <?php if ($toast): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showToast('<?php echo $toast['type']; ?>', '<?php echo addslashes($toast['message']); ?>');
            });
        <?php endif; ?>

        function dismissLastDistribution() {
            const notification = document.getElementById('lastDistributionNotification');
            if (notification) notification.remove();

            fetch('distribution_history.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'ajax=dismiss_last_distribution'
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            const lastDistributionNotification = document.getElementById('lastDistributionNotification');
            if (lastDistributionNotification) {
                setTimeout(() => {
                    dismissLastDistribution();
                }, 3000);
            }

            const filterForm = document.getElementById('filterForm');
            const searchInput = document.getElementById('searchInput');
            const departmentFilter = document.getElementById('departmentFilter');

            if (filterForm && searchInput) {
                let searchDebounceTimer;

                searchInput.addEventListener('input', function() {
                    clearTimeout(searchDebounceTimer);
                    searchDebounceTimer = setTimeout(() => {
                        filterForm.submit();
                    }, 350);
                });
            }

            if (filterForm && departmentFilter) {
                departmentFilter.addEventListener('change', function() {
                    filterForm.submit();
                });
            }
        });

        function openModal(id) {
            document.getElementById(id).style.display = 'flex';
        }

        function closeModal(id) {
            document.getElementById(id).style.display = 'none';
        }

        function viewDistribution(id) {
            openModal('viewModal');

            fetch(`distribution_history.php?ajax=get_distribution&id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const dist = data.distribution;
                        const dateStr = new Date(dist.date_distributed).toLocaleDateString('en-US', {
                            year: 'numeric',
                            month: 'long',
                            day: 'numeric'
                        });

                        let categoriesHtml = '';
                        if (dist.categories_list) {
                            const categories = dist.categories_list.split(', ');
                            categories.forEach(cat => {
                                categoriesHtml += `<span class="category-badge">${escapeHtml(cat)}</span>`;
                            });
                        } else {
                            categoriesHtml = '—';
                        }

                        document.getElementById('viewModalBody').innerHTML = `
                            <div class="detail-row">
                                <div class="detail-label">ID</div>
                                <div class="detail-value">#${dist.id}</div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Date</div>
                                <div class="detail-value">${dateStr}</div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Recipient</div>
                                <div class="detail-value">${escapeHtml(dist.distributed_to)}</div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Department</div>
                                <div class="detail-value">${escapeHtml(dist.department || '—')}</div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Subscriptions</div>
                                <div class="detail-value">${categoriesHtml}</div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Total Subscriptions</div>
                                <div class="detail-value">${dist.copies}</div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Distributed By</div>
                                <div class="detail-value">${escapeHtml(dist.distributed_by || '—')}</div>
                            </div>
                        `;
                    } else {
                        document.getElementById('viewModalBody').innerHTML = `
                            <div class="text-center py-8">
                                <i class="fa-regular fa-circle-exclamation text-red-500 text-3xl mb-2 block"></i>
                                <p class="text-gray-500">${data.message || 'Record not found'}</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    document.getElementById('viewModalBody').innerHTML = `
                        <div class="text-center py-8">
                            <i class="fa-regular fa-circle-exclamation text-red-500 text-3xl mb-2 block"></i>
                            <p class="text-gray-500">Error loading details</p>
                        </div>
                    `;
                });
        }

        let deleteId = null;

        function confirmDelete(id, recipientName) {
            deleteId = id;
            document.getElementById('deleteRecipientName').innerHTML = `<i class="fa-regular fa-user mr-2"></i> ${escapeHtml(recipientName)}`;
            document.getElementById('confirmDeleteBtn').href = `?delete_distribution=${id}`;
            openModal('deleteModal');
        }

        function escapeHtml(text) {
            if (!text) return '—';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function printDistributionHistory() {
            window.print();
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal').forEach(modal => {
                    modal.style.display = 'none';
                });
            }
        });
    </script>
</body>

</html>
