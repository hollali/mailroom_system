<?php
// distribution_history.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once './config/db.php';
session_start();

// ─── Helper: resolve newspapers_list for a distribution row ──────────────────
function resolveNewspapersList($conn, $row)
{
    // Already stored — use it
    if (!empty($row['newspapers_list'])) {
        return $row['newspapers_list'];
    }

    // Comma-separated newspaper_ids column
    if (!empty($row['newspaper_ids'])) {
        $ids = array_filter(array_map('intval', explode(',', $row['newspaper_ids'])));
        if (!empty($ids)) {
            $ids_sql = implode(',', $ids);
            $np = $conn->query("SELECT newspaper_name, newspaper_number FROM newspapers WHERE id IN ($ids_sql)");
            $papers = [];
            while ($n = $np->fetch_assoc()) {
                $papers[] = $n['newspaper_name'] . ' - Issue: ' . $n['newspaper_number'];
            }
            return implode('|', $papers);
        }
    }

    // Legacy single newspaper_id
    if (!empty($row['newspaper_id'])) {
        $nid = (int)$row['newspaper_id'];
        $np = $conn->query("SELECT newspaper_name, newspaper_number FROM newspapers WHERE id = $nid");
        if ($np && $n = $np->fetch_assoc()) {
            return $n['newspaper_name'] . ' - Issue: ' . $n['newspaper_number'];
        }
    }

    return '';
}

// ─── Handle Edit Distribution ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_distribution_submit'])) {
    $id             = (int)$_POST['edit_id'];
    $individual_name = trim($_POST['edit_individual_name']);
    $department     = trim($_POST['edit_department']);
    $copies         = (int)$_POST['edit_copies'];

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT newspaper_ids, newspaper_id, copies FROM distribution WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $current = $stmt->get_result()->fetch_assoc();

        if ($current) {
            $old_copies      = $current['copies'];
            $copy_difference = $copies - $old_copies;

            // Determine which newspaper IDs to adjust
            if (!empty($current['newspaper_ids'])) {
                $newspaper_ids = array_filter(array_map('intval', explode(',', $current['newspaper_ids'])));
            } elseif (!empty($current['newspaper_id'])) {
                $newspaper_ids = [(int)$current['newspaper_id']];
            } else {
                $newspaper_ids = [];
            }

            if ($copy_difference != 0 && !empty($newspaper_ids)) {
                $count       = count($newspaper_ids);
                $per_paper   = (int)floor(abs($copy_difference) / $count);
                $remainder   = abs($copy_difference) % $count;

                foreach (array_values($newspaper_ids) as $index => $nid) {
                    $adjustment = $per_paper + ($index < $remainder ? 1 : 0);
                    if ($adjustment === 0) continue;

                    if ($copy_difference > 0) {
                        $conn->query("UPDATE newspapers SET available_copies = available_copies - $adjustment WHERE id = $nid");
                    } else {
                        $conn->query("UPDATE newspapers SET available_copies = available_copies + $adjustment WHERE id = $nid");
                    }

                    $check = $conn->query("SELECT available_copies FROM newspapers WHERE id = $nid");
                    $paper = $check->fetch_assoc();
                    $status = ($paper['available_copies'] > 0) ? 'available' : 'distributed';
                    $conn->query("UPDATE newspapers SET status = '$status' WHERE id = $nid");
                }
            }

            $stmt2 = $conn->prepare("UPDATE distribution SET distributed_to = ?, department = ?, copies = ? WHERE id = ?");
            $stmt2->bind_param("ssii", $individual_name, $department, $copies, $id);
            $stmt2->execute();

            $conn->commit();
            $_SESSION['toast'] = ['type' => 'success', 'message' => 'Distribution updated successfully'];
        }
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['toast'] = ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()];
    }

    header('Location: distribution_history.php');
    exit();
}

