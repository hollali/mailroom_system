<?php
// recipients.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once './config/db.php';
session_start();

// Handle Add Recipient
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_recipient'])) {
    $name = trim($_POST['name']);
    $is_active = isset($_POST['is_active']) && (int)$_POST['is_active'] === 0 ? 0 : 1;

    if (!empty($name)) {
        $stmt = $conn->prepare("INSERT INTO recipients (name, is_active) VALUES (?, ?)");
        $stmt->bind_param("si", $name, $is_active);

        if ($stmt->execute()) {
            $_SESSION['toast'] = [
                'type' => 'success',
                'message' => "Recipient added successfully"
            ];
        } else {
            $_SESSION['toast'] = [
                'type' => 'error',
                'message' => "Error adding recipient: " . $conn->error
            ];
        }
    } else {
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => "Recipient name cannot be empty"
        ];
    }

    header('Location: recipients.php');
    exit();
}

// Handle Edit Recipient
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_recipient'])) {
    $id = (int)$_POST['id'];
    $name = trim($_POST['name']);
    $is_active = isset($_POST['is_active']) && (int)$_POST['is_active'] === 0 ? 0 : 1;

    if (!empty($name)) {
        $stmt = $conn->prepare("UPDATE recipients SET name = ?, is_active = ? WHERE id = ?");
        $stmt->bind_param("sii", $name, $is_active, $id);

        if ($stmt->execute()) {
            $_SESSION['toast'] = [
                'type' => 'success',
                'message' => "Recipient updated successfully"
            ];
        } else {
            $_SESSION['toast'] = [
                'type' => 'error',
                'message' => "Error updating recipient: " . $conn->error
            ];
        }
    } else {
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => "Recipient name cannot be empty"
        ];
    }

    header('Location: recipients.php');
    exit();
}

// Handle Delete Recipient
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];

    // Get recipient name first
    $name_query = $conn->query("SELECT name FROM recipients WHERE id = $id");
    $recipient = $name_query->fetch_assoc();
    $name = $recipient['name'] ?? '';

    // Check if recipient is used in distributions
    $check = $conn->query("SELECT COUNT(*) as count FROM distribution WHERE distributed_to LIKE '%" . $conn->real_escape_string($name) . "%'");
    $result = $check->fetch_assoc();

    if ($result['count'] > 0) {
        // Instead of deleting, deactivate
        $stmt = $conn->prepare("UPDATE recipients SET is_active = 0 WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $_SESSION['toast'] = [
            'type' => 'warning',
            'message' => "Recipient has distribution records. Deactivated instead of deleted."
        ];
    } else {
        // Delete if no distributions
        $stmt = $conn->prepare("DELETE FROM recipients WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $_SESSION['toast'] = [
            'type' => 'success',
            'message' => "Recipient deleted successfully"
        ];
    }

    $query_params = $_GET;
    unset($query_params['delete'], $query_params['page']);
    $redirect_url = 'recipients.php' . (!empty($query_params) ? '?' . http_build_query($query_params) : '');

    header('Location: ' . $redirect_url);
    exit();
}

// Handle Activate Recipient
if (isset($_GET['activate'])) {
    $id = (int)$_GET['activate'];
    $stmt = $conn->prepare("UPDATE recipients SET is_active = 1 WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $_SESSION['toast'] = [
        'type' => 'success',
        'message' => "Recipient activated successfully"
    ];
    $query_params = $_GET;
    unset($query_params['activate'], $query_params['page']);
    $redirect_url = 'recipients.php' . (!empty($query_params) ? '?' . http_build_query($query_params) : '');

    header('Location: ' . $redirect_url);
    exit();
}

// Pagination settings
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Search and filter settings
$search = trim($_GET['search'] ?? '');
$filter_status = $_GET['status'] ?? 'all';
$allowed_statuses = ['all', 'active', 'inactive'];

if (!in_array($filter_status, $allowed_statuses, true)) {
    $filter_status = 'all';
}

$where_clauses = [];

if ($search !== '') {
    $safe_search = $conn->real_escape_string($search);
    $where_clauses[] = "name LIKE '%$safe_search%'";
}

