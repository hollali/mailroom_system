<?php
// distribution_history.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once './config/db.php';
session_start();

// Handle Edit Distribution
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_distribution_submit'])) {
    $id = (int)$_POST['edit_id'];
    $individual_name = trim($_POST['edit_individual_name']);
    $department = trim($_POST['edit_department']);
    $copies = (int)$_POST['edit_copies'];

    $conn->begin_transaction();

    try {
        // Get current distribution details
        $current = $conn->query("SELECT newspaper_id, copies FROM distribution WHERE id = $id")->fetch_assoc();

        if ($current) {
            $newspaper_id = $current['newspaper_id'];
            $old_copies = $current['copies'];

            // Calculate the difference
            $copy_difference = $copies - $old_copies;

            if ($copy_difference != 0) {
                // Update newspaper available copies
                $conn->query("UPDATE newspapers SET available_copies = available_copies - $copy_difference WHERE id = $newspaper_id");

                // Update newspaper status
                $check = $conn->query("SELECT available_copies FROM newspapers WHERE id = $newspaper_id");
                $paper = $check->fetch_assoc();

                if ($paper['available_copies'] > 0) {
                    $conn->query("UPDATE newspapers SET status = 'available' WHERE id = $newspaper_id");
                } else {
                    $conn->query("UPDATE newspapers SET status = 'distributed' WHERE id = $newspaper_id");
                }
            }

            // Update distribution record
            $stmt = $conn->prepare("UPDATE distribution SET distributed_to = ?, department = ?, copies = ? WHERE id = ?");
            $stmt->bind_param("ssii", $individual_name, $department, $copies, $id);
            $stmt->execute();

            $conn->commit();
            $_SESSION['toast'] = [
                'type' => 'success',
                'message' => "Distribution updated successfully"
            ];
        }
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => "Error updating distribution: " . $e->getMessage()
        ];
    }

    header('Location: distribution_history.php');
    exit();
}

// Handle Delete Distribution
if (isset($_GET['delete_distribution'])) {
    $id = (int)$_GET['delete_distribution'];

    // Start transaction
    $conn->begin_transaction();

    try {
        // Get distribution details first
        $dist_result = $conn->query("SELECT newspaper_id, copies FROM distribution WHERE id = $id");
        $distribution = $dist_result->fetch_assoc();

        if ($distribution) {
            // Delete the distribution record
            $stmt = $conn->prepare("DELETE FROM distribution WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();

            // Restore the copies back to newspaper
            $newspaper_id = $distribution['newspaper_id'];
            $copies = $distribution['copies'];

            $conn->query("UPDATE newspapers SET available_copies = available_copies + $copies WHERE id = $newspaper_id");

            // Update newspaper status
            $check = $conn->query("SELECT available_copies FROM newspapers WHERE id = $newspaper_id");
            $paper = $check->fetch_assoc();

            if ($paper['available_copies'] > 0) {
                $conn->query("UPDATE newspapers SET status = 'available' WHERE id = $newspaper_id");
            }

            $conn->commit();
            $_SESSION['toast'] = [
                'type' => 'success',
                'message' => "Distribution record deleted and copies restored successfully"
            ];
        }
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => "Error deleting distribution: " . $e->getMessage()
        ];
    }

    // Preserve filters and pagination
    $query_params = $_GET;
    unset($query_params['delete_distribution']);
    $redirect_url = 'distribution_history.php' . (!empty($query_params) ? '?' . http_build_query($query_params) : '');
    header('Location: ' . $redirect_url);
    exit();
}

