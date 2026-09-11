<?php
// distribution_history.php - View all distribution records

require_once './config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/audit.php';
session_start();

// Handle Delete Distribution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_distribution'])) {
    csrf_check_post();
    $id = (int)$_POST['delete_distribution'];

    $conn->begin_transaction();

    try {
        // 1. Get newspaper IDs associated with this distribution to restore stock
        $stmt = $conn->prepare("SELECT newspaper_id, newspaper_ids FROM distribution WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $dist = $result->fetch_assoc();
        $stmt->close();

        if ($dist) {
            // Collect all unique newspaper IDs to restore
            $ids_to_restore = [];
            
            // Handle multiple IDs from newspaper_ids column
            if (!empty($dist['newspaper_ids'])) {
                $ids = explode(',', $dist['newspaper_ids']);
                foreach ($ids as $pid) {
                    $pid = (int)trim($pid);
                    if ($pid > 0) $ids_to_restore[$pid] = true;
                }
            }
            
            // Handle legacy single newspaper_id
            if (!empty($dist['newspaper_id'])) {
                $ids_to_restore[(int)$dist['newspaper_id']] = true;
            }

            // Restore stock and update status for each ID
            foreach (array_keys($ids_to_restore) as $pid) {
                // Increment available_copies and update status
                $conn->query("UPDATE newspapers SET 
                    available_copies = available_copies + 1,
                    status = CASE 
                        WHEN (available_copies + 1) >= total_copies THEN 'available'
                        ELSE 'partial'
                    END
                    WHERE id = $pid");
            }
        }

        // 2. Delete the distribution record
        $stmt = $conn->prepare("DELETE FROM distribution WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();

        audit_log('delete', 'distribution', $id, "Deleted newspaper distribution #$id to '{$dist['distributed_to']}'", 'System');

        $_SESSION['toast'] = [
            'type' => 'success',
            'message' => 'Distribution record deleted and stock restored.'
        ];
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => 'Error: ' . $e->getMessage()
        ];
    }

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
    csrf_check_post();
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

$has_active_filters = $search !== '' || $department_filter !== ''
    || $date_from !== '' || $date_to !== '';

// Build where clause
$where_clauses = [];
$params = [];
$types = "";

if (!empty($search)) {
    $where_clauses[] = "(CONCAT('DIST-', DATE_FORMAT(date_distributed, '%Y%m%d'), '-', LPAD(id, 4, '0')) LIKE ? OR distributed_to LIKE ? OR department LIKE ? OR distributed_by LIKE ? OR categories_list LIKE ? OR newspapers_list LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ssssss";
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

$total_pages = $total_distributions > 0 ? ceil($total_distributions / $limit) : 1;

// Get all distribution records for export (matching filters, without pagination limit)
$all_distributions_export = [];
$export_sql = "SELECT * FROM distribution $where_sql ORDER BY date_distributed DESC, id DESC";
$export_stmt = $conn->prepare($export_sql);
if (!empty($params)) {
    $export_stmt->bind_param($types, ...$params);
}
if ($export_stmt->execute()) {
    $export_res = $export_stmt->get_result();
    while ($row = $export_res->fetch_assoc()) {
        $all_distributions_export[] = [
            'reference' => 'DIST-' . date('Ymd', strtotime($row['date_distributed'])) . '-' . str_pad((string)$row['id'], 4, '0', STR_PAD_LEFT),
            'date_distributed' => $row['date_distributed'],
            'distributed_to' => $row['distributed_to'],
            'department' => $row['department'] ?? '',
            'copies' => $row['copies'],
            'distributed_by' => $row['distributed_by'] ?? '',
            'newspapers' => $row['newspapers_list'] ?? $row['categories_list'] ?? ''
        ];
    }
}
$export_stmt->close();

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

// ─── Summary statistics ───────────────────────────────────────────────────────
$summary = $conn->query("
    SELECT
        COUNT(id)                           AS total_distributions,
        COALESCE(SUM(copies), 0)            AS total_copies,
        COUNT(DISTINCT distributed_to)       AS unique_recipients
    FROM distribution
")->fetch_assoc();

// ─── Build a pagination URL preserving current filters ────────────────────────
function buildDistHistUrl($overrides = [])
{
    $params = $_GET;
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    return 'distribution_history.php' . (!empty($params) ? '?' . http_build_query($params) : '');
}

// Get toast message
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
    <title>Distribution History - Mailroom</title>
    <meta name="csrf-token" content="<?php echo csrf_token(); ?>">
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
                        <span>Newspaper Distribution History</span>
                    </div>
                    <h1 class="page-header-title">Distribution History</h1>
                    <p class="page-header-subtitle">View and manage newspaper distribution records.</p>
                </div>
                <div class="header-actions flex items-center gap-2 no-print">
                    <button type="button" onclick="printDistributionHistory()" class="btn btn-soft">
                        <i class="fa-solid fa-print"></i>
                        <span class="hidden sm:inline">Print</span>
                    </button>
                    <button type="button" onclick="exportDistributionHistory()" class="btn btn-soft">
                        <i class="fa-regular fa-file-excel"></i>
                        <span class="hidden sm:inline">Export CSV</span>
                    </button>
                    <a href="newspaper_distribution.php" class="btn btn-primary">
                        <i class="fa-solid fa-plus"></i>
                        <span class="hidden sm:inline">New Distribution</span>
                    </a>
                </div>
            </div>

            <div class="page-body">
                <?php if ($toast): ?>
                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            MailroomToast.<?php echo $toast['type']; ?>(<?php echo json_encode($toast['message']); ?>);
                        });
                    </script>
                <?php endif; ?>

                <?php if ($last_distribution): ?>
                    <div class="alert alert-green no-print" id="lastDistributionNotification" style="justify-content:space-between;align-items:center;">
                        <div class="flex items-center gap-2">
                            <i class="fa-regular fa-circle-check"></i>
                            <span style="font-size:13px;font-weight:500;">
                                <?php echo $last_distribution['count']; ?> subscription(s) distributed to
                                <?php echo htmlspecialchars($last_distribution['individual']); ?>
                            </span>
                            <span style="font-size:11px;opacity:.75;margin-left:6px;">
                                <?php echo date('M j, Y', strtotime($last_distribution['date'])); ?>
                            </span>
                        </div>
                        <button onclick="dismissLastDistribution()" style="background:none;border:none;cursor:pointer;color:inherit;font-size:14px;line-height:1;padding:2px;">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                <?php endif; ?>

                <div class="print-only mb-5">
                    <h1 class="text-xl font-semibold text-[#1b2a4a]">Newspaper Distribution History Statement</h1>
                    <p class="text-sm text-[#4b5570] mt-1">Generated on <?php echo date('F j, Y g:i A'); ?></p>
                    <p class="text-sm text-[#4b5570]">Total records: <?php echo number_format($summary['total_distributions']); ?> | Total copies distributed: <?php echo number_format($summary['total_copies']); ?></p>
                </div>

                <!-- Stat Cards -->
                <div class="stat-grid mb-6">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fa-regular fa-rectangle-list"></i></div>
                        <div class="stat-label">Total Distributions</div>
                        <div class="stat-value"><?php echo number_format($summary['total_distributions']); ?></div>
                        <div class="stat-hint">Distribution records</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon gold"><i class="fa-solid fa-copy"></i></div>
                        <div class="stat-label">Subscriptions Distributed</div>
                        <div class="stat-value"><?php echo number_format($summary['total_copies']); ?></div>
                        <div class="stat-hint">Total copies issued</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fa-regular fa-user"></i></div>
                        <div class="stat-label">Unique Recipients</div>
                        <div class="stat-value"><?php echo number_format($summary['unique_recipients']); ?></div>
                        <div class="stat-hint">Recipients on record</div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card mb-6 no-print">
                    <div class="card-body">
                        <form method="GET" id="filterForm" class="filter-bar" style="margin-bottom:0;">
                            <div class="search-wrap">
                                <i class="fa-solid fa-magnifying-glass icon"></i>
                                <input type="text" id="searchInput" name="search"
                                    class="input" autocomplete="off"
                                    placeholder="Reference, recipient, department, subscriptions..."
                                    value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <select id="departmentFilter" name="department" class="select">
                                <option value="">All Departments</option>
                                <?php foreach ($departments as $department): ?>
                                    <option value="<?php echo htmlspecialchars($department); ?>" <?php echo $department_filter === $department ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($department); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="date" name="date_from" class="input" value="<?php echo htmlspecialchars($date_from); ?>">
                            <input type="date" name="date_to" class="input" value="<?php echo htmlspecialchars($date_to); ?>">
                            <button type="submit" class="btn btn-primary">
                                <i class="fa-solid fa-filter"></i> Filter
                            </button>
                            <a href="distribution_history.php" class="btn btn-soft">
                                <i class="fa-solid fa-rotate-left"></i> Reset
                            </a>
                        </form>
                    </div>
                </div>

                <!-- Records Table -->
                <div class="card">
                    <div class="card-header" style="padding:14px 20px;">
                        <div>
                            <div class="card-title">Distribution Records</div>
                            <div class="card-subtitle">
                                <?php if ($total_distributions > 0): ?>
                                    Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $limit, $total_distributions); ?> of <?php echo number_format($total_distributions); ?>
                                <?php else: ?>
                                    No records to display
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Reference No.</th>
                                    <th>Date</th>
                                    <th>Recipient</th>
                                    <th class="hidden md:table-cell">Department</th>
                                    <th>Count</th>
                                    <th class="hidden md:table-cell">Distributed By</th>
                                    <th class="no-print text-right" style="width:92px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($distribution_history && $distribution_history->num_rows > 0): ?>
                                    <?php while ($row = $distribution_history->fetch_assoc()): ?>
                                        <?php $distribution_reference = generateDistributionReference($row['id'], $row['date_distributed']); ?>
                                        <tr>
                                            <td><span class="pill table-cell-mono"><?php echo htmlspecialchars($distribution_reference); ?></span></td>
                                            <td><?php echo date('M j, Y', strtotime($row['date_distributed'])); ?></td>
                                            <td><span class="table-cell-title"><?php echo htmlspecialchars($row['distributed_to']); ?></span></td>
                                            <td class="hidden md:table-cell"><?php echo htmlspecialchars($row['department'] ?? '—'); ?></td>
                                            <td><span class="table-cell-mono"><?php echo (int)$row['copies']; ?></span></td>
                                            <td class="hidden md:table-cell table-cell-subtitle"><?php echo htmlspecialchars($row['distributed_by'] ?? '—'); ?></td>
                                            <td class="no-print">
                                                <div class="row-actions" style="justify-content:flex-end;">
                                                    <a href="#" onclick="MailroomReceipt.open('newsdist', <?php echo $row['id']; ?>); return false;" class="icon-btn" title="Print slip">
                                                        <i class="fa-solid fa-print"></i>
                                                    </a>
                                                    <button class="icon-btn primary" onclick="viewDistribution(<?php echo $row['id']; ?>)" title="View">
                                                        <i class="fa-regular fa-eye"></i>
                                                    </button>
                                                    <button class="icon-btn danger"
                                                        onclick="confirmDelete(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars(addslashes($row['distributed_to'])); ?>')"
                                                        title="Delete">
                                                        <i class="fa-regular fa-trash-can"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7">
                                            <div class="empty-state">
                                                <div class="empty-state-icon"><i class="fa-regular fa-inbox"></i></div>
                                                <div class="empty-state-title">No distribution records found</div>
                                                <div class="empty-state-text">
                                                    <?php if ($has_active_filters): ?>
                                                        Try adjusting your search or filter criteria.
                                                    <?php else: ?>
                                                        Newspaper distributions will appear here once created.
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($has_active_filters): ?>
                                                    <a href="distribution_history.php" class="btn btn-soft btn-sm" style="margin-top:16px;">Clear filters</a>
                                                <?php else: ?>
                                                    <a href="newspaper_distribution.php" class="btn btn-primary btn-sm" style="margin-top:16px;">
                                                        <i class="fa-solid fa-plus"></i> New Distribution
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Table Footer -->
                    <div class="pagination-shell no-print">
                        <div class="pagination-meta"><?php echo number_format($total_distributions); ?> total record(s)</div>
                        <div class="pagination-meta">Total subscriptions distributed: <strong><?php echo number_format($summary['total_copies']); ?></strong></div>
                    </div>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination-shell mt-4 no-print">
                        <div class="pagination-meta">
                            <div class="pagination-title">Showing <?php echo min($limit, $total_distributions - ($page - 1) * $limit); ?> record(s) on this page</div>
                            <span>Records <?php echo ($page - 1) * $limit + 1; ?>–<?php echo min($page * $limit, $total_distributions); ?> of <?php echo number_format($total_distributions); ?> total</span>
                        </div>
                        <div class="pagination-controls">
                            <div class="pagination-page-indicator">Page <?php echo $page; ?> of <?php echo $total_pages; ?></div>
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                    <a href="<?php echo htmlspecialchars(buildDistHistUrl(['page' => 1])); ?>" class="pagination-item compact" aria-label="First page">
                                        <i class="fa-solid fa-chevrons-left"></i>
                                    </a>
                                    <a href="<?php echo htmlspecialchars(buildDistHistUrl(['page' => $page - 1])); ?>" class="pagination-item compact" aria-label="Previous page">
                                        <i class="fa-solid fa-chevron-left"></i>
                                    </a>
                                <?php endif; ?>

                                <?php
                                $start = max(1, $page - 2);
                                $end = min($total_pages, $page + 2);

                                if ($start > 1) {
                                    echo '<a href="' . htmlspecialchars(buildDistHistUrl(['page' => 1])) . '" class="pagination-item">1</a>';
                                    if ($start > 2) {
                                        echo '<span class="pagination-ellipsis">...</span>';
                                    }
                                }

                                for ($i = $start; $i <= $end; $i++) {
                                    $active_class = ($i == $page) ? 'active' : '';
                                    echo '<a href="' . htmlspecialchars(buildDistHistUrl(['page' => $i])) . '" class="pagination-item ' . $active_class . '">' . $i . '</a>';
                                }

                                if ($end < $total_pages) {
                                    if ($end < $total_pages - 1) {
                                        echo '<span class="pagination-ellipsis">...</span>';
                                    }
                                    echo '<a href="' . htmlspecialchars(buildDistHistUrl(['page' => $total_pages])) . '" class="pagination-item">' . $total_pages . '</a>';
                                }
                                ?>

                                <?php if ($page < $total_pages): ?>
                                    <a href="<?php echo htmlspecialchars(buildDistHistUrl(['page' => $page + 1])); ?>" class="pagination-item compact" aria-label="Next page">
                                        <i class="fa-solid fa-chevron-right"></i>
                                    </a>
                                    <a href="<?php echo htmlspecialchars(buildDistHistUrl(['page' => $total_pages])); ?>" class="pagination-item compact" aria-label="Last page">
                                        <i class="fa-solid fa-chevrons-right"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- View Modal -->
    <div id="viewModal" class="modal-backdrop" style="display:none;">
        <div class="modal-dialog lg">
            <div class="modal-header">
                <h2 class="modal-title">Distribution Details</h2>
                <button type="button" onclick="MailroomModal.close('viewModal')" class="modal-close"><i class="fa-solid fa-xmark text-xl"></i></button>
            </div>
            <div class="modal-body">
                <div id="viewModalBody">
                    <div class="text-center py-10">
                        <i class="fa-solid fa-spinner fa-spin text-[#9aa0b5] text-2xl"></i>
                        <p class="text-[#7d8398] mt-3 text-sm">Loading...</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button onclick="MailroomModal.close('viewModal')" class="btn btn-soft">Close</button>
            </div>
        </div>
    </div>

    <!-- Delete Modal -->
    <div id="deleteModal" class="modal-backdrop" style="display:none;">
        <div class="modal-dialog sm">
            <div class="modal-header">
                <h2 class="modal-title">Confirm Delete</h2>
                <button type="button" onclick="MailroomModal.close('deleteModal')" class="modal-close"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <p style="color:var(--text-secondary);font-size:13px;margin-bottom:14px;">Are you sure you want to delete this distribution record?</p>
                <div class="alert alert-red" style="margin-bottom:0;">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div>
                        <p class="font-medium text-sm" id="deleteRecipientName"></p>
                        <p class="text-xs mt-2" style="opacity:.85;">Newspaper stock will be restored and the record removed. This action cannot be undone.</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button onclick="MailroomModal.close('deleteModal')" class="btn btn-soft">Cancel</button>
                <button id="confirmDeleteBtn" class="btn btn-danger">
                    <i class="fa-regular fa-trash-can"></i> Delete Record
                </button>
            </div>
        </div>
    </div>

    <script>
        function escapeHtml(text) {
            if (text === null || text === undefined) return '—';
            const div = document.createElement('div');
            div.textContent = String(text);
            return div.innerHTML;
        }

        <?php if ($toast): ?>
            document.addEventListener('DOMContentLoaded', function() {
                MailroomToast.<?php echo $toast['type']; ?>(<?php echo json_encode($toast['message']); ?>);
            });
        <?php endif; ?>

        function dismissLastDistribution() {
            const notification = document.getElementById('lastDistributionNotification');
            if (notification) notification.remove();

            const formData = new FormData();
            formData.append('ajax', 'dismiss_last_distribution');
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);

            fetch('distribution_history.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
                },
                body: formData
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            const lastDistributionNotification = document.getElementById('lastDistributionNotification');
            if (lastDistributionNotification) {
                setTimeout(() => {
                    dismissLastDistribution();
                }, 8000);
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
                    }, 380);
                });
            }

            if (filterForm && departmentFilter) {
                departmentFilter.addEventListener('change', function() {
                    filterForm.submit();
                });
            }
        });

        // ── View Distribution ─────────────────────────────────────────────────
        function viewDistribution(id) {
            MailroomModal.open('viewModal');
            document.getElementById('viewModalBody').innerHTML = `
                <div class="text-center py-10">
                    <i class="fa-solid fa-spinner fa-spin text-[#9aa0b5] text-2xl"></i>
                    <p class="text-[#7d8398] mt-3 text-sm">Loading...</p>
                </div>`;

            fetch(`distribution_history.php?ajax=get_distribution&id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const dist = data.distribution;
                        const dateStr = dist.date_distributed ?
                            new Date(dist.date_distributed).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
                        const distributionRef = `DIST-${String(dist.date_distributed).replace(/-/g, '').slice(0, 8)}-${String(dist.id).padStart(4, '0')}`;

                        let categoriesHtml = '';
                        const activeList = dist.newspapers_list || dist.categories_list;
                        if (activeList) {
                            const categories = String(activeList).split(', ');
                            categories.forEach(cat => {
                                categoriesHtml += `<span class="pill">${escapeHtml(cat)}</span> `;
                            });
                        } else {
                            categoriesHtml = '—';
                        }

                        document.getElementById('viewModalBody').innerHTML = `
                            <div class="grid grid-cols-2 gap-3">
                                <div class="col-span-2">
                                    <p style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:4px;">Reference</p>
                                    <p><span class="pill table-cell-mono">${escapeHtml(distributionRef)}</span></p>
                                </div>
                                <div>
                                    <p style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:4px;">Date</p>
                                    <p class="text-sm" style="color:var(--text-secondary);">${escapeHtml(dateStr)}</p>
                                </div>
                                <div>
                                    <p style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:4px;">Distributed By</p>
                                    <p class="text-sm" style="color:var(--text-secondary);">${escapeHtml(dist.distributed_by || '—')}</p>
                                </div>
                                <div class="col-span-2">
                                    <p style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:4px;">Recipient</p>
                                    <p class="text-sm font-medium" style="color:var(--text);">${escapeHtml(dist.distributed_to)}</p>
                                </div>
                                <div>
                                    <p style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:4px;">Department</p>
                                    <p class="text-sm" style="color:var(--text-secondary);">${escapeHtml(dist.department || '—')}</p>
                                </div>
                                <div>
                                    <p style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:4px;">Total Subscriptions</p>
                                    <p class="text-sm font-mono" style="color:var(--text);">${Number(dist.copies) || 0}</p>
                                </div>
                                <div class="col-span-2">
                                    <p style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:4px;">Subscriptions</p>
                                    <p class="text-sm" style="color:var(--text-secondary);">${categoriesHtml}</p>
                                </div>
                            </div>
                        `;
                    } else {
                        document.getElementById('viewModalBody').innerHTML = `
                            <div class="text-center py-10 text-[#7d8398]">
                                <i class="fa-regular fa-circle-exclamation text-red-400 text-3xl mb-3 block"></i>
                                <p>${escapeHtml(data.message || 'Record not found')}</p>
                            </div>
                        `;
                    }
                })
                .catch(() => {
                    document.getElementById('viewModalBody').innerHTML = `
                        <div class="text-center py-10 text-[#7d8398]">
                            <i class="fa-regular fa-circle-exclamation text-red-400 text-3xl mb-3 block"></i>
                            <p>Error loading details</p>
                        </div>
                    `;
                });
        }

        // ── Delete ─────────────────────────────────────────────────────────────
        function confirmDelete(id, recipientName) {
            document.getElementById('deleteRecipientName').innerHTML =
                `<i class="fa-regular fa-user mr-2"></i> ${escapeHtml(recipientName)}`;
            document.getElementById('confirmDeleteBtn').onclick = function(e) {
                e.preventDefault();
                submitPostForm('distribution_history.php', { delete_distribution: id });
            };
            MailroomModal.open('deleteModal');
        }

        function printDistributionHistory() {
            window.print();
        }

        // Export distribution history to CSV
        function exportDistributionHistory() {
            const data = <?php echo json_encode($all_distributions_export); ?>;
            if (!data || data.length === 0) {
                MailroomToast.info('No records to export.');
                return;
            }
            exportToCSV(data, 'distribution_history_' + new Date().toISOString().split('T')[0] + '.csv');
            MailroomToast.success('Export completed successfully!');
        }
    </script>
    <script src="assets/app.js"></script>
</body>

</html>