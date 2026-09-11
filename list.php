<?php
// list.php

require_once './config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/audit.php';

// Start session for messages
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// ========== HELPER FUNCTIONS ==========

/**
 * Set a toast message in session
 */
function setToast($type, $message)
{
    $_SESSION['toast'] = ['type' => $type, 'message' => $message];
}

// ========== CATEGORY HANDLERS ==========

// Handle Add Category
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_category_submit'])) {
    csrf_check_post();
    $category_name = trim($_POST['category_name']);
    $description = trim($_POST['description']);

    if (!empty($category_name)) {
        $stmt = $conn->prepare("INSERT INTO newspaper_categories (category_name, description) VALUES (?, ?)");
        $stmt->bind_param("ss", $category_name, $description);

        if ($stmt->execute()) {
            setToast('success', "Category added successfully!");
        } else {
            setToast('error', "Error adding category: " . $conn->error);
        }
        $stmt->close();
    } else {
        setToast('error', "Category name is required");
    }

    header('Location: list.php');
    exit();
}

// Handle Edit Category
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_category_submit'])) {
    csrf_check_post();
    $id = (int)$_POST['category_id'];
    $category_name = trim($_POST['category_name']);
    $description = trim($_POST['description']);

    if (!empty($category_name)) {
        $stmt = $conn->prepare("UPDATE newspaper_categories SET category_name = ?, description = ? WHERE id = ?");
        $stmt->bind_param("ssi", $category_name, $description, $id);

        if ($stmt->execute()) {
            setToast('success', "Category updated successfully!");
        } else {
            setToast('error', "Error updating category: " . $conn->error);
        }
        $stmt->close();
    } else {
        setToast('error', "Category name is required");
    }

    header('Location: list.php');
    exit();
}

// Handle Delete Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_category'])) {
    csrf_check_post();
    $id = (int)$_POST['delete_category'];

    // Check if category is used in newspapers
    $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM newspapers WHERE category_id = ?");
    $check_stmt->bind_param("i", $id);
    $check_stmt->execute();
    $row = $check_stmt->get_result()->fetch_assoc();
    $check_stmt->close();

    if ($row['count'] > 0) {
        setToast('error', "Cannot delete: This category has $row[count] newspaper(s)");
    } else {
        $stmt = $conn->prepare("DELETE FROM newspaper_categories WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            setToast('success', "Category deleted successfully!");
        } else {
            setToast('error', "Error deleting category: " . $conn->error);
        }
        $stmt->close();
    }

    header('Location: list.php');
    exit();
}

// ========== NEWSPAPER HANDLERS ==========

// Handle Add Newspaper
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_newspaper_submit'])) {
    csrf_check_post();
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $date_received = $_POST['date_received'];
    $copies_received = (int)$_POST['copies_received'];
    $received_by = trim($_POST['received_by']);

    // Get category name for newspaper name and issue number
    $cat_stmt = $conn->prepare("SELECT category_name FROM newspaper_categories WHERE id = ?");
    $cat_stmt->bind_param("i", $category_id);
    $cat_stmt->execute();
    $cat_row = $cat_stmt->get_result()->fetch_assoc();
    $cat_stmt->close();
    $newspaper_name = $cat_row['category_name'];

    // Generate issue number
    $newspaper_number = generateIssueNumber($newspaper_name, $date_received);

    // Insert into newspapers table
    $stmt = $conn->prepare("INSERT INTO newspapers (newspaper_name, newspaper_number, category_id, date_received, received_by, available_copies, status) VALUES (?, ?, ?, ?, ?, ?, 'available')");
    $stmt->bind_param("ssissi", $newspaper_name, $newspaper_number, $category_id, $date_received, $received_by, $copies_received);

    if ($stmt->execute()) {
        $id = (int)$stmt->insert_id;
        audit_log('create', 'newspaper', $id, "Added newspaper '$newspaper_name' ($newspaper_number) - $copies_received copies", $received_by);
        setToast('success', "Newspaper added successfully! Issue #: $newspaper_number");
    } else {
        setToast('error', "Error adding newspaper: " . $conn->error);
    }
    $stmt->close();

    header('Location: list.php');
    exit();
}

// Handle Delete Newspaper
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_newspaper'])) {
    csrf_check_post();
    $id = (int)$_POST['delete_newspaper'];

    // Check if newspaper is used in distribution
    $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM distribution WHERE newspaper_id = ?");
    $check_stmt->bind_param("i", $id);
    $check_stmt->execute();
    $row = $check_stmt->get_result()->fetch_assoc();
    $check_stmt->close();

    if ($row['count'] > 0) {
        setToast('error', "Cannot delete: This newspaper has been distributed");
    } else {
        $name_stmt = $conn->prepare("SELECT newspaper_name, newspaper_number FROM newspapers WHERE id = ?");
        $name_stmt->bind_param("i", $id);
        $name_stmt->execute();
        $name_row = $name_stmt->get_result()->fetch_assoc();
        $name_stmt->close();
        $paper_name = $name_row['newspaper_name'] ?? '';
        $paper_issue = $name_row['newspaper_number'] ?? '';

        $stmt = $conn->prepare("DELETE FROM newspapers WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            audit_log('delete', 'newspaper', $id, "Deleted newspaper '$paper_name' ($paper_issue)");
            setToast('success', "Newspaper deleted successfully!");
        } else {
            setToast('error', "Error deleting newspaper: " . $conn->error);
        }
        $stmt->close();
    }

    header('Location: list.php');
    exit();
}

// Handle Update Newspaper Copies
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_copies_submit'])) {
    csrf_check_post();
    $id = (int)$_POST['newspaper_id'];
    $available_copies = (int)$_POST['available_copies'];

    $stmt = $conn->prepare("UPDATE newspapers SET available_copies = ? WHERE id = ?");
    $stmt->bind_param("ii", $available_copies, $id);

    if ($stmt->execute()) {
        $name_stmt = $conn->prepare("SELECT newspaper_name, newspaper_number FROM newspapers WHERE id = ?");
        $name_stmt->bind_param("i", $id);
        $name_stmt->execute();
        $name_row = $name_stmt->get_result()->fetch_assoc();
        $name_stmt->close();
        audit_log('update', 'newspaper', $id, "Updated newspaper '{$name_row['newspaper_name']}' ({$name_row['newspaper_number']})", 'System');
        // Update status based on available copies
        if ($available_copies == 0) {
            $status_stmt = $conn->prepare("UPDATE newspapers SET status = 'distributed' WHERE id = ?");
        } else {
            $status_stmt = $conn->prepare("UPDATE newspapers SET status = 'available' WHERE id = ?");
        }
        $status_stmt->bind_param("i", $id);
        $status_stmt->execute();
        $status_stmt->close();
        setToast('success', "Newspaper copies updated successfully!");
    } else {
        setToast('error', "Error updating copies: " . $conn->error);
    }
    $stmt->close();

    // Preserve filters and pagination
    $query_params = $_GET;
    $redirect_url = 'list.php' . (!empty($query_params) ? '?' . http_build_query($query_params) : '');
    header('Location: ' . $redirect_url);
    exit();
}