// Handle AJAX request for getting distribution data
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_distribution' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $id = (int)$_GET['id'];

    $result = $conn->query("
        SELECT d.*, n.newspaper_name, n.newspaper_number, nc.category_name 
        FROM distribution d 
        JOIN newspapers n ON d.newspaper_id = n.id 
        LEFT JOIN newspaper_categories nc ON n.category_id = nc.id 
        WHERE d.id = $id
    ");

    if ($result && $row = $result->fetch_assoc()) {
        echo json_encode(['success' => true, 'distribution' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Distribution not found']);
    }
    exit();
}

// Get all categories for filter dropdown
$categories = $conn->query("SELECT * FROM newspaper_categories ORDER BY category_name");
$all_categories = [];
while ($cat = $categories->fetch_assoc()) {
    $all_categories[] = $cat;
}

// Pagination and filter settings for distribution history
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_category = isset($_GET['filter_category']) ? (int)$_GET['filter_category'] : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'date_distributed';
$sort_order = isset($_GET['sort_order']) ? $_GET['sort_order'] : 'DESC';

// Build where clause for distribution history
$where_clauses = [];

if (!empty($search)) {
    $where_clauses[] = "(n.newspaper_name LIKE '%$search%' OR n.newspaper_number LIKE '%$search%' OR d.distributed_to LIKE '%$search%' OR d.department LIKE '%$search%' OR d.distributed_by LIKE '%$search%')";
}

if ($filter_category > 0) {
    $where_clauses[] = "n.category_id = $filter_category";
}

if (!empty($date_from)) {
    $where_clauses[] = "d.date_distributed >= '$date_from'";
}

if (!empty($date_to)) {
    $where_clauses[] = "d.date_distributed <= '$date_to'";
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM distribution d 
                JOIN newspapers n ON d.newspaper_id = n.id 
                LEFT JOIN newspaper_categories nc ON n.category_id = nc.id 
                $where_sql";
$count_result = $conn->query($count_query);
$total_distributions = $count_result->fetch_assoc()['total'] ?? 0;
$total_pages = ceil($total_distributions / $limit);

// Get distribution history with filters, sorting and pagination
$distribution_history = $conn->query("
    SELECT d.*, n.newspaper_name, n.newspaper_number, nc.category_name, nc.id as category_id
    FROM distribution d 
    JOIN newspapers n ON d.newspaper_id = n.id 
    LEFT JOIN newspaper_categories nc ON n.category_id = nc.id 
    $where_sql
    ORDER BY 
        CASE WHEN '$sort_by' = 'date_distributed' THEN d.date_distributed END $sort_order,
        CASE WHEN '$sort_by' = 'newspaper_name' THEN n.newspaper_name END $sort_order,
        CASE WHEN '$sort_by' = 'category_name' THEN nc.category_name END $sort_order,
        CASE WHEN '$sort_by' = 'distributed_to' THEN d.distributed_to END $sort_order,
        CASE WHEN '$sort_by' = 'department' THEN d.department END $sort_order,
        CASE WHEN '$sort_by' = 'distributed_by' THEN d.distributed_by END $sort_order,
        CASE WHEN '$sort_by' = 'copies' THEN d.copies END $sort_order
    LIMIT $offset, $limit
");

// Get toast message from session
$toast = null;
if (isset($_SESSION['toast'])) {
    $toast = $_SESSION['toast'];
    unset($_SESSION['toast']);
}

// Include sidebar
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
            font-size: 0.75rem;
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
        }

        .sort-icon {
            font-size: 0.7rem;
            margin-left: 0.25rem;
            opacity: 0.5;
        }

        th.active-sort .sort-icon {
            opacity: 1;
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
            justify-content: flex-end;
            align-items: center;
            flex-wrap: wrap;
        }

        .pagination-item {
            min-width: 2.5rem;
            height: 2.5rem;
            padding: 0 0.85rem;
            border: 1px solid #e7e5e4;
            border-radius: 0.8rem;
            font-size: 0.875rem;
            font-weight: 500;
            color: #292524;
            background-color: white;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 1px 2px rgba(28, 25, 23, 0.04);
            transition: all 0.2s ease;
        }

        .pagination-item:hover {
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

        @media (max-width: 768px) {
            .pagination-shell {
                padding: 1rem;
            }

            .pagination-controls {
                width: 100%;
                justify-content: flex-start;
            }
        }

        .filter-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background-color: #f0f0f0;
            padding: 0.25rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.75rem;
        }

        .modal {
            transition: opacity 0.3s ease;
        }

        .modal-content {
            max-height: 90vh;
            overflow-y: auto;
        }

        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }

        .toast {
            min-width: 300px;
            max-width: 400px;
            background-color: white;
            border: 1px solid #e5e5e5;
            border-radius: 0.375rem;
            padding: 1rem;
            margin-bottom: 0.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            animation: slideIn 0.3s ease-in-out;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .toast-success {
            border-left: 4px solid #10b981;
        }

        .toast-error {
            border-left: 4px solid #ef4444;
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

        @keyframes fadeOut {
            from {
                transform: translateX(0);
                opacity: 1;
            }

            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }

        .toast.fade-out {
            animation: fadeOut 0.3s ease-in-out forwards;
        }

        .issue-number {
            font-family: monospace;
            font-size: 0.75rem;
            color: #6b7280;
        }
    </style>
</head>

<body class="bg-[#f5f5f4]">
    <!-- Toast Container -->
    <div id="toastContainer" class="toast-container"></div>

    <div class="flex">
        <main class="flex-1 ml-60 min-h-screen">
            <!-- Header -->
            <div class="px-8 py-6 border-b border-[#e5e5e5] bg-white">
                <div>
                    <div>
                        <h1 class="text-2xl font-medium text-[#1e1e1e]">Distribution History</h1>
                        <p class="text-sm text-[#6e6e6e] mt-1">View and manage all newspaper distribution records</p>
                    </div>
                </div>
            </div>

            <div class="p-8">
                <!-- Distribution History with Search and Filters -->
                <div class="bg-white border border-[#e5e5e5] rounded-lg overflow-hidden">
                    <div class="px-5 py-4 bg-[#fafafa] border-b border-[#e5e5e5]">
                        <div class="flex justify-between items-center">
                            <h3 class="text-sm font-medium text-[#1e1e1e]">Distribution Records</h3>
                        </div>

                        <!-- Search and Filter Bar -->
                        <div class="mt-3">
                            <form method="GET" id="distributionHistoryForm" class="flex flex-wrap gap-2 items-end">
                                <div class="flex-1 min-w-[200px]">
                                    <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Search</label>
                                    <div class="relative">
                                        <i class="fa-solid fa-magnifying-glass absolute left-3 top-2.5 text-sm text-[#9e9e9e]"></i>
                                        <input type="text" name="search" id="distributionLiveSearch"
                                            placeholder="Newspaper, recipient, department, staff..."
                                            value="<?php echo htmlspecialchars($search); ?>"
                                            class="w-full pl-9 pr-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]"
                                            autocomplete="off">
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Category</label>
                                    <select name="filter_category" class="px-3 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white min-w-[150px]">
                                        <option value="0">All Categories</option>
                                        <?php foreach ($all_categories as $cat): ?>
                                            <option value="<?php echo $cat['id']; ?>" <?php echo $filter_category == $cat['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($cat['category_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">From Date</label>
                                    <input type="date" name="date_from" value="<?php echo $date_from; ?>"
                                        class="px-3 py-2 text-sm border border-[#e5e5e5] rounded-md" autocomplete="off">
                                </div>

                                <div>
                                    <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">To Date</label>
                                    <input type="date" name="date_to" value="<?php echo $date_to; ?>"
                                        class="px-3 py-2 text-sm border border-[#e5e5e5] rounded-md" autocomplete="off">
                                </div>

                                <div class="flex gap-2">
                                    <button type="submit" class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4]">
                                        <i class="fa-solid fa-sliders mr-1"></i>Apply
                                    </button>
                                    <a href="distribution_history.php" class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4]">
                                        <i class="fa-solid fa-rotate-right mr-1"></i>Reset
                                    </a>
                                </div>
                            </form>
                        </div>

                        <!-- Active Filters Display -->
                        <?php if (!empty($search) || $filter_category > 0 || !empty($date_from) || !empty($date_to)): ?>
                            <div class="flex flex-wrap gap-2 mt-3 pt-3 border-t border-[#e5e5e5]">
                                <?php if (!empty($search)): ?>
                                    <span class="filter-badge">
                                        Search: "<?php echo htmlspecialchars($search); ?>"
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['search' => '', 'page' => 1])); ?>" class="text-[#9e9e9e] hover:text-[#1e1e1e]">
                                            <i class="fa-solid fa-xmark"></i>
                                        </a>
                                    </span>
                                <?php endif; ?>

                                <?php if ($filter_category > 0):
                                    $cat_name = '';
                                    foreach ($all_categories as $cat) {
                                        if ($cat['id'] == $filter_category) {
                                            $cat_name = $cat['category_name'];
                                            break;
                                        }
                                    }
                                ?>
                                    <span class="filter-badge">
                                        Category: <?php echo htmlspecialchars($cat_name); ?>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['filter_category' => 0, 'page' => 1])); ?>" class="text-[#9e9e9e] hover:text-[#1e1e1e]">
                                            <i class="fa-solid fa-xmark"></i>
                                        </a>
                                    </span>
                                <?php endif; ?>

                                <?php if (!empty($date_from)): ?>
                                    <span class="filter-badge">
                                        From: <?php echo $date_from; ?>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['date_from' => '', 'page' => 1])); ?>" class="text-[#9e9e9e] hover:text-[#1e1e1e]">
                                            <i class="fa-solid fa-xmark"></i>
                                        </a>
                                    </span>
                                <?php endif; ?>

                                <?php if (!empty($date_to)): ?>
                                    <span class="filter-badge">
                                        To: <?php echo $date_to; ?>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['date_to' => '', 'page' => 1])); ?>" class="text-[#9e9e9e] hover:text-[#1e1e1e]">
                                            <i class="fa-solid fa-xmark"></i>
                                        </a>
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($distribution_history && $distribution_history->num_rows > 0): ?>
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead>
                                    <tr class="bg-[#fafafa]">
                                        <th onclick="sortTable('date_distributed')" class="<?php echo $sort_by == 'date_distributed' ? 'active-sort' : ''; ?>">
                                            Date
                                            <?php if ($sort_by == 'date_distributed'): ?>
                                                <i class="fa-solid fa-chevron-<?php echo $sort_order == 'ASC' ? 'up' : 'down'; ?> sort-icon"></i>
                                            <?php endif; ?>
                                        </th>
                                        <th onclick="sortTable('newspaper_name')" class="<?php echo $sort_by == 'newspaper_name' ? 'active-sort' : ''; ?>">
                                            Newspaper
                                            <?php if ($sort_by == 'newspaper_name'): ?>
                                                <i class="fa-solid fa-chevron-<?php echo $sort_order == 'ASC' ? 'up' : 'down'; ?> sort-icon"></i>
                                            <?php endif; ?>
                                        </th>
                                        <th onclick="sortTable('category_name')" class="<?php echo $sort_by == 'category_name' ? 'active-sort' : ''; ?>">
                                            Category
                                            <?php if ($sort_by == 'category_name'): ?>
                                                <i class="fa-solid fa-chevron-<?php echo $sort_order == 'ASC' ? 'up' : 'down'; ?> sort-icon"></i>
                                            <?php endif; ?>
                                        </th>
                                        <th onclick="sortTable('distributed_to')" class="<?php echo $sort_by == 'distributed_to' ? 'active-sort' : ''; ?>">
                                            Distributed To
                                            <?php if ($sort_by == 'distributed_to'): ?>
                                                <i class="fa-solid fa-chevron-<?php echo $sort_order == 'ASC' ? 'up' : 'down'; ?> sort-icon"></i>
                                            <?php endif; ?>
                                        </th>
                                        <th onclick="sortTable('department')" class="<?php echo $sort_by == 'department' ? 'active-sort' : ''; ?>">
                                            Department
                                            <?php if ($sort_by == 'department'): ?>
                                                <i class="fa-solid fa-chevron-<?php echo $sort_order == 'ASC' ? 'up' : 'down'; ?> sort-icon"></i>
                                            <?php endif; ?>
                                        </th>
                                        <th onclick="sortTable('copies')" class="<?php echo $sort_by == 'copies' ? 'active-sort' : ''; ?>">
                                            Copies
                                            <?php if ($sort_by == 'copies'): ?>
                                                <i class="fa-solid fa-chevron-<?php echo $sort_order == 'ASC' ? 'up' : 'down'; ?> sort-icon"></i>
                                            <?php endif; ?>
                                        </th>
                                        <th onclick="sortTable('distributed_by')" class="<?php echo $sort_by == 'distributed_by' ? 'active-sort' : ''; ?>">
                                            By
                                            <?php if ($sort_by == 'distributed_by'): ?>
                                                <i class="fa-solid fa-chevron-<?php echo $sort_order == 'ASC' ? 'up' : 'down'; ?> sort-icon"></i>
                                            <?php endif; ?>
                                        </th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($dist = $distribution_history->fetch_assoc()): ?>
                                        <tr class="hover:bg-[#fafafa] distribution-row" id="distribution-row-<?php echo $dist['id']; ?>"
                                            data-search="<?php echo strtolower(htmlspecialchars(trim(($dist['newspaper_name'] ?? '') . ' ' . ($dist['newspaper_number'] ?? '') . ' ' . ($dist['category_name'] ?? '') . ' ' . ($dist['distributed_to'] ?? '') . ' ' . ($dist['department'] ?? '') . ' ' . ($dist['distributed_by'] ?? '') . ' ' . ($dist['copies'] ?? 0) . ' ' . date('Y-m-d', strtotime($dist['date_distributed']))))); ?>"
                                            data-category="<?php echo (int) ($dist['category_id'] ?? 0); ?>"
                                            data-date="<?php echo htmlspecialchars(date('Y-m-d', strtotime($dist['date_distributed']))); ?>">
                                            <td class="text-sm"><?php echo date('M j, Y', strtotime($dist['date_distributed'])); ?></td>
                                            <td class="text-sm font-medium">
                                                <?php echo htmlspecialchars($dist['newspaper_name']); ?>
                                                <span class="text-xs font-mono issue-number ml-1"><?php echo htmlspecialchars($dist['newspaper_number']); ?></span>
                                            </td>
                                            <td class="text-sm"><?php echo htmlspecialchars($dist['category_name'] ?? 'N/A'); ?></td>
                                            <td class="text-sm"><?php echo htmlspecialchars($dist['distributed_to']); ?></td>
                                            <td class="text-sm"><?php echo htmlspecialchars($dist['department'] ?? '-'); ?></td>
                                            <td class="text-sm"><?php echo $dist['copies']; ?></td>
                                            <td class="text-sm"><?php echo htmlspecialchars($dist['distributed_by'] ?? 'N/A'); ?></td>
                                            <td class="text-sm">
                                                <div class="flex items-center gap-2">
                                                    <button onclick="viewDistribution(<?php echo htmlspecialchars(json_encode($dist)); ?>)"
                                                        class="action-btn" title="View Details">
                                                        <i class="fa-regular fa-eye"></i>
                                                    </button>
                                                    <button onclick="editDistribution(<?php echo $dist['id']; ?>)"
                                                        class="action-btn" title="Edit">
                                                        <i class="fa-regular fa-pen-to-square"></i>
                                                    </button>
                                                    <button onclick="openDeleteModal(<?php echo $dist['id']; ?>, '<?php echo htmlspecialchars($dist['newspaper_name']); ?>', '<?php echo htmlspecialchars($dist['distributed_to']); ?>')"
                                                        class="action-btn delete-btn" title="Delete">
                                                        <i class="fa-regular fa-trash-can"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                    <tr id="distributionNoResultsRow" class="hidden">
                                        <td colspan="8" class="text-sm text-[#6e6e6e] text-center py-8">
                                            No distribution history matches the current live search on this page.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <?php
                            $pageStart = $total_distributions > 0 ? $offset + 1 : 0;
                            $pageEnd = min($offset + ($distribution_history ? $distribution_history->num_rows : 0), $total_distributions);
                            $start = max(1, $page - 2);
                            $end = min($total_pages, $page + 2);
                            ?>
                            <div class="pagination-shell">
                                <div class="pagination-meta">
                                    <div class="pagination-title">
                                        Showing <span id="visibleDistributionCount"><?php echo $distribution_history ? $distribution_history->num_rows : 0; ?></span> entr<?php echo ($distribution_history && $distribution_history->num_rows == 1) ? 'y' : 'ies'; ?> on this page
                                    </div>
                                    <div class="pagination-subtitle">
                                        Records <?php echo $pageStart; ?>-<?php echo $pageEnd; ?> of <?php echo $total_distributions; ?> total
                                    </div>
                                </div>
                                <div class="pagination-controls">
                                    <div class="pagination-page-indicator">Page <?php echo $page; ?> of <?php echo $total_pages; ?></div>
                                    <div class="pagination">
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" class="pagination-item compact <?php echo $page <= 1 ? 'pointer-events-none opacity-50' : ''; ?>">
                                            <i class="fa-solid fa-chevrons-left"></i>
                                        </a>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)])); ?>" class="pagination-item compact <?php echo $page <= 1 ? 'pointer-events-none opacity-50' : ''; ?>">
                                            <i class="fa-solid fa-chevron-left"></i>
                                        </a>

                                        <?php if ($start > 1): ?>
                                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" class="pagination-item">1</a>
                                            <?php if ($start > 2): ?>
                                                <span class="pagination-ellipsis">...</span>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <?php for ($i = $start; $i <= $end; $i++): ?>
                                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"
                                                class="pagination-item <?php echo $i == $page ? 'active' : ''; ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        <?php endfor; ?>

                                        <?php if ($end < $total_pages): ?>
                                            <?php if ($end < $total_pages - 1): ?>
                                                <span class="pagination-ellipsis">...</span>
                                            <?php endif; ?>
                                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" class="pagination-item"><?php echo $total_pages; ?></a>
                                        <?php endif; ?>

                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => min($total_pages, $page + 1)])); ?>" class="pagination-item compact <?php echo $page >= $total_pages ? 'pointer-events-none opacity-50' : ''; ?>">
                                            <i class="fa-solid fa-chevron-right"></i>
                                        </a>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" class="pagination-item compact <?php echo $page >= $total_pages ? 'pointer-events-none opacity-50' : ''; ?>">
                                            <i class="fa-solid fa-chevrons-right"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="text-center py-8 text-[#6e6e6e]">
                            <i class="fa-regular fa-clock text-3xl mb-2"></i>
                            <p>No distribution records found</p>
                            <a href="newspaper_distribution.php" class="inline-block mt-3 text-sm text-blue-600 hover:underline">Start distributing →</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- View Distribution Modal -->
    <div id="viewModal" class="fixed inset-0 bg-[#000000] bg-opacity-20 hidden items-center justify-center z-50 modal">
        <div class="bg-white border border-[#e5e5e5] rounded-md w-full max-w-lg p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-medium text-[#1e1e1e]">Distribution Details</h2>
                <button type="button" onclick="closeViewModal()" class="text-[#9e9e9e] hover:text-[#1e1e1e]">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>

            <div id="viewContent" class="space-y-4">
                <!-- Content will be filled by JavaScript -->
            </div>

            <div class="flex justify-end mt-4">
                <button onclick="closeViewModal()"
                    class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                    Close
                </button>
            </div>
        </div>
    </div>

    <!-- Edit Distribution Modal -->
    <div id="editModal" class="fixed inset-0 bg-[#000000] bg-opacity-20 hidden items-center justify-center z-50 modal">
        <div class="bg-white border border-[#e5e5e5] rounded-md w-full max-w-lg p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-medium text-[#1e1e1e]">Edit Distribution</h2>
                <button type="button" onclick="closeEditModal()" class="text-[#9e9e9e] hover:text-[#1e1e1e]">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>

            <div id="editLoading" class="text-center py-4">
                <i class="fa-solid fa-spinner fa-spin mr-2"></i> Loading distribution data...
            </div>

            <form method="POST" action="distribution_history.php" id="editForm" style="display: none;">
                <input type="hidden" name="edit_id" id="edit_id">
                <div class="space-y-4">
                    <div>
                        <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Newspaper</label>
                        <p id="edit_newspaper_display" class="text-sm font-medium bg-[#f5f5f4] p-2 rounded-md"></p>
                    </div>

                    <div>
                        <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Individual's Name *</label>
                        <input type="text" name="edit_individual_name" id="edit_individual_name" required
                            class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]" autocomplete="off">
                    </div>

                    <div>
                        <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Department</label>
                        <input type="text" name="edit_department" id="edit_department"
                            class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]" autocomplete="off">
                    </div>

                    <div>
                        <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Copies *</label>
                        <input type="number" name="edit_copies" id="edit_copies" min="1" required
                            class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]" autocomplete="off">
                        <p class="text-xs text-[#6e6e6e] mt-1">Note: Changing copies will update newspaper inventory</p>
                    </div>
                </div>

                <div class="flex justify-end gap-2 mt-6">
                    <button type="button" onclick="closeEditModal()"
                        class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                        Cancel
                    </button>
                    <button type="submit" name="edit_distribution_submit"
                        class="px-4 py-2 text-sm bg-[#1e1e1e] text-white rounded-md hover:bg-[#2d2d2d]">
                        Update Distribution
                    </button>
                </div>
            </form>
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
                    <p class="text-sm font-medium text-red-800" id="deleteNewspaperName"></p>
                    <p class="text-xs text-red-600 mt-1" id="deleteRecipientName"></p>
                </div>
                <p class="text-xs text-[#9e9e9e] mt-3">
                    <i class="fa-solid fa-circle-info mr-1"></i>
                    This will restore the copies back to the newspaper inventory.
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
        // ========== TOAST NOTIFICATION ==========
        function showToast(type, message) {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;

            const icon = type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation';

            toast.innerHTML = `
                <div class="flex items-center gap-3">
                    <i class="fa-regular ${icon} text-${type === 'success' ? 'green' : 'red'}-500"></i>
                    <span class="text-sm text-[#1e1e1e]">${message}</span>
                </div>
                <button onclick="this.parentElement.remove()" class="text-[#9e9e9e] hover:text-[#1e1e1e]">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            `;

            container.appendChild(toast);

            setTimeout(() => {
                toast.classList.add('fade-out');
                setTimeout(() => {
                    if (toast.parentElement) {
                        toast.remove();
                    }
                }, 300);
            }, 5000);
        }

        <?php if ($toast): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showToast('<?php echo $toast['type']; ?>', '<?php echo addslashes($toast['message']); ?>');
            });
        <?php endif; ?>

        // ========== VIEW MODAL FUNCTIONS ==========
        function viewDistribution(dist) {
            const content = document.getElementById('viewContent');
            content.innerHTML = `
                <div class="grid grid-cols-2 gap-4">
                    <div class="col-span-2">
                        <p class="text-xs text-[#6e6e6e] uppercase mb-1">Newspaper</p>
                        <p class="text-sm font-medium">${escapeHtml(dist.newspaper_name)}</p>
                        <p class="text-xs font-mono text-[#6e6e6e] mt-1">${escapeHtml(dist.newspaper_number)}</p>
                    </div>
                    <div>
                        <p class="text-xs text-[#6e6e6e] uppercase mb-1">Category</p>
                        <p class="text-sm">${escapeHtml(dist.category_name || 'N/A')}</p>
                    </div>
                    <div>
                        <p class="text-xs text-[#6e6e6e] uppercase mb-1">Copies</p>
                        <p class="text-sm">${dist.copies}</p>
                    </div>
                    <div>
                        <p class="text-xs text-[#6e6e6e] uppercase mb-1">Date Distributed</p>
                        <p class="text-sm">${new Date(dist.date_distributed).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</p>
                    </div>
                    <div>
                        <p class="text-xs text-[#6e6e6e] uppercase mb-1">Distributed To</p>
                        <p class="text-sm">${escapeHtml(dist.distributed_to)}</p>
                    </div>
                    <div>
                        <p class="text-xs text-[#6e6e6e] uppercase mb-1">Department</p>
                        <p class="text-sm">${escapeHtml(dist.department || '-')}</p>
                    </div>
                    <div>
                        <p class="text-xs text-[#6e6e6e] uppercase mb-1">Distributed By</p>
                        <p class="text-sm">${escapeHtml(dist.distributed_by || 'N/A')}</p>
                    </div>
                </div>
            `;
            document.getElementById('viewModal').style.display = 'flex';
        }

        function closeViewModal() {
            document.getElementById('viewModal').style.display = 'none';
        }

        // ========== EDIT MODAL FUNCTIONS ==========
        function editDistribution(id) {
            const editModal = document.getElementById('editModal');
            const loadingEl = document.getElementById('editLoading');
            const formEl = document.getElementById('editForm');

            editModal.style.display = 'flex';
            loadingEl.style.display = 'block';
            formEl.style.display = 'none';

            fetch('distribution_history.php?ajax=get_distribution&id=' + id)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('edit_id').value = data.distribution.id;
                        document.getElementById('edit_newspaper_display').textContent =
                            data.distribution.newspaper_name + ' (' + data.distribution.newspaper_number + ')';
                        document.getElementById('edit_individual_name').value = data.distribution.distributed_to;
                        document.getElementById('edit_department').value = data.distribution.department || '';
                        document.getElementById('edit_copies').value = data.distribution.copies;

                        loadingEl.style.display = 'none';
                        formEl.style.display = 'block';
                    } else {
                        showToast('error', 'Failed to load distribution data');
                        closeEditModal();
                    }
                })
                .catch(error => {
                    showToast('error', 'Error loading distribution data');
                    closeEditModal();
                });
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
            document.getElementById('editLoading').style.display = 'block';
            document.getElementById('editForm').style.display = 'none';
        }

        // ========== DELETE MODAL FUNCTIONS ==========
        function openDeleteModal(id, newspaper, recipient) {
            document.getElementById('deleteNewspaperName').textContent = newspaper;
            document.getElementById('deleteRecipientName').textContent = 'Recipient: ' + recipient;
            document.getElementById('confirmDeleteBtn').href = '?delete_distribution=' + id + '&<?php echo http_build_query($_GET); ?>';
            document.getElementById('deleteModal').style.display = 'flex';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // ========== SORTING FUNCTION ==========
        function sortTable(column) {
            const url = new URL(window.location.href);
            const currentSort = '<?php echo $sort_by; ?>';
            const currentOrder = '<?php echo $sort_order; ?>';

            let newOrder = 'ASC';
            if (column === currentSort) {
                newOrder = currentOrder === 'ASC' ? 'DESC' : 'ASC';
            }

            url.searchParams.set('sort_by', column);
            url.searchParams.set('sort_order', newOrder);
            url.searchParams.set('page', '1');

            window.location.href = url.toString();
        }

        // ========== LIVE SEARCH FILTERING ==========
        function filterDistributionHistoryLive() {
            const searchTokens = (document.getElementById('distributionLiveSearch')?.value || '')
                .toLowerCase()
                .split(/\s+/)
                .filter(Boolean);
            const categoryFilter = document.querySelector('#distributionHistoryForm select[name="filter_category"]')?.value || '0';
            const fromDate = document.querySelector('#distributionHistoryForm input[name="date_from"]')?.value || '';
            const toDate = document.querySelector('#distributionHistoryForm input[name="date_to"]')?.value || '';
            const rows = document.querySelectorAll('.distribution-row');
            const noResultsRow = document.getElementById('distributionNoResultsRow');
            let visibleCount = 0;

            rows.forEach(row => {
                const searchText = row.getAttribute('data-search') || '';
                const category = row.getAttribute('data-category') || '0';
                const rowDate = row.getAttribute('data-date') || '';

                const matchesSearch = searchTokens.length === 0 || searchTokens.every(token => searchText.includes(token));
                const matchesCategory = categoryFilter === '0' || category === categoryFilter;
                const matchesFrom = !fromDate || rowDate >= fromDate;
                const matchesTo = !toDate || rowDate <= toDate;
                const show = matchesSearch && matchesCategory && matchesFrom && matchesTo;

                row.style.display = show ? '' : 'none';
                if (show) {
                    visibleCount++;
                }
            });

            if (noResultsRow) {
                noResultsRow.classList.toggle('hidden', visibleCount !== 0 || rows.length === 0);
            }

            const visibleCountEl = document.getElementById('visibleDistributionCount');
            if (visibleCountEl) {
                visibleCountEl.textContent = visibleCount;
            }
        }

        // ========== ESCAPE HTML ==========
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const viewModal = document.getElementById('viewModal');
            const editModal = document.getElementById('editModal');
            const deleteModal = document.getElementById('deleteModal');

            if (event.target == viewModal) {
                closeViewModal();
            }
            if (event.target == editModal) {
                closeEditModal();
            }
            if (event.target == deleteModal) {
                closeDeleteModal();
            }
        }

        // ESC key to close modals
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeViewModal();
                closeEditModal();
                closeDeleteModal();
            }
        });

        // Set up live search event listeners
        document.getElementById('distributionLiveSearch')?.addEventListener('input', filterDistributionHistoryLive);
        document.querySelector('#distributionHistoryForm select[name="filter_category"]')?.addEventListener('change', filterDistributionHistoryLive);
        document.querySelector('#distributionHistoryForm input[name="date_from"]')?.addEventListener('change', filterDistributionHistoryLive);
        document.querySelector('#distributionHistoryForm input[name="date_to"]')?.addEventListener('change', filterDistributionHistoryLive);
    </script>
</body>

</html>