// ─── Handle Delete Distribution ───────────────────────────────────────────────
if (isset($_GET['delete_distribution'])) {
    $id = (int)$_GET['delete_distribution'];

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT newspaper_ids, newspaper_id, copies FROM distribution WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $distribution = $stmt->get_result()->fetch_assoc();

        if ($distribution) {
            $del = $conn->prepare("DELETE FROM distribution WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();

            // Determine IDs to restore
            if (!empty($distribution['newspaper_ids'])) {
                $newspaper_ids = array_filter(array_map('intval', explode(',', $distribution['newspaper_ids'])));
            } elseif (!empty($distribution['newspaper_id'])) {
                $newspaper_ids = [(int)$distribution['newspaper_id']];
            } else {
                $newspaper_ids = [];
            }

            foreach ($newspaper_ids as $nid) {
                $conn->query("UPDATE newspapers SET available_copies = available_copies + 1 WHERE id = $nid");
                $check  = $conn->query("SELECT available_copies FROM newspapers WHERE id = $nid");
                $paper  = $check->fetch_assoc();
                $status = ($paper['available_copies'] > 0) ? 'available' : 'distributed';
                $conn->query("UPDATE newspapers SET status = '$status' WHERE id = $nid");
            }

            $conn->commit();
            $_SESSION['toast'] = ['type' => 'success', 'message' => 'Record deleted successfully'];
        }
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['toast'] = ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()];
    }

    $query_params = $_GET;
    unset($query_params['delete_distribution']);
    $redirect_url = 'distribution_history.php' . (!empty($query_params) ? '?' . http_build_query($query_params) : '');
    header('Location: ' . $redirect_url);
    exit();
}