// Handle Discontinue / Continue Newspaper
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
    csrf_check_post();
    $id = (int)$_POST['toggle_status'];
    $stmt = $conn->prepare("SELECT status, available_copies, newspaper_name FROM newspapers WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $paper = $result->fetch_assoc();
    $stmt->close();

    if ($paper) {
        if ($paper['status'] === 'archived') {
            $new_status = $paper['available_copies'] > 0 ? 'available' : 'distributed';
            setToast('success', 'Newspaper has been continued for distribution.');
        } else {
            $new_status = 'archived';
            setToast('info', 'Newspaper has been discontinued from distribution.');
        }

        $update = $conn->prepare("UPDATE newspapers SET status = ? WHERE id = ?");
        $update->bind_param('si', $new_status, $id);
        if ($update->execute()) {
            $audit_action = $new_status === 'archived' ? 'Archived' : 'Unarchived';
            audit_log('update', 'newspaper', $id, "$audit_action newspaper '{$paper['newspaper_name']}'", 'System');
        }
        $update->close();
    } else {
        setToast('error', 'Invalid newspaper selected.');
    }

    header('Location: list.php');
    exit();
}

// ========== GET DATA FOR DISPLAY ==========

// Get all categories
$categories_query = $conn->query("SELECT * FROM newspaper_categories ORDER BY category_name");
$all_categories = [];
while ($cat = $categories_query->fetch_assoc()) {
    $all_categories[] = $cat;
}

// Pagination settings for newspapers
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page);
$limit = 10;
$offset = ($page - 1) * $limit;

// Filter settings for newspapers
$filter_category = isset($_GET['filter_category']) ? (int)$_GET['filter_category'] : 0;
$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'date_received';
$sort_order = isset($_GET['sort_order']) ? $_GET['sort_order'] : 'DESC';

$has_active_filters = $filter_category > 0 || $filter_status !== '' || $search !== '';

$allowed_sort_fields = ['date_received', 'newspaper_name', 'category_name', 'status', 'available_copies'];
if (!in_array($sort_by, $allowed_sort_fields, true)) {
    $sort_by = 'date_received';
}
$sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';

$allowed_statuses = ['available', 'partial', 'distributed', 'archived', 'pending'];
if ($filter_status !== '' && !in_array($filter_status, $allowed_statuses, true)) {
    $filter_status = '';
}

// Build query for all newspapers with filters (use prepared statements for LIKE)
$where_clauses = [];
$where_params = [];
$where_types = '';
if ($filter_category > 0) {
    $where_clauses[] = "n.category_id = ?";
    $where_params[] = $filter_category;
    $where_types .= 'i';
}
if (!empty($filter_status)) {
    $where_clauses[] = "n.status = ?";
    $where_params[] = $filter_status;
    $where_types .= 's';
}
if (!empty($search)) {
    $where_clauses[] = "(n.newspaper_name LIKE ? OR n.newspaper_number LIKE ?)";
    $search_pattern = '%' . $search . '%';
    $where_params[] = $search_pattern;
    $where_params[] = $search_pattern;
    $where_types .= 'ss';
}
$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM newspapers n $where_sql";
if (!empty($where_params)) {
    $count_stmt = $conn->prepare($count_query);
    $count_stmt->bind_param($where_types, ...$where_params);
    $count_stmt->execute();
    $total_rows = $count_stmt->get_result()->fetch_assoc()['total'];
    $count_stmt->close();
} else {
    $total_rows = $conn->query($count_query)->fetch_assoc()['total'];
}
$total_pages = $total_rows > 0 ? ceil($total_rows / $limit) : 1;

// Get all newspapers for management with filters, sorting and pagination
$main_query = "SELECT n.*, nc.category_name 
               FROM newspapers n 
               LEFT JOIN newspaper_categories nc ON n.category_id = nc.id 
               $where_sql
               ORDER BY 
                   CASE WHEN ? = 'date_received' THEN n.date_received END $sort_order,
                   CASE WHEN ? = 'newspaper_name' THEN n.newspaper_name END $sort_order,
                   CASE WHEN ? = 'category_name' THEN nc.category_name END $sort_order,
                   CASE WHEN ? = 'status' THEN n.status END $sort_order,
                   CASE WHEN ? = 'available_copies' THEN n.available_copies END $sort_order
               LIMIT ?, ?";
$main_params = array_merge($where_params, [$sort_by, $sort_by, $sort_by, $sort_by, $sort_by, $offset, $limit]);
$main_types = $where_types . 'ssssiii';
$main_stmt = $conn->prepare($main_query);
$main_stmt->bind_param($main_types, ...$main_params);
$main_stmt->execute();
$all_newspapers = $main_stmt->get_result();
$main_stmt->close();

// Get all newspapers for export matching filters (without pagination limit)
$all_newspapers_export = [];
$export_query = "SELECT n.*, nc.category_name 
                 FROM newspapers n 
                 LEFT JOIN newspaper_categories nc ON n.category_id = nc.id 
                 $where_sql
                 ORDER BY 
                     CASE WHEN ? = 'date_received' THEN n.date_received END $sort_order,
                     CASE WHEN ? = 'newspaper_name' THEN n.newspaper_name END $sort_order,
                     CASE WHEN ? = 'category_name' THEN nc.category_name END $sort_order,
                     CASE WHEN ? = 'status' THEN n.status END $sort_order,
                     CASE WHEN ? = 'available_copies' THEN n.available_copies END $sort_order";
$export_params = array_merge($where_params, [$sort_by, $sort_by, $sort_by, $sort_by, $sort_by]);
$export_types = $where_types . 'sssss';
$export_stmt = $conn->prepare($export_query);
$export_stmt->bind_param($export_types, ...$export_params);
$export_stmt->execute();
$export_res = $export_stmt->get_result();
$export_stmt->close();
if ($export_res) {
    while ($row = $export_res->fetch_assoc()) {
        $all_newspapers_export[] = [
            'id' => $row['id'],
            'newspaper_name' => $row['newspaper_name'],
            'newspaper_number' => $row['newspaper_number'],
            'category_name' => $row['category_name'] ?? 'Uncategorized',
            'date_received' => $row['date_received'] ? date('Y-m-d', strtotime($row['date_received'])) : '',
            'status' => ucfirst($row['status']),
            'available_copies' => $row['available_copies']
        ];
    }
}