if ($filter_status === 'active') {
    $where_clauses[] = "is_active = 1";
} elseif ($filter_status === 'inactive') {
    $where_clauses[] = "is_active = 0";
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Get total count for pagination
$count_result = $conn->query("SELECT COUNT(*) as total FROM recipients $where_sql");
$total_recipients = (int)($count_result->fetch_assoc()['total'] ?? 0);
$total_pages = ceil($total_recipients / $limit);

// Get recipients with pagination
$recipients = $conn->query("SELECT * FROM recipients $where_sql ORDER BY is_active DESC, name ASC LIMIT $offset, $limit");

$active_count = (int)($conn->query("SELECT COUNT(*) as total FROM recipients WHERE is_active = 1")->fetch_assoc()['total'] ?? 0);
$inactive_count = (int)($conn->query("SELECT COUNT(*) as total FROM recipients WHERE is_active = 0")->fetch_assoc()['total'] ?? 0);

function buildRecipientsUrl($overrides = [], $remove_keys = [])
{
    $params = $_GET;

    foreach ($remove_keys as $key) {
        unset($params[$key]);
    }

    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }

    return 'recipients.php' . (!empty($params) ? '?' . http_build_query($params) : '');
}

$delete_base_url = buildRecipientsUrl([], ['delete', 'activate', 'page']);
$delete_separator = strpos($delete_base_url, '?') !== false ? '&' : '?';

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
    <title>Manage Recipients - Mailroom</title>
    <link rel="icon" type="image/png" href="./images/logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #f5f5f4;
        }

        .stat-card {
            background: white;
            border: 1px solid #e5e5e5;
            padding: 1.25rem;
            border-radius: 0.5rem;
            transition: all 0.2s ease;
        }

        .stat-card:hover {
            border-color: #9e9e9e;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
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

        .edit-btn:hover {
            color: #3b82f6;
        }

        .activate-btn:hover {
            color: #10b981;
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
            align-items: center;
            flex-wrap: wrap;
            gap: 0.4rem;
        }

        .pagination-item {
            min-width: 2.5rem;
            height: 2.5rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 0.85rem;
            border: 1px solid #e7e5e4;
            border-radius: 0.8rem;
            background: white;
            color: #292524;
            font-size: 0.875rem;
            font-weight: 500;
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

        .pagination-item.disabled {
            opacity: 0.45;
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

        .toast-warning {
            border-left: 4px solid #f59e0b;
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

        .modal {
            transition: opacity 0.3s ease;
        }

        .filter-input,
        .filter-select {
            width: 100%;
            border: 1px solid #e5e5e5;
            border-radius: 0.5rem;
            padding: 0.7rem 0.85rem;
            font-size: 0.875rem;
            color: #1e1e1e;
            background-color: white;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .filter-input:focus,
        .filter-select:focus {
            outline: none;
            border-color: #a8a29e;
            box-shadow: 0 0 0 3px rgba(168, 162, 158, 0.15);
        }

        .filter-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            border: 1px solid #e7e5e4;
            border-radius: 9999px;
            background-color: #fafaf9;
            padding: 0.45rem 0.85rem;
            font-size: 0.8rem;
            color: #57534e;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.35rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.01em;
        }

        .status-active {
            background-color: #ecfdf5;
            color: #047857;
        }

        .status-inactive {
            background-color: #fff7ed;
            color: #c2410c;
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
                <div class="flex justify-between items-center">
                    <div>
                        <h1 class="text-2xl font-medium text-[#1e1e1e]">Manage Recipients</h1>
                        <p class="text-sm text-[#6e6e6e] mt-1">Add, edit, and manage distribution recipients</p>
                    </div>
                    <button onclick="openAddModal()" class="px-4 py-2 text-sm bg-[#1e1e1e] text-white rounded-md hover:bg-[#2d2d2d]">
                        <i class="fa-solid fa-plus mr-1"></i> Add Recipient
                    </button>
                </div>
            </div>

            <div class="p-8">
                <!-- Recipients Table -->
                <div class="bg-white border border-[#e5e5e5] rounded-lg overflow-hidden">
                    <div class="px-5 py-4 bg-[#fafafa] border-b border-[#e5e5e5]">
                        <div class="flex flex-col gap-4">
                            <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
                                <div>
                                    <h3 class="text-sm font-medium text-[#1e1e1e]">Recipients List</h3>
                                    <p class="text-xs text-[#6e6e6e] mt-1">Format: Name - Department/Office (e.g., John Doe - HR Department)</p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <span class="filter-chip">
                                        <i class="fa-regular fa-user"></i>
                                        Total: <?php echo $active_count + $inactive_count; ?>
                                    </span>
                                    <span class="filter-chip">
                                        <i class="fa-regular fa-circle-check text-green-600"></i>
                                        Active: <?php echo $active_count; ?>
                                    </span>
                                    <span class="filter-chip">
                                        <i class="fa-regular fa-circle-pause text-amber-600"></i>
                                        Inactive: <?php echo $inactive_count; ?>
                                    </span>
                                </div>
                            </div>

                            <form method="GET" action="recipients.php" id="recipientsFilterForm" class="grid grid-cols-1 md:grid-cols-[minmax(0,1.6fr)_220px_auto] gap-3">
                                <input type="hidden" name="page" value="1">
                                <div>
                                    <label for="search" class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Search</label>
                                    <div class="flex gap-2">
                                        <input
                                            type="text"
                                            id="search"
                                            name="search"
                                            value="<?php echo htmlspecialchars($search); ?>"
                                            class="filter-input"
                                            autocomplete="off"
                                            placeholder="Search by recipient name"
                                        >
                                        <button type="submit" class="px-4 py-3 text-sm bg-[#1e1e1e] text-white rounded-md hover:bg-[#2d2d2d] whitespace-nowrap">
                                            <i class="fa-solid fa-magnifying-glass mr-1"></i> Search
                                        </button>
                                    </div>
                                </div>
                                <div>
                                    <label for="status" class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Status</label>
                                    <select id="status" name="status" class="filter-select">
                                        <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All recipients</option>
                                        <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active only</option>
                                        <option value="inactive" <?php echo $filter_status === 'inactive' ? 'selected' : ''; ?>>Inactive only</option>
                                    </select>
                                </div>
                                <div class="flex items-end gap-2">
                                    <button type="submit" class="px-4 py-3 text-sm bg-[#1e1e1e] text-white rounded-md hover:bg-[#2d2d2d]">
                                        <i class="fa-solid fa-sliders mr-1"></i> Apply Filters
                                    </button>
                                    <?php if ($search !== '' || $filter_status !== 'all'): ?>
                                        <a href="recipients.php" class="px-4 py-3 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                                            Reset
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>

                    <?php if ($recipients && $recipients->num_rows > 0): ?>
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead>
                                    <tr class="bg-[#fafafa] border-b border-[#e5e5e5]">
                                        <th class="text-left p-3 text-xs font-medium text-[#6e6e6e]">#</th>
                                        <th class="text-left p-3 text-xs font-medium text-[#6e6e6e]">Recipient Name</th>
                                        <th class="text-left p-3 text-xs font-medium text-[#6e6e6e]">Status</th>
                                        <th class="text-left p-3 text-xs font-medium text-[#6e6e6e]">Created</th>
                                        <th class="text-left p-3 text-xs font-medium text-[#6e6e6e]">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="recipientsTableBody">
                                    <?php $counter = ($page - 1) * $limit + 1;
                                    while ($recipient = $recipients->fetch_assoc()): ?>
                                        <tr class="border-b border-[#f0f0f0] hover:bg-[#fafafa] recipient-row"
                                            data-search="<?php echo strtolower(htmlspecialchars(trim(
                                                                        ($recipient['name'] ?? '') . ' ' .
                                                                            ($recipient['is_active'] ? 'active' : 'inactive') . ' ' .
                                                                            ($recipient['created_at'] ?? '')
                                                                    ))); ?>"
                                            data-status="<?php echo $recipient['is_active'] ? 'active' : 'inactive'; ?>">
                                            <td class="p-3 text-sm"><?php echo $counter++; ?></td>
                                            <td class="p-3 text-sm font-medium"><?php echo htmlspecialchars($recipient['name']); ?></td>
                                            <td class="p-3 text-sm">
                                                <span class="status-badge <?php echo $recipient['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                                    <i class="fa-regular <?php echo $recipient['is_active'] ? 'fa-circle-check' : 'fa-circle-pause'; ?>"></i>
                                                    <?php echo $recipient['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </td>
                                            <td class="p-3 text-sm text-[#6e6e6e]">
                                                <?php echo date('M j, Y', strtotime($recipient['created_at'])); ?>
                                            </td>
                                            <td class="p-3">
                                                <div class="flex items-center gap-2">
                                                    <button onclick="editRecipient(<?php echo $recipient['id']; ?>, '<?php echo htmlspecialchars(addslashes($recipient['name'])); ?>', <?php echo (int)$recipient['is_active']; ?>)"
                                                        class="action-btn edit-btn" title="Edit">
                                                        <i class="fa-regular fa-pen-to-square"></i>
                                                    </button>
                                                    <?php if ($recipient['is_active']): ?>
                                                        <button onclick="deleteRecipient(<?php echo $recipient['id']; ?>, '<?php echo htmlspecialchars(addslashes($recipient['name'])); ?>')"
                                                            class="action-btn delete-btn" title="Delete/Deactivate">
                                                            <i class="fa-regular fa-trash-can"></i>
                                                        </button>
                                                    <?php else: ?>
                                                        <a href="<?php echo htmlspecialchars(buildRecipientsUrl(['activate' => $recipient['id']], ['page'])); ?>"
                                                            class="action-btn activate-btn" title="Activate"
                                                            onclick="return confirm('Activate this recipient?')">
                                                            <i class="fa-regular fa-circle-check"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                        <div id="recipientsSearchEmptyState" class="hidden px-4 py-3 text-sm text-[#6e6e6e] border-t border-[#e5e5e5]">
                            No recipients on this page match the current search or status filter.
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <div class="pagination-shell">
                                <div class="pagination-meta">
                                    <div class="pagination-title">
                                        Showing <?php echo min($limit, $total_recipients - ($page - 1) * $limit); ?> recipient(s) on this page
                                    </div>
                                    <div class="pagination-subtitle">
                                        Records <?php echo ($page - 1) * $limit + 1; ?>-<?php echo min($page * $limit, $total_recipients); ?> of <?php echo $total_recipients; ?> total
                                    </div>
                                </div>
                                <div class="pagination-controls">
                                    <div class="pagination-page-indicator">Page <?php echo $page; ?> of <?php echo $total_pages; ?></div>
                                    <div class="pagination">
                                        <?php if ($page > 1): ?>
                                            <a href="<?php echo htmlspecialchars(buildRecipientsUrl(['page' => 1])); ?>" class="pagination-item compact" aria-label="First page">
                                                <i class="fa-solid fa-chevrons-left"></i>
                                            </a>
                                            <a href="<?php echo htmlspecialchars(buildRecipientsUrl(['page' => $page - 1])); ?>" class="pagination-item compact" aria-label="Previous page">
                                                <i class="fa-solid fa-chevron-left"></i>
                                            </a>
                                        <?php endif; ?>

                                        <?php
                                        $start = max(1, $page - 2);
                                        $end = min($total_pages, $page + 2);

                                        if ($start > 1) {
                                            echo '<a href="' . htmlspecialchars(buildRecipientsUrl(['page' => 1])) . '" class="pagination-item">1</a>';
                                            if ($start > 2) {
                                                echo '<span class="pagination-ellipsis">...</span>';
                                            }
                                        }

                                        for ($i = $start; $i <= $end; $i++) {
                                            $active_class = ($i == $page) ? 'active' : '';
                                            echo '<a href="' . htmlspecialchars(buildRecipientsUrl(['page' => $i])) . '" class="pagination-item ' . $active_class . '">' . $i . '</a>';
                                        }

                                        if ($end < $total_pages) {
                                            if ($end < $total_pages - 1) {
                                                echo '<span class="pagination-ellipsis">...</span>';
                                            }
                                            echo '<a href="' . htmlspecialchars(buildRecipientsUrl(['page' => $total_pages])) . '" class="pagination-item">' . $total_pages . '</a>';
                                        }
                                        ?>

                                        <?php if ($page < $total_pages): ?>
                                            <a href="<?php echo htmlspecialchars(buildRecipientsUrl(['page' => $page + 1])); ?>" class="pagination-item compact" aria-label="Next page">
                                                <i class="fa-solid fa-chevron-right"></i>
                                            </a>
                                            <a href="<?php echo htmlspecialchars(buildRecipientsUrl(['page' => $total_pages])); ?>" class="pagination-item compact" aria-label="Last page">
                                                <i class="fa-solid fa-chevrons-right"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="text-center py-8 text-[#6e6e6e]">
                            <i class="fa-regular fa-user text-3xl mb-2"></i>
                            <?php if ($search !== '' || $filter_status !== 'all'): ?>
                                <p>No recipients match the current search or filter.</p>
                                <a href="recipients.php" class="inline-block mt-3 text-sm text-blue-600 hover:underline">
                                    Clear filters →
                                </a>
                            <?php else: ?>
                                <p>No recipients found</p>
                                <button onclick="openAddModal()" class="inline-block mt-3 text-sm text-blue-600 hover:underline">
                                    Add your first recipient →
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Recipient Modal -->
    <div id="addModal" class="fixed inset-0 bg-[#000000] bg-opacity-20 hidden items-center justify-center z-50 modal">
        <div class="bg-white border border-[#e5e5e5] rounded-md w-full max-w-md p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-medium text-[#1e1e1e]">Add Recipient</h2>
                <button type="button" onclick="closeAddModal()" class="text-[#9e9e9e] hover:text-[#1e1e1e]">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>

            <form method="POST" action="recipients.php">
                <div class="mb-4">
                    <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Recipient Name *</label>
                    <input type="text" name="name" required
                        class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]"
                        placeholder="e.g., John Doe - HR Department" autocomplete="off">
                    <p class="text-xs text-[#6e6e6e] mt-1">Format: Name - Department/Office</p>
                </div>

                <div class="mb-4">
                    <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Status</label>
                    <select name="is_active"
                        class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e] bg-white">
                        <option value="1" selected>Active</option>
                        <option value="0">Inactive</option>
                    </select>
                    <p class="text-xs text-[#6e6e6e] mt-1">Inactive recipients will not appear in distribution selection.</p>
                </div>

                <div class="flex justify-end gap-2 mt-6">
                    <button type="button" onclick="closeAddModal()"
                        class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                        Cancel
                    </button>
                    <button type="submit" name="add_recipient"
                        class="px-4 py-2 text-sm bg-[#1e1e1e] text-white rounded-md hover:bg-[#2d2d2d]">
                        Add Recipient
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Recipient Modal -->
    <div id="editModal" class="fixed inset-0 bg-[#000000] bg-opacity-20 hidden items-center justify-center z-50 modal">
        <div class="bg-white border border-[#e5e5e5] rounded-md w-full max-w-md p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-medium text-[#1e1e1e]">Edit Recipient</h2>
                <button type="button" onclick="closeEditModal()" class="text-[#9e9e9e] hover:text-[#1e1e1e]">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>

            <form method="POST" action="recipients.php">
                <input type="hidden" name="id" id="edit_id">
                <div class="mb-4">
                    <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Recipient Name *</label>
                    <input type="text" name="name" id="edit_name" required
                        class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]"
                        placeholder="e.g., John Doe - HR Department" autocomplete="off">
                    <p class="text-xs text-[#6e6e6e] mt-1">Format: Name - Department/Office</p>
                </div>

                <div class="mb-4">
                    <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Status</label>
                    <select name="is_active" id="edit_is_active"
                        class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e] bg-white">
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                    <p class="text-xs text-[#6e6e6e] mt-1">Set a recipient inactive to hide them from new distributions.</p>
                </div>

                <div class="flex justify-end gap-2 mt-6">
                    <button type="button" onclick="closeEditModal()"
                        class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                        Cancel
                    </button>
                    <button type="submit" name="edit_recipient"
                        class="px-4 py-2 text-sm bg-[#1e1e1e] text-white rounded-md hover:bg-[#2d2d2d]">
                        Update Recipient
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="fixed inset-0 bg-[#000000] bg-opacity-20 hidden items-center justify-center z-50 modal">
        <div class="bg-white border border-[#e5e5e5] rounded-md w-full max-w-md p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-medium text-[#1e1e1e]">Confirm Action</h2>
                <button type="button" onclick="closeDeleteModal()" class="text-[#9e9e9e] hover:text-[#1e1e1e]">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>

            <div class="py-2">
                <p class="text-sm text-[#6e6e6e]" id="deleteMessage">Are you sure you want to delete this recipient?</p>
                <p class="text-xs text-[#9e9e9e] mt-3" id="deleteNote">
                    <i class="fa-solid fa-circle-info mr-1"></i>
                    If this recipient has distribution records, they will be deactivated instead of deleted.
                </p>
            </div>

            <div class="flex justify-end gap-2 mt-6">
                <button onclick="closeDeleteModal()"
                    class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                    Cancel
                </button>
                <a href="#" id="confirmDeleteBtn"
                    class="px-4 py-2 text-sm bg-red-600 text-white rounded-md hover:bg-red-700">
                    Confirm
                </a>
            </div>
        </div>
    </div>

    <script>
        // Toast Notification
        function showToast(type, message) {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;

            const icon = type === 'success' ? 'fa-circle-check' : (type === 'error' ? 'fa-circle-exclamation' : 'fa-triangle-exclamation');

            toast.innerHTML = `
                <div class="flex items-center gap-3">
                    <i class="fa-regular ${icon} text-${type === 'success' ? 'green' : (type === 'error' ? 'red' : 'orange')}-500"></i>
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

        // Modal Functions
        function openAddModal() {
            const modal = document.getElementById('addModal');
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
        }

        function closeAddModal() {
            const modal = document.getElementById('addModal');
            modal.classList.add('hidden');
            modal.style.display = 'none';
        }

        function editRecipient(id, name, isActive) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_is_active').value = String(isActive);
            const modal = document.getElementById('editModal');
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
        }

        function closeEditModal() {
            const modal = document.getElementById('editModal');
            modal.classList.add('hidden');
            modal.style.display = 'none';
        }

        let currentDeleteId = null;

        function deleteRecipient(id, name) {
            currentDeleteId = id;
            document.getElementById('deleteMessage').innerHTML = `Are you sure you want to delete "<strong>${escapeHtml(name)}</strong>"?`;
            document.getElementById('confirmDeleteBtn').href = `<?php echo addslashes($delete_base_url . $delete_separator); ?>delete=${id}`;
            const modal = document.getElementById('deleteModal');
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
        }

        function closeDeleteModal() {
            const modal = document.getElementById('deleteModal');
            modal.classList.add('hidden');
            modal.style.display = 'none';
            currentDeleteId = null;
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function getRecipientSearchTokens(value) {
            return (value || '')
                .toLowerCase()
                .trim()
                .split(/\s+/)
                .filter(Boolean);
        }

        function filterRecipientsLive() {
            const searchInput = document.getElementById('search');
            const statusFilter = document.getElementById('status');
            const rows = document.querySelectorAll('.recipient-row');
            const emptyState = document.getElementById('recipientsSearchEmptyState');
            let visibleCount = 0;

            if (!searchInput || !statusFilter || rows.length === 0) return;

            const searchTokens = getRecipientSearchTokens(searchInput.value);
            const statusValue = statusFilter.value;

            rows.forEach(function(row) {
                const searchText = (row.getAttribute('data-search') || '').toLowerCase();
                const rowStatus = row.getAttribute('data-status') || 'all';
                const matchesSearch = searchTokens.length === 0 || searchTokens.every(function(token) {
                    return searchText.includes(token);
                });
                const matchesStatus = statusValue === 'all' || rowStatus === statusValue;
                const show = matchesSearch && matchesStatus;

                row.style.display = show ? '' : 'none';
                if (show) visibleCount++;
            });

            if (emptyState) {
                emptyState.classList.toggle('hidden', visibleCount > 0);
            }
        }

        document.getElementById('search')?.addEventListener('input', filterRecipientsLive);
        document.getElementById('status')?.addEventListener('change', filterRecipientsLive);
        document.getElementById('recipientsFilterForm')?.addEventListener('submit', function() {
            const pageInput = this.querySelector('input[name="page"]');
            if (pageInput) pageInput.value = '1';
        });
        filterRecipientsLive();

        // Close modals when clicking outside
        window.onclick = function(event) {
            const addModal = document.getElementById('addModal');
            const editModal = document.getElementById('editModal');
            const deleteModal = document.getElementById('deleteModal');

            if (event.target == addModal) {
                closeAddModal();
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
                closeAddModal();
                closeEditModal();
                closeDeleteModal();
            }
        });
    </script>
</body>

</html>