// ─── AJAX: Get single distribution ───────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_distribution' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $id = (int)$_GET['id'];

    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid ID']);
        exit();
    }

    $stmt = $conn->prepare("SELECT * FROM distribution WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $row = $result->fetch_assoc()) {
        $row['newspapers_list'] = resolveNewspapersList($conn, $row);
        echo json_encode(['success' => true, 'distribution' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Record not found']);
    }
    exit();
}

// ─── AJAX: Dismiss last distribution notification ─────────────────────────────
if (isset($_POST['ajax']) && $_POST['ajax'] === 'dismiss_last_distribution') {
    unset($_SESSION['last_distribution']);
    echo json_encode(['success' => true]);
    exit();
}

// ─── Page setup ───────────────────────────────────────────────────────────────
$categories     = $conn->query("SELECT * FROM newspaper_categories ORDER BY category_name");
$all_categories = [];
while ($cat = $categories->fetch_assoc()) {
    $all_categories[] = $cat;
}

$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 10;
$offset = ($page - 1) * $limit;

$search          = trim($_GET['search']          ?? '');
$filter_category = (int)($_GET['filter_category'] ?? 0);
$date_from       = $_GET['date_from'] ?? '';
$date_to         = $_GET['date_to']   ?? '';
$sort_by         = $_GET['sort_by']   ?? 'date_distributed';
$sort_order      = strtoupper($_GET['sort_order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

// Whitelist sort columns to prevent SQL injection
$allowed_sorts = ['date_distributed', 'distributed_to', 'department', 'distributed_by', 'copies'];
if (!in_array($sort_by, $allowed_sorts)) {
    $sort_by = 'date_distributed';
}

$where_clauses = [];

if ($search !== '') {
    $safe_search = $conn->real_escape_string($search);
    $where_clauses[] = "(d.distributed_to LIKE '%$safe_search%' OR d.department LIKE '%$safe_search%' OR d.distributed_by LIKE '%$safe_search%')";
}

if ($filter_category > 0) {
    $where_clauses[] = "EXISTS (
        SELECT 1 FROM newspapers n
        WHERE FIND_IN_SET(n.id, d.newspaper_ids) AND n.category_id = $filter_category
    )";
}

if ($date_from !== '') {
    $safe_from = $conn->real_escape_string($date_from);
    $where_clauses[] = "d.date_distributed >= '$safe_from'";
}

if ($date_to !== '') {
    $safe_to = $conn->real_escape_string($date_to);
    $where_clauses[] = "d.date_distributed <= '$safe_to'";
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

$count_result        = $conn->query("SELECT COUNT(*) as total FROM distribution d $where_sql");
$total_distributions = (int)($count_result->fetch_assoc()['total'] ?? 0);
$total_pages         = (int)ceil($total_distributions / $limit);

$distribution_history = $conn->query("
    SELECT d.*
    FROM distribution d
    $where_sql
    ORDER BY d.$sort_by $sort_order
    LIMIT $offset, $limit
");

$last_distribution = $_SESSION['last_distribution'] ?? null;

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
    <title>Distribution History</title>
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

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 12px 16px;
            border-bottom: 1px solid #e5e5e5;
            font-weight: 500;
            color: #57534e;
            font-size: 13px;
            background: #fafaf9;
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

        .btn-primary {
            background: #1c1917;
            color: white;
            padding: 8px 16px;
            border-radius: 8px;
            border: none;
            font-size: 14px;
            cursor: pointer;
            display: inline-block;
            text-decoration: none;
        }

        .btn-primary:hover {
            background: #292524;
        }

        .btn-danger {
            background: #dc2626;
            color: white;
            padding: 8px 16px;
            border-radius: 8px;
            border: none;
            font-size: 14px;
            cursor: pointer;
            display: inline-block;
            text-decoration: none;
        }

        .btn-danger:hover {
            background: #b91c1c;
        }

        .btn-secondary {
            background: white;
            color: #1c1917;
            padding: 8px 16px;
            border-radius: 8px;
            border: 1px solid #e5e5e5;
            font-size: 14px;
            cursor: pointer;
            display: inline-block;
            text-decoration: none;
        }

        .btn-secondary:hover {
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

        .sort-link {
            color: #57534e;
            text-decoration: none;
        }

        .sort-link:hover {
            color: #1c1917;
        }

        .sort-link.active {
            color: #1c1917;
            font-weight: 600;
        }

        .pagination {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .page-link {
            padding: 8px 12px;
            border: 1px solid #e5e5e5;
            background: white;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
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
            border-radius: 12px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid #e5e5e5;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 18px;
            font-weight: 500;
        }

        .modal-body {
            padding: 24px;
        }

        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #e5e5e5;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 6px;
            color: #57534e;
        }

        .form-input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #e5e5e5;
            border-radius: 6px;
            font-size: 14px;
        }

        .form-input:focus {
            outline: none;
            border-color: #1c1917;
        }

        .filter-bar {
            padding: 16px 20px;
            background: #fafaf9;
            border-bottom: 1px solid #e5e5e5;
        }

        .filter-group {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: flex-end;
        }

        .filter-field {
            flex: 1;
            min-width: 180px;
        }

        .filter-label {
            font-size: 11px;
            text-transform: uppercase;
            color: #78716c;
            margin-bottom: 4px;
        }

        .filter-select,
        .filter-input {
            width: 100%;
            padding: 6px 10px;
            border: 1px solid #e5e5e5;
            border-radius: 6px;
            font-size: 13px;
            background: white;
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
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
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
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .newspaper-item {
            display: inline-block;
            background: #f5f5f4;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 13px;
            margin: 4px;
            border: 1px solid #e5e5e5;
        }

        .detail-row {
            display: flex;
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .detail-label {
            width: 140px;
            font-size: 13px;
            color: #78716c;
        }

        .detail-value {
            flex: 1;
            font-size: 14px;
            color: #1c1917;
        }
    </style>
</head>

<body>

    <div class="toast-container" id="toastContainer"></div>

    <div class="flex">
        <?php include './sidebar.php'; ?>

        <main class="flex-1 ml-60 min-h-screen">
            <div class="p-8">

                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-2xl font-medium">Distribution History</h1>
                        <p class="text-sm text-gray-500 mt-1">View and manage distribution records</p>
                    </div>
                    <!--<a href="newspaper_distribution.php" class="btn-primary">
                        <i class="fa-solid fa-plus mr-1"></i> New Distribution
                    </a>-->
                </div>

                <?php if ($last_distribution): ?>
                    <div class="notification" id="lastDistributionNotification">
                        <div>
                            <i class="fa-regular fa-circle-check text-green-600 mr-2"></i>
                            <span class="text-sm">
                                <?php echo (int)$last_distribution['count']; ?> newspaper(s) distributed to
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

                <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">

                    <!-- Filter bar -->
                    <div class="filter-bar">
                        <form method="GET" class="filter-group">
                            <div class="filter-field">
                                <div class="filter-label">Search</div>
                                <input type="text" name="search" class="filter-input"
                                    placeholder="Recipient, department..."
                                    autocomplete="off"
                                    value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="filter-field">
                                <div class="filter-label">Category</div>
                                <select name="filter_category" class="filter-select">
                                    <option value="0">All</option>
                                    <?php foreach ($all_categories as $cat): ?>
                                        <option value="<?php echo $cat['id']; ?>"
                                            <?php echo $filter_category == $cat['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cat['category_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-field">
                                <div class="filter-label">From</div>
                                <input type="date" name="date_from" class="filter-input" value="<?php echo htmlspecialchars($date_from); ?>">
                            </div>
                            <div class="filter-field">
                                <div class="filter-label">To</div>
                                <input type="date" name="date_to" class="filter-input" value="<?php echo htmlspecialchars($date_to); ?>">
                            </div>
                            <div>
                                <button type="submit" class="btn-primary">Apply</button>
                                <a href="distribution_history.php" class="btn-secondary ml-2">Reset</a>
                            </div>
                        </form>
                    </div>

                    <?php if ($distribution_history && $distribution_history->num_rows > 0): ?>
                        <div class="overflow-x-auto">
                            <table>
                                <thead>
                                    <tr>
                                        <th>
                                            <a href="<?php echo sortUrl('date_distributed', $sort_by, $sort_order); ?>"
                                                class="sort-link <?php echo $sort_by === 'date_distributed' ? 'active' : ''; ?>">
                                                Date
                                                <?php echo sortIcon('date_distributed', $sort_by, $sort_order); ?>
                                            </a>
                                        </th>
                                        <th>
                                            <a href="<?php echo sortUrl('distributed_to', $sort_by, $sort_order); ?>"
                                                class="sort-link <?php echo $sort_by === 'distributed_to' ? 'active' : ''; ?>">
                                                Recipient
                                                <?php echo sortIcon('distributed_to', $sort_by, $sort_order); ?>
                                            </a>
                                        </th>
                                        <th>
                                            <a href="<?php echo sortUrl('department', $sort_by, $sort_order); ?>"
                                                class="sort-link <?php echo $sort_by === 'department' ? 'active' : ''; ?>">
                                                Department
                                                <?php echo sortIcon('department', $sort_by, $sort_order); ?>
                                            </a>
                                        </th>
                                        <th>
                                            <a href="<?php echo sortUrl('copies', $sort_by, $sort_order); ?>"
                                                class="sort-link <?php echo $sort_by === 'copies' ? 'active' : ''; ?>">
                                                Copies
                                                <?php echo sortIcon('copies', $sort_by, $sort_order); ?>
                                            </a>
                                        </th>
                                        <th>
                                            <a href="<?php echo sortUrl('distributed_by', $sort_by, $sort_order); ?>"
                                                class="sort-link <?php echo $sort_by === 'distributed_by' ? 'active' : ''; ?>">
                                                Distributed By
                                                <?php echo sortIcon('distributed_by', $sort_by, $sort_order); ?>
                                            </a>
                                        </th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($dist = $distribution_history->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo date('M j, Y', strtotime($dist['date_distributed'])); ?></td>
                                            <td class="font-medium"><?php echo htmlspecialchars($dist['distributed_to']); ?></td>
                                            <td><?php echo htmlspecialchars($dist['department'] ?? '—'); ?></td>
                                            <td><?php echo (int)$dist['copies']; ?></td>
                                            <td><?php echo htmlspecialchars($dist['distributed_by'] ?? 'N/A'); ?></td>
                                            <td class="whitespace-nowrap">
                                                <button onclick='viewDistribution(<?php echo json_encode($dist); ?>)'
                                                    class="action-btn" title="View">
                                                    <i class="fa-regular fa-eye"></i>
                                                </button>
                                                <button onclick="editDistribution(<?php echo (int)$dist['id']; ?>)"
                                                    class="action-btn" title="Edit">
                                                    <i class="fa-regular fa-pen-to-square"></i>
                                                </button>
                                                <button onclick="openDeleteModal(<?php echo (int)$dist['id']; ?>, '<?php echo addslashes(htmlspecialchars($dist['distributed_to'])); ?>', <?php echo (int)$dist['copies']; ?>)"
                                                    class="action-btn delete-btn" title="Delete">
                                                    <i class="fa-regular fa-trash-can"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($total_pages > 1): ?>
                            <?php
                            $base_params = $_GET;
                            unset($base_params['page']);
                            $base_qs = http_build_query($base_params);
                            $base_qs = $base_qs ? $base_qs . '&' : '';
                            ?>
                            <div class="p-4 border-t border-gray-200 flex justify-between items-center">
                                <div class="text-sm text-gray-500">
                                    Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $limit, $total_distributions); ?>
                                    of <?php echo $total_distributions; ?>
                                </div>
                                <div class="pagination">
                                    <a href="?<?php echo $base_qs; ?>page=1" class="page-link">«</a>
                                    <a href="?<?php echo $base_qs; ?>page=<?php echo max(1, $page - 1); ?>" class="page-link">‹</a>
                                    <?php
                                    $start = max(1, $page - 2);
                                    $end   = min($total_pages, $page + 2);
                                    for ($p = $start; $p <= $end; $p++): ?>
                                        <a href="?<?php echo $base_qs; ?>page=<?php echo $p; ?>"
                                            class="page-link <?php echo $p === $page ? 'active' : ''; ?>">
                                            <?php echo $p; ?>
                                        </a>
                                    <?php endfor; ?>
                                    <a href="?<?php echo $base_qs; ?>page=<?php echo min($total_pages, $page + 1); ?>" class="page-link">›</a>
                                    <a href="?<?php echo $base_qs; ?>page=<?php echo $total_pages; ?>" class="page-link">»</a>
                                </div>
                            </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <div class="text-center py-12 text-gray-500">
                            <i class="fa-regular fa-inbox text-3xl mb-2 block"></i>
                            <p>No distribution records found</p>
                            <a href="newspaper_distribution.php" class="text-blue-600 hover:underline text-sm mt-2 inline-block">
                                Start distributing
                            </a>
                        </div>
                    <?php endif; ?>

                </div><!-- /card -->
            </div>
        </main>
    </div>

    <!-- ── View Modal ─────────────────────────────────────────────────────────── -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Distribution Details</h3>
                <button onclick="closeModal('viewModal')" class="text-gray-400 hover:text-gray-600">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>
            <div class="modal-body" id="viewContent"></div>
            <div class="modal-footer">
                <button onclick="closeModal('viewModal')" class="btn-secondary">Close</button>
            </div>
        </div>
    </div>

    <!-- ── Edit Modal ─────────────────────────────────────────────────────────── -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Distribution</h3>
                <button onclick="closeModal('editModal')" class="text-gray-400 hover:text-gray-600">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>
            <div class="modal-body">
                <div id="editLoading" class="text-center py-8 text-gray-500">
                    <i class="fa-solid fa-spinner fa-spin mr-2"></i> Loading…
                </div>
                <form id="editForm" style="display:none;" method="POST" action="distribution_history.php">
                    <input type="hidden" name="edit_distribution_submit" value="1">
                    <input type="hidden" name="edit_id" id="edit_id">
                    <div class="form-group">
                        <label class="form-label">Recipient Name</label>
                        <input type="text" name="edit_individual_name" id="edit_individual_name" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Department</label>
                        <input type="text" name="edit_department" id="edit_department" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Copies</label>
                        <input type="number" name="edit_copies" id="edit_copies" class="form-input" min="1" required>
                    </div>
                    <div class="flex justify-end gap-2 mt-6">
                        <button type="button" onclick="closeModal('editModal')" class="btn-secondary">Cancel</button>
                        <button type="submit" class="btn-primary">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ── Delete Modal ───────────────────────────────────────────────────────── -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Confirm Delete</h3>
                <button onclick="closeModal('deleteModal')" class="text-gray-400 hover:text-gray-600">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>
            <div class="modal-body">
                <p class="text-gray-600 mb-4">Delete this distribution record?</p>
                <div class="bg-red-50 border border-red-200 rounded-lg p-3">
                    <p class="font-medium text-red-800" id="deleteRecipientName"></p>
                    <p class="text-sm text-red-600 mt-1" id="deleteCopiesCount"></p>
                </div>
                <p class="text-xs text-gray-500 mt-3">Copies will be restored to inventory.</p>
            </div>
            <div class="modal-footer">
                <button onclick="closeModal('deleteModal')" class="btn-secondary">Cancel</button>
                <a href="#" id="confirmDeleteBtn" class="btn-danger">Delete</a>
            </div>
        </div>
    </div>

    <script>
        // ── Toast ────────────────────────────────────────────────────────────────────
        function showToast(type, message) {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = 'toast toast-' + type;
            const icon = type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation';
            toast.innerHTML = `
        <i class="fa-regular ${icon}"></i>
        <span style="flex:1">${message}</span>
        <button onclick="this.parentElement.remove()" style="color:#9e9e9e;background:none;border:none;cursor:pointer;font-size:16px;">×</button>
    `;
            container.appendChild(toast);
            setTimeout(() => {
                if (toast.parentNode) toast.remove();
            }, 4000);
        }

        <?php if ($toast): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showToast('<?php echo $toast['type']; ?>', '<?php echo addslashes($toast['message']); ?>');
            });
        <?php endif; ?>

        // ── Dismiss last distribution notification ───────────────────────────────────
        function dismissLastDistribution() {
            const el = document.getElementById('lastDistributionNotification');
            if (el) el.remove();
            fetch('distribution_history.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'ajax=dismiss_last_distribution'
            });
        }

        // ── Modal helpers ────────────────────────────────────────────────────────────
        function closeModal(id) {
            document.getElementById(id).style.display = 'none';
        }

        function openModal(id) {
            document.getElementById(id).style.display = 'flex';
        }

        window.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal')) e.target.style.display = 'none';
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal').forEach(m => m.style.display = 'none');
            }
        });

        // ── Escape HTML ──────────────────────────────────────────────────────────────
        function escapeHtml(text) {
            if (text == null) return '—';
            const d = document.createElement('div');
            d.textContent = text;
            return d.innerHTML;
        }

        // ── View modal ───────────────────────────────────────────────────────────────
        function viewDistribution(dist) {
            // Build newspapers HTML from newspapers_list (pipe-separated) or fallback
            let newspapersHtml = '';
            const list = dist.newspapers_list || '';
            if (list) {
                list.split('|').forEach(function(paper) {
                    if (paper.trim()) {
                        newspapersHtml += `<span class="newspaper-item">${escapeHtml(paper.trim())}</span>`;
                    }
                });
            } else {
                newspapersHtml = '<span class="text-gray-400">—</span>';
            }

            const dateStr = dist.date_distributed ?
                new Date(dist.date_distributed).toLocaleDateString('en-GB', {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric'
                }) :
                '—';

            document.getElementById('viewContent').innerHTML = `
        <div class="detail-row">
            <div class="detail-label">Newspapers</div>
            <div class="detail-value">${newspapersHtml}</div>
        </div>
        <div class="detail-row">
            <div class="detail-label">Date</div>
            <div class="detail-value">${dateStr}</div>
        </div>
        <div class="detail-row">
            <div class="detail-label">Copies</div>
            <div class="detail-value">${dist.copies}</div>
        </div>
        <div class="detail-row">
            <div class="detail-label">Recipient</div>
            <div class="detail-value">${escapeHtml(dist.distributed_to)}</div>
        </div>
        <div class="detail-row">
            <div class="detail-label">Department</div>
            <div class="detail-value">${escapeHtml(dist.department)}</div>
        </div>
        <div class="detail-row">
            <div class="detail-label">Distributed By</div>
            <div class="detail-value">${escapeHtml(dist.distributed_by)}</div>
        </div>
    `;
            openModal('viewModal');
        }

        // ── Edit modal ───────────────────────────────────────────────────────────────
        function editDistribution(id) {
            openModal('editModal');
            document.getElementById('editLoading').style.display = 'block';
            document.getElementById('editForm').style.display = 'none';

            fetch('distribution_history.php?ajax=get_distribution&id=' + encodeURIComponent(id))
                .then(function(res) {
                    if (!res.ok) throw new Error('Network error');
                    return res.json();
                })
                .then(function(data) {
                    if (data.success) {
                        document.getElementById('edit_id').value = data.distribution.id;
                        document.getElementById('edit_individual_name').value = data.distribution.distributed_to || '';
                        document.getElementById('edit_department').value = data.distribution.department || '';
                        document.getElementById('edit_copies').value = data.distribution.copies || 1;
                        document.getElementById('editLoading').style.display = 'none';
                        document.getElementById('editForm').style.display = 'block';
                    } else {
                        showToast('error', data.message || 'Failed to load record');
                        closeModal('editModal');
                    }
                })
                .catch(function() {
                    showToast('error', 'Could not load distribution data');
                    closeModal('editModal');
                });
        }

        // ── Delete modal ─────────────────────────────────────────────────────────────
        function openDeleteModal(id, recipient, copies) {
            document.getElementById('deleteRecipientName').textContent = recipient;
            document.getElementById('deleteCopiesCount').textContent = copies + ' copy(s) will be returned to stock';

            // Build the delete URL preserving current filters but removing delete param
            const params = new URLSearchParams(window.location.search);
            params.delete('delete_distribution');
            params.set('delete_distribution', id);
            document.getElementById('confirmDeleteBtn').href = 'distribution_history.php?' + params.toString();

            openModal('deleteModal');
        }
    </script>

    <?php
    // ── PHP helpers for sort links ─────────────────────────────────────────────
    function sortUrl($column, $current_sort, $current_order)
    {
        $params = $_GET;
        $params['sort_by'] = $column;
        $params['sort_order'] = ($column === $current_sort && $current_order === 'ASC') ? 'DESC' : 'ASC';
        $params['page'] = 1;
        return 'distribution_history.php?' . http_build_query($params);
    }

    function sortIcon($column, $current_sort, $current_order)
    {
        if ($column !== $current_sort) return '<i class="fa-solid fa-sort text-gray-300 ml-1 text-xs"></i>';
        $icon = $current_order === 'ASC' ? 'fa-sort-up' : 'fa-sort-down';
        return '<i class="fa-solid ' . $icon . ' ml-1 text-xs"></i>';
    }
    ?>

</body>

</html>