// Get statistics
$total_available = $conn->query("SELECT SUM(available_copies) as total FROM newspapers")->fetch_assoc()['total'] ?? 0;
$total_categories = $conn->query("SELECT COUNT(*) as count FROM newspaper_categories")->fetch_assoc()['count'] ?? 0;

$stats_sql = "SELECT 
                COUNT(*) as total_newspapers,
                SUM(CASE WHEN YEAR(date_received) = YEAR(CURDATE()) THEN 1 ELSE 0 END) as yearly_newspapers,
                SUM(CASE WHEN MONTH(date_received) = MONTH(CURDATE()) AND YEAR(date_received) = YEAR(CURDATE()) THEN 1 ELSE 0 END) as monthly_newspapers,
                SUM(CASE WHEN YEARWEEK(date_received, 1) = YEARWEEK(CURDATE(), 1) THEN 1 ELSE 0 END) as weekly_newspapers,
                SUM(CASE WHEN date_received = CURDATE() THEN 1 ELSE 0 END) as daily_newspapers
              FROM newspapers";

$stats = [
    'total_newspapers' => 0,
    'yearly_newspapers' => 0,
    'monthly_newspapers' => 0,
    'weekly_newspapers' => 0,
    'daily_newspapers' => 0
];
$stats_result = $conn->query($stats_sql);
if ($stats_result && $row = $stats_result->fetch_assoc()) {
    $stats = [
        'total_newspapers' => (int)($row['total_newspapers'] ?? 0),
        'yearly_newspapers' => (int)($row['yearly_newspapers'] ?? 0),
        'monthly_newspapers' => (int)($row['monthly_newspapers'] ?? 0),
        'weekly_newspapers' => (int)($row['weekly_newspapers'] ?? 0),
        'daily_newspapers' => (int)($row['daily_newspapers'] ?? 0)
    ];
}

$today = date('Y-m-d');
$active_tab = (isset($_GET['tab']) && $_GET['tab'] === 'statistics') ? 'statistics' : 'newspapers';

$category_stats = [];
$category_result = $conn->query("SELECT nc.id, nc.category_name,
        SUM(CASE WHEN n.date_received = '$today' THEN COALESCE(NULLIF(n.total_copies, 0), n.available_copies) ELSE 0 END) as daily_count,
        SUM(CASE WHEN YEARWEEK(n.date_received, 1) = YEARWEEK('$today', 1) THEN COALESCE(NULLIF(n.total_copies, 0), n.available_copies) ELSE 0 END) as weekly_count,
        SUM(CASE WHEN MONTH(n.date_received) = MONTH('$today') AND YEAR(n.date_received) = YEAR('$today') THEN COALESCE(NULLIF(n.total_copies, 0), n.available_copies) ELSE 0 END) as monthly_count,
        SUM(CASE WHEN YEAR(n.date_received) = YEAR('$today') THEN COALESCE(NULLIF(n.total_copies, 0), n.available_copies) ELSE 0 END) as yearly_count,
        SUM(COALESCE(NULLIF(n.total_copies, 0), n.available_copies)) as total_count
    FROM newspaper_categories nc
    LEFT JOIN newspapers n ON n.category_id = nc.id
    GROUP BY nc.id, nc.category_name
    ORDER BY nc.category_name");
if ($category_result) {
    while ($row = $category_result->fetch_assoc()) {
        $category_stats[] = [
            'category_name' => $row['category_name'],
            'daily_count' => (int)$row['daily_count'],
            'weekly_count' => (int)$row['weekly_count'],
            'monthly_count' => (int)$row['monthly_count'],
            'yearly_count' => (int)$row['yearly_count'],
            'total_count' => (int)$row['total_count'],
        ];
    }
}

// ─── Build a pagination URL preserving current filters ────────────────────────
function buildListUrl($overrides = [])
{
    $params = $_GET;
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    return 'list.php' . (!empty($params) ? '?' . http_build_query($params) : '');
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
    <title>Newspaper Management - Mailroom</title>
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
                        <span>Newspapers</span>
                    </div>
                    <h1 class="page-header-title">Newspaper Management</h1>
                    <p class="page-header-subtitle">Manage newspapers and track distributions.</p>
                </div>
                <div class="header-actions flex items-center gap-2 no-print">
                    <button onclick="openAddModal()" class="btn btn-primary">
                        <i class="fa-solid fa-plus"></i>
                        <span class="hidden sm:inline">Add Newspaper</span>
                    </button>
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

                <!-- Tabs -->
                <div class="tabs no-print">
                    <button type="button" class="tab-button <?php echo $active_tab === 'newspapers' ? 'active' : ''; ?>" data-page-tab="newspapers">
                        <i class="fa-solid fa-newspaper"></i> Newspapers
                    </button>
                    <button type="button" class="tab-button <?php echo $active_tab === 'statistics' ? 'active' : ''; ?>" data-page-tab="statistics">
                        <i class="fa-solid fa-chart-column"></i> Statistics
                    </button>
                </div>

                <!-- Newspapers Pane -->
                <div id="newspapersPane" class="<?php echo $active_tab === 'newspapers' ? '' : 'hidden'; ?>">
                    <!-- Stats Cards -->
                    <div class="stat-grid mb-6">
                        <div class="stat-card">
                            <div class="stat-icon blue"><i class="fa-solid fa-newspaper"></i></div>
                            <div class="stat-label">Total Received</div>
                            <div class="stat-value"><?php echo number_format($stats['total_newspapers']); ?></div>
                            <div class="stat-hint">All-time received</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon gold"><i class="fa-solid fa-calendar"></i></div>
                            <div class="stat-label">This Year</div>
                            <div class="stat-value"><?php echo number_format($stats['yearly_newspapers']); ?></div>
                            <div class="stat-hint">This calendar year</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon green"><i class="fa-solid fa-calendar-days"></i></div>
                            <div class="stat-label">This Month</div>
                            <div class="stat-value"><?php echo number_format($stats['monthly_newspapers']); ?></div>
                            <div class="stat-hint">This calendar month</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon orange"><i class="fa-solid fa-calendar-week"></i></div>
                            <div class="stat-label">This Week</div>
                            <div class="stat-value"><?php echo number_format($stats['weekly_newspapers']); ?></div>
                            <div class="stat-hint">This week</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon red"><i class="fa-solid fa-calendar-day"></i></div>
                            <div class="stat-label">Today</div>
                            <div class="stat-value"><?php echo number_format($stats['daily_newspapers']); ?></div>
                            <div class="stat-hint">Received today</div>
                        </div>
                    </div>

                    <!-- Filters -->
                    <div class="card mb-6 no-print">
                        <div class="card-body">
                            <form method="GET" id="newspaperFilterForm" class="filter-bar" style="margin-bottom:0;">
                                <input type="hidden" name="page" value="1">
                                <div class="search-wrap">
                                    <i class="fa-solid fa-magnifying-glass icon"></i>
                                    <input type="text" id="newspaperLiveSearch" name="search"
                                        class="input" style="width:240px;" autocomplete="off"
                                        placeholder="Search by name or issue number..."
                                        value="<?php echo htmlspecialchars($search); ?>">
                                </div>
                                <select name="filter_category" class="select">
                                    <option value="0">All Categories</option>
                                    <?php foreach ($all_categories as $cat): ?>
                                        <option value="<?php echo $cat['id']; ?>" <?php echo $filter_category == $cat['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cat['category_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="filter_status" class="select">
                                    <option value="">All Statuses</option>
                                    <?php foreach ($allowed_statuses as $status_option): ?>
                                        <option value="<?php echo $status_option; ?>" <?php echo $filter_status === $status_option ? 'selected' : ''; ?>>
                                            <?php echo ucfirst($status_option); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="sort_by" class="select">
                                    <option value="date_received" <?php echo $sort_by == 'date_received' ? 'selected' : ''; ?>>Sort by Date</option>
                                    <option value="newspaper_name" <?php echo $sort_by == 'newspaper_name' ? 'selected' : ''; ?>>Sort by Name</option>
                                    <option value="category_name" <?php echo $sort_by == 'category_name' ? 'selected' : ''; ?>>Sort by Category</option>
                                    <option value="available_copies" <?php echo $sort_by == 'available_copies' ? 'selected' : ''; ?>>Sort by Copies</option>
                                </select>
                                <select name="sort_order" class="select">
                                    <option value="DESC" <?php echo $sort_order == 'DESC' ? 'selected' : ''; ?>>Descending</option>
                                    <option value="ASC" <?php echo $sort_order == 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                                </select>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fa-solid fa-sliders"></i> Filter
                                </button>
                                <a href="list.php" class="btn btn-soft">
                                    <i class="fa-solid fa-rotate-right"></i> Reset
                                </a>
                                <button type="button" onclick="printNewspaperList()" class="btn btn-soft">
                                    <i class="fa-solid fa-print"></i> Print
                                </button>
                                <button type="button" onclick="exportNewspapers()" class="btn btn-soft">
                                    <i class="fa-regular fa-file-excel"></i> Export CSV
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Active Filters Display -->
                    <?php if ($has_active_filters): ?>
                        <div class="filter-bar" style="margin-bottom:16px;">
                            <?php if ($filter_category > 0):
                                $cat_name = '';
                                foreach ($all_categories as $cat) {
                                    if ($cat['id'] == $filter_category) {
                                        $cat_name = $cat['category_name'];
                                        break;
                                    }
                                }
                            ?>
                                <span class="filter-chip">
                                    Category: <?php echo htmlspecialchars($cat_name); ?>
                                    <a href="<?php echo htmlspecialchars(buildListUrl(['filter_category' => 0, 'page' => 1])); ?>"><i class="fa-solid fa-xmark"></i></a>
                                </span>
                            <?php endif; ?>

                            <?php if (!empty($filter_status)): ?>
                                <span class="filter-chip">
                                    Status: <?php echo htmlspecialchars(ucfirst($filter_status)); ?>
                                    <a href="<?php echo htmlspecialchars(buildListUrl(['filter_status' => '', 'page' => 1])); ?>"><i class="fa-solid fa-xmark"></i></a>
                                </span>
                            <?php endif; ?>

                            <?php if (!empty($search)): ?>
                                <span class="filter-chip">
                                    Search: "<?php echo htmlspecialchars($search); ?>"
                                    <a href="<?php echo htmlspecialchars(buildListUrl(['search' => '', 'page' => 1])); ?>"><i class="fa-solid fa-xmark"></i></a>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Newspapers Table -->
                    <div class="card">
                        <div class="card-header" style="padding:14px 20px;">
                            <div>
                                <div class="card-title">Newspaper Records</div>
                                <div class="card-subtitle">
                                    <?php if ($total_rows > 0): ?>
                                        Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $limit, $total_rows); ?> of <?php echo number_format($total_rows); ?>
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
                                        <th class="hidden md:table-cell">ID</th>
                                        <th>Newspaper</th>
                                        <th class="hidden md:table-cell">Issue #</th>
                                        <th>Category</th>
                                        <th>Date Received</th>
                                        <th>Status</th>
                                        <th>Available</th>
                                        <th class="no-print" style="width:120px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($all_newspapers && $all_newspapers->num_rows > 0): ?>
                                        <?php while ($paper = $all_newspapers->fetch_assoc()): ?>
                                            <?php
                                            $paper_status = $paper['status'] ?? 'pending';
                                            $badge_map = [
                                                'available' => 'badge-green',
                                                'partial' => 'badge-orange',
                                                'distributed' => 'badge-gray',
                                                'archived' => 'badge-red',
                                                'pending' => 'badge-blue',
                                            ];
                                            ?>
                                            <tr class="newspaper-row" id="newspaper-row-<?php echo $paper['id']; ?>"
                                                data-search="<?php echo strtolower(htmlspecialchars(trim($paper['id'] . ' ' . ($paper['newspaper_name'] ?? '') . ' ' . ($paper['newspaper_number'] ?? '') . ' ' . ($paper['category_name'] ?? '') . ' ' . ($paper['status'] ?? '') . ' ' . ($paper['available_copies'] ?? 0) . ' ' . date('M j, Y', strtotime($paper['date_received']))))); ?>"
                                                data-category="<?php echo (int)($paper['category_id'] ?? 0); ?>"
                                                data-status="<?php echo strtolower($paper['status'] ?? ''); ?>">
                                                <td class="table-cell-mono hidden md:table-cell text-[#7d8398]"><?php echo $paper['id']; ?></td>
                                                <td><span class="table-cell-title"><?php echo htmlspecialchars($paper['newspaper_name']); ?></span></td>
                                                <td class="table-cell-mono hidden md:table-cell"><?php echo htmlspecialchars($paper['newspaper_number']); ?></td>
                                                <td><?php echo htmlspecialchars($paper['category_name'] ?? 'Uncategorized'); ?></td>
                                                <td><?php echo date('M j, Y', strtotime($paper['date_received'])); ?></td>
                                                <td>
                                                    <span class="badge <?php echo $badge_map[$paper_status] ?? 'badge-gray'; ?>">
                                                        <?php echo ucfirst($paper_status); ?>
                                                    </span>
                                                </td>
                                                <td><span class="table-cell-mono"><?php echo (int)$paper['available_copies']; ?></span></td>
                                                <td class="no-print">
                                                    <div class="row-actions">
                                                        <button class="icon-btn primary" onclick="viewNewspaper(<?php echo htmlspecialchars(json_encode($paper)); ?>)" title="View Details">
                                                            <i class="fa-regular fa-eye"></i>
                                                        </button>
                                                        <button class="icon-btn primary" onclick="openUpdateModal(<?php echo $paper['id']; ?>, '<?php echo htmlspecialchars($paper['newspaper_name']); ?>', <?php echo (int)$paper['available_copies']; ?>)" title="Edit">
                                                            <i class="fa-regular fa-pen-to-square"></i>
                                                        </button>
                                                        <?php
                                                        $is_archived = $paper_status === 'archived';
                                                        $toggle_label = $is_archived ? 'Continue' : 'Discontinue';
                                                        $toggle_icon = $is_archived ? 'fa-play' : 'fa-ban';
                                                        $toggle_color = $is_archived ? 'var(--green)' : 'var(--orange)';
                                                        ?>
                                                        <form method="POST" action="list.php" style="display:inline;">
                                                            <?php csrf_field(); ?>
                                                            <input type="hidden" name="toggle_status" value="<?php echo $paper['id']; ?>">
                                                            <button type="submit" class="icon-btn primary" style="color:<?php echo $toggle_color; ?>;" title="<?php echo $toggle_label; ?>">
                                                                <i class="fa-solid <?php echo $toggle_icon; ?>"></i>
                                                            </button>
                                                        </form>
                                                        <button class="icon-btn danger"
                                                            onclick="openDeleteModal(<?php echo $paper['id']; ?>, '<?php echo htmlspecialchars($paper['newspaper_name']); ?>', '<?php echo htmlspecialchars($paper['newspaper_number']); ?>')"
                                                            title="Delete">
                                                            <i class="fa-regular fa-trash-can"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                        <tr id="newspaperNoResultsRow" class="hidden">
                                            <td colspan="8">
                                                <div class="empty-state">
                                                    <div class="empty-state-icon"><i class="fa-regular fa-magnifying-glass"></i></div>
                                                    <div class="empty-state-title">No newspapers match the current live search</div>
                                                    <div class="empty-state-text">Try adjusting your search or filter criteria on this page.</div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8">
                                                <div class="empty-state">
                                                    <div class="empty-state-icon"><i class="fa-regular fa-newspaper"></i></div>
                                                    <div class="empty-state-title">No newspapers found</div>
                                                    <div class="empty-state-text">Add a newspaper to start receiving subscriptions.</div>
                                                    <button onclick="openAddModal()" class="btn btn-primary btn-sm" style="margin-top:16px;">
                                                        <i class="fa-solid fa-plus"></i> Add Newspaper
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <?php
                            $pageStart = $total_rows > 0 ? $offset + 1 : 0;
                            $pageEnd = min($offset + ($all_newspapers ? $all_newspapers->num_rows : 0), $total_rows);
                            $start = max(1, $page - 2);
                            $end = min($total_pages, $page + 2);
                            ?>
                            <div class="pagination-shell no-print">
                                <div class="pagination-meta">
                                    <div class="pagination-title">Showing <span id="visibleNewspaperCount"><?php echo $all_newspapers ? $all_newspapers->num_rows : 0; ?></span> item<?php echo ($all_newspapers && $all_newspapers->num_rows == 1) ? '' : 's'; ?> on this page</div>
                                    <span>Records <?php echo $pageStart; ?>-<?php echo $pageEnd; ?> of <?php echo $total_rows; ?> total</span>
                                </div>
                                <div class="pagination-controls">
                                    <div class="pagination-page-indicator">Page <?php echo $page; ?> of <?php echo $total_pages; ?></div>
                                    <div class="pagination">
                                        <a href="<?php echo htmlspecialchars(buildListUrl(['page' => 1])); ?>" class="pagination-item compact <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                            <i class="fa-solid fa-chevrons-left"></i>
                                        </a>
                                        <a href="<?php echo htmlspecialchars(buildListUrl(['page' => max(1, $page - 1)])); ?>" class="pagination-item compact <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                            <i class="fa-solid fa-chevron-left"></i>
                                        </a>

                                        <?php if ($start > 1): ?>
                                            <a href="<?php echo htmlspecialchars(buildListUrl(['page' => 1])); ?>" class="pagination-item">1</a>
                                            <?php if ($start > 2): ?>
                                                <span class="pagination-ellipsis">...</span>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <?php for ($i = $start; $i <= $end; $i++): ?>
                                            <a href="<?php echo htmlspecialchars(buildListUrl(['page' => $i])); ?>"
                                                class="pagination-item <?php echo $i == $page ? 'active' : ''; ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        <?php endfor; ?>

                                        <?php if ($end < $total_pages): ?>
                                            <?php if ($end < $total_pages - 1): ?>
                                                <span class="pagination-ellipsis">...</span>
                                            <?php endif; ?>
                                            <a href="<?php echo htmlspecialchars(buildListUrl(['page' => $total_pages])); ?>" class="pagination-item"><?php echo $total_pages; ?></a>
                                        <?php endif; ?>

                                        <a href="<?php echo htmlspecialchars(buildListUrl(['page' => min($total_pages, $page + 1)])); ?>" class="pagination-item compact <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                            <i class="fa-solid fa-chevron-right"></i>
                                        </a>
                                        <a href="<?php echo htmlspecialchars(buildListUrl(['page' => $total_pages])); ?>" class="pagination-item compact <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                            <i class="fa-solid fa-chevrons-right"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Statistics Pane -->
                <div id="statisticsPane" class="<?php echo $active_tab === 'statistics' ? '' : 'hidden'; ?>">
                    <?php if (!empty($category_stats)): ?>
                        <?php
                        $stats_totals = [
                            'total_count' => 0,
                            'daily_count' => 0,
                            'weekly_count' => 0,
                            'monthly_count' => 0,
                            'yearly_count' => 0,
                        ];
                        foreach ($category_stats as $cat) {
                            foreach ($stats_totals as $key => $value) {
                                $stats_totals[$key] += $cat[$key];
                            }
                        }
                        ?>
                        <div class="card">
                            <div class="card-header" style="padding:14px 20px;">
                                <div>
                                    <div class="card-title">Newspaper Statistics</div>
                                    <div class="card-subtitle">Copy counts by category</div>
                                </div>
                                <button type="button" onclick="printNewspaperStatistics()" class="btn btn-soft no-print">
                                    <i class="fa-solid fa-print"></i> Print
                                </button>
                            </div>
                            <div class="table-wrap">
                                <table class="table" id="newspaperStatsTable">
                                    <thead>
                                        <tr>
                                            <th>Category</th>
                                            <th style="text-align:right;">Total received</th>
                                            <th style="text-align:right;">Today</th>
                                            <th style="text-align:right;">This week</th>
                                            <th style="text-align:right;">This month</th>
                                            <th style="text-align:right;">This year</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($category_stats as $cat): ?>
                                            <tr>
                                                <td><span class="table-cell-title"><?php echo htmlspecialchars($cat['category_name']); ?></span></td>
                                                <td style="text-align:right;"><span class="table-cell-mono"><?php echo number_format($cat['total_count']); ?></span></td>
                                                <td style="text-align:right;"><span class="table-cell-mono"><?php echo number_format($cat['daily_count']); ?></span></td>
                                                <td style="text-align:right;"><span class="table-cell-mono"><?php echo number_format($cat['weekly_count']); ?></span></td>
                                                <td style="text-align:right;"><span class="table-cell-mono"><?php echo number_format($cat['monthly_count']); ?></span></td>
                                                <td style="text-align:right;"><span class="table-cell-mono"><?php echo number_format($cat['yearly_count']); ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td><strong>Total</strong></td>
                                            <td style="text-align:right;"><span class="table-cell-mono"><strong><?php echo number_format($stats_totals['total_count']); ?></strong></span></td>
                                            <td style="text-align:right;"><span class="table-cell-mono"><strong><?php echo number_format($stats_totals['daily_count']); ?></strong></span></td>
                                            <td style="text-align:right;"><span class="table-cell-mono"><strong><?php echo number_format($stats_totals['weekly_count']); ?></strong></span></td>
                                            <td style="text-align:right;"><span class="table-cell-mono"><strong><?php echo number_format($stats_totals['monthly_count']); ?></strong></span></td>
                                            <td style="text-align:right;"><span class="table-cell-mono"><strong><?php echo number_format($stats_totals['yearly_count']); ?></strong></span></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="card">
                            <div class="card-body">
                                <div class="empty-state">
                                    <div class="empty-state-icon"><i class="fa-solid fa-chart-column"></i></div>
                                    <div class="empty-state-title">No category statistics available</div>
                                    <div class="empty-state-text">Statistics will appear here once newspapers are added.</div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Newspaper Modal -->
    <div id="addModal" class="modal-backdrop" style="display:none;">
        <div class="modal-dialog">
            <div class="modal-header">
                <h2 class="modal-title">Add Newspaper</h2>
                <button type="button" onclick="MailroomModal.close('addModal')" class="modal-close"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <!-- Preview of generated issue number -->
                <div class="notice-bar" style="margin-bottom:16px;">
                    <i class="fa-solid fa-file-signature"></i>
                    <div style="flex:1;">
                        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:2px;">Auto-generated Issue #</div>
                        <div id="previewIssueNumber" class="table-cell-mono" style="color:var(--text);">—</div>
                    </div>
                </div>

                <form method="POST" action="list.php">
                    <?php csrf_field(); ?>
                    <div class="form-grid">
                        <div class="form-field span-2">
                            <label class="label">Category <span class="req">*</span></label>
                            <select name="category_id" id="categorySelect" required
                                onchange="updateIssuePreview()" class="select">
                                <option value="">Select category</option>
                                <?php foreach ($all_categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>" data-name="<?php echo htmlspecialchars($cat['category_name']); ?>">
                                        <?php echo htmlspecialchars($cat['category_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-field">
                            <label class="label">Date Received <span class="req">*</span></label>
                            <input type="date" name="date_received" id="dateReceived" required value="<?php echo date('Y-m-d'); ?>"
                                onchange="updateIssuePreview()" class="input" autocomplete="off">
                        </div>
                        <div class="form-field">
                            <label class="label">Copies <span class="req">*</span></label>
                            <input type="number" name="copies_received" min="1" required
                                class="input" placeholder="e.g., 5" autocomplete="off">
                        </div>
                        <div class="form-field span-2">
                            <label class="label">Received By <span class="req">*</span></label>
                            <input type="text" name="received_by" required
                                class="input" placeholder="Staff name" autocomplete="off">
                        </div>
                    </div>

                    <div class="notice-bar" style="margin-top:16px;">
                        <i class="fa-solid fa-circle-info"></i>
                        <span>Issue number will be auto-generated based on category and date.</span>
                    </div>

                    <div class="modal-footer" style="padding:16px 0 0;border-top:none;">
                        <button type="button" onclick="MailroomModal.close('addModal')" class="btn btn-soft">Cancel</button>
                        <button type="submit" name="add_newspaper_submit" class="btn btn-primary">
                            <i class="fa-solid fa-floppy-disk"></i> Add Newspaper
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Update Newspaper Copies Modal -->
    <div id="updateModal" class="modal-backdrop" style="display:none;">
        <div class="modal-dialog sm">
            <div class="modal-header">
                <h2 class="modal-title">Update Available Copies</h2>
                <button type="button" onclick="MailroomModal.close('updateModal')" class="modal-close"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <form method="POST" action="list.php">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="newspaper_id" id="update_id">
                    <p class="text-sm font-medium" style="color:var(--text);margin-bottom:16px;" id="update_name"></p>
                    <label class="label">Available Copies</label>
                    <input type="number" name="available_copies" id="update_copies" min="0" required class="input" autocomplete="off">
                    <div class="modal-footer" style="padding:16px 0 0;border-top:none;">
                        <button type="button" onclick="MailroomModal.close('updateModal')" class="btn btn-soft">Cancel</button>
                        <button type="submit" name="update_copies_submit" class="btn btn-primary">
                            <i class="fa-solid fa-floppy-disk"></i> Update
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Category Modal -->
    <div id="addCategoryModal" class="modal-backdrop" style="display:none;">
        <div class="modal-dialog">
            <div class="modal-header">
                <h2 class="modal-title">Add Category</h2>
                <button type="button" onclick="MailroomModal.close('addCategoryModal')" class="modal-close"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <form method="POST" action="list.php">
                    <?php csrf_field(); ?>
                    <div class="form-grid">
                        <div class="form-field span-2">
                            <label class="label">Category Name <span class="req">*</span></label>
                            <input type="text" name="category_name" required
                                class="input" placeholder="e.g., Daily News, Sports, Business" autocomplete="off">
                        </div>
                        <div class="form-field span-2">
                            <label class="label">Description</label>
                            <textarea name="description" rows="3" placeholder="Optional description"
                                class="textarea"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer" style="padding:16px 0 0;border-top:none;">
                        <button type="button" onclick="MailroomModal.close('addCategoryModal')" class="btn btn-soft">Cancel</button>
                        <button type="submit" name="add_category_submit" class="btn btn-primary">
                            <i class="fa-solid fa-floppy-disk"></i> Add Category
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Newspaper Modal -->
    <div id="viewModal" class="modal-backdrop" style="display:none;">
        <div class="modal-dialog">
            <div class="modal-header">
                <h2 class="modal-title">Newspaper Details</h2>
                <button type="button" onclick="MailroomModal.close('viewModal')" class="modal-close"><i class="fa-solid fa-xmark text-xl"></i></button>
            </div>
            <div class="modal-body">
                <div id="viewContent" class="grid grid-cols-2 gap-3">
                    <!-- Content will be filled by JavaScript -->
                </div>
            </div>
            <div class="modal-footer">
                <button onclick="MailroomModal.close('viewModal')" class="btn btn-soft">Close</button>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal-backdrop" style="display:none;">
        <div class="modal-dialog sm">
            <div class="modal-header">
                <h2 class="modal-title">Confirm Delete</h2>
                <button type="button" onclick="MailroomModal.close('deleteModal')" class="modal-close"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <p style="color:var(--text-secondary);font-size:13px;margin-bottom:14px;">Are you sure you want to delete this newspaper?</p>
                <div class="alert alert-red" style="margin-bottom:0;">
                    <i class="fa-solid fa-trash-can"></i>
                    <div>
                        <p class="font-medium text-sm" id="deleteNewspaperName"></p>
                        <p class="text-xs mt-1" id="deleteIssueNumber"></p>
                        <p class="text-xs mt-2" style="opacity:.85;">This action cannot be undone. The newspaper will be permanently deleted.</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button onclick="MailroomModal.close('deleteModal')" class="btn btn-soft">Cancel</button>
                <form method="POST" action="list.php" id="deleteForm" style="display:inline;">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="delete_newspaper" id="delete_newspaper_input" value="">
                    <button type="submit" class="btn btn-danger">
                        Delete Permanently
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        <?php if ($toast): ?>
            document.addEventListener('DOMContentLoaded', function() {
                MailroomToast.<?php echo $toast['type']; ?>(<?php echo json_encode($toast['message']); ?>);
            });
        <?php endif; ?>

        // ========== ISSUE NUMBER PREVIEW ==========
        function updateIssuePreview() {
            const categorySelect = document.getElementById('categorySelect');
            const dateInput = document.getElementById('dateReceived');
            const previewEl = document.getElementById('previewIssueNumber');

            if (categorySelect.value && dateInput.value) {
                const selectedOption = categorySelect.options[categorySelect.selectedIndex];
                const categoryName = selectedOption.getAttribute('data-name') || '';

                const categoryPrefix = categoryName.replace(/[^a-zA-Z0-9]/g, '').substring(0, 3).toUpperCase();
                const datePrefix = dateInput.value.replace(/-/g, '');

                previewEl.textContent = categoryPrefix + '-' + datePrefix + '-001';
            } else {
                previewEl.textContent = '—';
            }
        }

        // ========== NEWSPAPER MODAL FUNCTIONS ==========
        function openAddModal() {
            MailroomModal.open('addModal');
            updateIssuePreview();
        }

        function openUpdateModal(id, name, copies) {
            document.getElementById('update_id').value = id;
            document.getElementById('update_name').textContent = name;
            document.getElementById('update_copies').value = copies;
            MailroomModal.open('updateModal');
        }

        // ========== VIEW MODAL FUNCTIONS ==========
        function viewNewspaper(paper) {
            const content = document.getElementById('viewContent');

            content.innerHTML = `
                <div class="col-span-2">
                    <p style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:4px;">Newspaper</p>
                    <p class="text-sm font-medium" style="color:var(--text);">${escapeHtml(paper.newspaper_name)}</p>
                </div>
                <div class="col-span-2">
                    <p style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:4px;">Issue Number</p>
                    <p class="table-cell-mono" style="color:var(--text);">${escapeHtml(paper.newspaper_number)}</p>
                </div>
                <div>
                    <p style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:4px;">Category</p>
                    <p class="text-sm" style="color:var(--text-secondary);">${escapeHtml(paper.category_name || 'Uncategorized')}</p>
                </div>
                <div>
                    <p style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:4px;">Date Received</p>
                    <p class="text-sm" style="color:var(--text-secondary);">${paper.date_received ? new Date(paper.date_received + 'T00:00:00').toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : '—'}</p>
                </div>
                <div>
                    <p style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:4px;">Received By</p>
                    <p class="text-sm" style="color:var(--text-secondary);">${escapeHtml(paper.received_by || '—')}</p>
                </div>
                <div>
                    <p style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:4px;">Total Copies</p>
                    <p class="table-cell-mono" style="color:var(--text);">${Number(paper.available_copies) || 0}</p>
                </div>
            `;
            MailroomModal.open('viewModal');
        }

        // ========== DELETE MODAL FUNCTIONS ==========
        function openDeleteModal(id, name, issueNumber) {
            document.getElementById('deleteNewspaperName').textContent = name;
            document.getElementById('deleteIssueNumber').textContent = 'Issue #: ' + issueNumber;
            document.getElementById('delete_newspaper_input').value = id;

            MailroomModal.open('deleteModal');
        }

        // ========== ESCAPE HTML ==========
        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            const div = document.createElement('div');
            div.textContent = String(text);
            return div.innerHTML;
        }

        // ========== LIVE FILTER NEWSPAPERS ==========
        function filterNewspapersLive() {
            const searchTokens = (document.getElementById('newspaperLiveSearch')?.value || '')
                .toLowerCase()
                .split(/\s+/)
                .filter(Boolean);
            const categoryFilter = document.querySelector('#newspaperFilterForm select[name="filter_category"]')?.value || '0';
            const statusFilter = document.querySelector('#newspaperFilterForm select[name="filter_status"]')?.value?.toLowerCase() || '';
            const rows = document.querySelectorAll('.newspaper-row');
            const noResultsRow = document.getElementById('newspaperNoResultsRow');
            let visibleCount = 0;

            rows.forEach(row => {
                const searchText = row.getAttribute('data-search') || '';
                const category = row.getAttribute('data-category') || '0';
                const status = row.getAttribute('data-status') || '';

                const matchesSearch = searchTokens.length === 0 || searchTokens.every(token => searchText.includes(token));
                const matchesCategory = categoryFilter === '0' || category === categoryFilter;
                const matchesStatus = !statusFilter || status === statusFilter;
                const show = matchesSearch && matchesCategory && matchesStatus;

                row.style.display = show ? '' : 'none';
                if (show) {
                    visibleCount++;
                }
            });

            if (noResultsRow) {
                noResultsRow.classList.toggle('hidden', visibleCount !== 0 || rows.length === 0);
            }

            const visibleCountEl = document.getElementById('visibleNewspaperCount');
            if (visibleCountEl) {
                visibleCountEl.textContent = visibleCount;
            }
        }

        document.getElementById('newspaperLiveSearch')?.addEventListener('input', filterNewspapersLive);
        document.querySelector('#newspaperFilterForm select[name="filter_category"]')?.addEventListener('change', filterNewspapersLive);
        document.querySelector('#newspaperFilterForm select[name="filter_status"]')?.addEventListener('change', filterNewspapersLive);

        // ========== PRINT ==========
        function getPrintTableStyles() {
            return `
                body { font-family: Arial, sans-serif; padding: 20px; color: #1b2a4a; }
                h1 { font-size: 22px; margin: 0 0 6px; }
                .meta { color: #4b5570; font-size: 13px; margin-bottom: 18px; }
                table { border-collapse: collapse; width: 100%; }
                th, td { border: 1px solid #ddd; padding: 10px 12px; font-size: 13px; }
                th { background-color: #f1ece1; text-align: left; }
                td.numeric, th.numeric { text-align: right; }
                tfoot td { font-weight: 600; background-color: #f7f3ec; }
            `;
        }

        function printNewspaperList() {
            const rows = Array.from(document.querySelectorAll('.newspaper-row')).filter((row) => row.style.display !== 'none');
            if (!rows.length) {
                MailroomToast.error('No newspapers to print on this page.');
                return;
            }

            let rowsHtml = '';
            rows.forEach((row) => {
                const cells = row.querySelectorAll('td');
                rowsHtml += `
                    <tr>
                        <td>${cells[0].textContent.trim()}</td>
                        <td>${cells[1].textContent.trim()}</td>
                        <td>${cells[2].textContent.trim()}</td>
                        <td>${cells[3].textContent.trim()}</td>
                        <td>${cells[4].textContent.trim()}</td>
                        <td>${cells[5].textContent.trim()}</td>
                        <td>${cells[6].textContent.trim()}</td>
                    </tr>
                `;
            });

            const search = document.getElementById('newspaperLiveSearch')?.value?.trim() || '';
            const categorySelect = document.querySelector('#newspaperFilterForm select[name="filter_category"]');
            const statusSelect = document.querySelector('#newspaperFilterForm select[name="filter_status"]');
            const categoryLabel = categorySelect?.selectedOptions[0]?.textContent?.trim() || 'All Categories';
            const statusLabel = statusSelect?.value ? statusSelect.selectedOptions[0].textContent.trim() : 'All Statuses';
            const filters = [
                `Category: ${categoryLabel}`,
                `Status: ${statusLabel}`,
                search ? `Search: "${search}"` : null
            ].filter(Boolean).join(' • ');

            const printContent = `
                <html>
                <head>
                    <title>Newspaper List</title>
                    <style>${getPrintTableStyles()}</style>
                </head>
                <body>
                    <h1>Newspaper List</h1>
                    <p class="meta">Generated: ${new Date().toLocaleString()}<br>${filters}<br>Showing ${rows.length} record(s) from current page</p>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Newspaper</th>
                                <th>Issue #</th>
                                <th>Category</th>
                                <th>Date Received</th>
                                <th>Status</th>
                                <th>Available</th>
                            </tr>
                        </thead>
                        <tbody>${rowsHtml}</tbody>
                    </table>
                </body>
                </html>
            `;

            printHtmlOnPage(printContent);
            MailroomToast.info('Print dialog opened.');
        }

        function printNewspaperStatistics() {
            const table = document.getElementById('newspaperStatsTable');
            if (!table) {
                MailroomToast.error('No statistics available to print.');
                return;
            }

            const printContent = `
                <html>
                <head>
                    <title>Newspaper Statistics</title>
                    <style>${getPrintTableStyles()}</style>
                </head>
                <body>
                    <h1>Newspaper Statistics</h1>
                    <p class="meta">Generated: ${new Date().toLocaleString()}<br>Copy counts by category</p>
                    ${table.outerHTML}
                </body>
                </html>
            `;

            printHtmlOnPage(printContent);
            MailroomToast.info('Print dialog opened.');
        }

        // ========== PAGE TABS ==========
        (function() {
            const pageTabs = Array.from(document.querySelectorAll('[data-page-tab]'));
            const pagePanes = {
                newspapers: document.getElementById('newspapersPane'),
                statistics: document.getElementById('statisticsPane')
            };

            function setPageTab(tabName, updateUrl) {
                pageTabs.forEach((tab) => {
                    tab.classList.toggle('active', tab.dataset.pageTab === tabName);
                });
                Object.entries(pagePanes).forEach(([name, pane]) => {
                    if (pane) {
                        pane.classList.toggle('hidden', name !== tabName);
                    }
                });
                if (updateUrl) {
                    const url = new URL(window.location.href);
                    if (tabName === 'statistics') {
                        url.searchParams.set('tab', 'statistics');
                    } else {
                        url.searchParams.delete('tab');
                    }
                    window.history.replaceState({}, '', url);
                }
            }

            pageTabs.forEach((tab) => {
                tab.addEventListener('click', function() {
                    setPageTab(tab.dataset.pageTab, true);
                });
            });
        })();

        // ========== EXPORT ==========
        function exportNewspapers() {
            const data = <?php echo json_encode($all_newspapers_export); ?>;
            if (!data || data.length === 0) {
                MailroomToast.info('No records to export.');
                return;
            }
            exportToCSV(data, 'newspapers_' + new Date().toISOString().split('T')[0] + '.csv');
            MailroomToast.success('Export completed successfully!');
        }
    </script>
    <script src="assets/app.js"></script>
</body>

</html>