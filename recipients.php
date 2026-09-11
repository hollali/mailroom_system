<?php
// recipients.php

require_once './config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/audit.php';
session_start();

// Handle Add Recipient
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_recipient'])) {
    csrf_check_post();
    $name = trim($_POST['name']);
    $is_active = isset($_POST['is_active']) && (int)$_POST['is_active'] === 0 ? 0 : 1;

    if (!empty($name)) {
        $stmt = $conn->prepare("INSERT INTO recipients (name, is_active) VALUES (?, ?)");
        $stmt->bind_param("si", $name, $is_active);

        if ($stmt->execute()) {
            $id = (int)$stmt->insert_id;
            audit_log('create', 'recipient', $id, "Added recipient '$name'");
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
    csrf_check_post();
    $id = (int)$_POST['id'];
    $name = trim($_POST['name']);
    // When the status field is not submitted (we removed it from the UI),
    // preserve the existing `is_active` value.
    if (isset($_POST['is_active'])) {
        $is_active = (int)$_POST['is_active'] === 0 ? 0 : 1;
    } else {
        $curr_stmt = $conn->prepare("SELECT is_active FROM recipients WHERE id = ?");
        $curr_stmt->bind_param("i", $id);
        $curr_stmt->execute();
        $current = $curr_stmt->get_result();
        $curr_stmt->close();
        $is_active = (int)($current->fetch_assoc()['is_active'] ?? 1);
    }

    if (!empty($name)) {
        $stmt = $conn->prepare("UPDATE recipients SET name = ?, is_active = ? WHERE id = ?");
        $stmt->bind_param("sii", $name, $is_active, $id);

        if ($stmt->execute()) {
            audit_log('update', 'recipient', $id, "Updated recipient '$name'");
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_recipient'])) {
    csrf_check_post();
    $id = (int)$_POST['delete_recipient'];

    // Get recipient name first
    $name_stmt = $conn->prepare("SELECT name FROM recipients WHERE id = ?");
    $name_stmt->bind_param("i", $id);
    $name_stmt->execute();
    $name_query = $name_stmt->get_result();
    $recipient = $name_query->fetch_assoc();
    $name_stmt->close();
    $name = $recipient['name'] ?? '';

    // Check if recipient is used in distributions
    $like_pattern = '%' . $name . '%';
    $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM distribution WHERE distributed_to LIKE ?");
    $check_stmt->bind_param("s", $like_pattern);
    $check_stmt->execute();
    $result = $check_stmt->get_result()->fetch_assoc();
    $check_stmt->close();

    if ($result['count'] > 0) {
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => "Cannot delete recipient with distribution records. Please deactivate them instead."
        ];
    } else {
        // Delete if no distributions
        $stmt = $conn->prepare("DELETE FROM recipients WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            audit_log('delete', 'recipient', $id, "Deleted recipient '$name'");
            $_SESSION['toast'] = [
                'type' => 'success',
                'message' => "Recipient deleted successfully"
            ];
        } else {
            $_SESSION['toast'] = [
                'type' => 'error',
                'message' => "Error deleting recipient: " . $conn->error
            ];
        }
    }

    $query_params = $_GET;
    unset($query_params['delete_recipient'], $query_params['page']);
    $redirect_url = 'recipients.php' . (!empty($query_params) ? '?' . http_build_query($query_params) : '');

    header('Location: ' . $redirect_url);
    exit();
}

// Handle Deactivate Recipient
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deactivate_recipient'])) {
    csrf_check_post();
    $id = (int)$_POST['deactivate_recipient'];
    $stmt = $conn->prepare("UPDATE recipients SET is_active = 0 WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $name_stmt = $conn->prepare("SELECT name FROM recipients WHERE id = ?");
        $name_stmt->bind_param("i", $id);
        $name_stmt->execute();
        $name_row = $name_stmt->get_result()->fetch_assoc();
        $name_stmt->close();
        audit_log('update', 'recipient', $id, "Deactivated recipient '{$name_row['name']}'");
        $_SESSION['toast'] = [
            'type' => 'success',
            'message' => "Recipient deactivated successfully"
        ];
    } else {
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => "Error deactivating recipient: " . $conn->error
        ];
    }
    $query_params = $_GET;
    unset($query_params['deactivate_recipient'], $query_params['page']);
    $redirect_url = 'recipients.php' . (!empty($query_params) ? '?' . http_build_query($query_params) : '');

    header('Location: ' . $redirect_url);
    exit();
}

// Handle Activate Recipient
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['activate_recipient'])) {
    csrf_check_post();
    $id = (int)$_POST['activate_recipient'];
    $stmt = $conn->prepare("UPDATE recipients SET is_active = 1 WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $name_stmt = $conn->prepare("SELECT name FROM recipients WHERE id = ?");
        $name_stmt->bind_param("i", $id);
        $name_stmt->execute();
        $name_row = $name_stmt->get_result()->fetch_assoc();
        $name_stmt->close();
        audit_log('update', 'recipient', $id, "Activated recipient '{$name_row['name']}'");
        $_SESSION['toast'] = [
            'type' => 'success',
            'message' => "Recipient activated successfully"
        ];
    } else {
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => "Error activating recipient: " . $conn->error
        ];
    }
    $query_params = $_GET;
    unset($query_params['activate_recipient'], $query_params['page']);
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
$status_filter = $_GET['status'] ?? 'all';
$sort_filter = $_GET['sort'] ?? 'name_asc';

$allowed_status_filters = ['all', 'active', 'inactive'];
$allowed_sort_filters = [
    'name_asc' => 'name ASC',
    'name_desc' => 'name DESC',
    'newest' => 'created_at DESC',
    'oldest' => 'created_at ASC',
];

if (!in_array($status_filter, $allowed_status_filters, true)) {
    $status_filter = 'all';
}

if (!array_key_exists($sort_filter, $allowed_sort_filters)) {
    $sort_filter = 'name_asc';
}

$where_clauses = [];

if ($search !== '') {
    $safe_search = $conn->real_escape_string($search);
    $where_clauses[] = "name LIKE '%$safe_search%'";
}

if ($status_filter === 'active') {
    $where_clauses[] = "is_active = 1";
} elseif ($status_filter === 'inactive') {
    $where_clauses[] = "is_active = 0";
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
$order_sql = $allowed_sort_filters[$sort_filter];

// Get total count for pagination
$count_result = $conn->query("SELECT COUNT(*) as total FROM recipients $where_sql");
$total_recipients = (int)($count_result->fetch_assoc()['total'] ?? 0);
$total_pages = ceil($total_recipients / $limit);

// Get recipients with pagination
$recipients = $conn->query("SELECT * FROM recipients $where_sql ORDER BY is_active DESC, $order_sql LIMIT $offset, $limit");

// Get all recipients for export matching filters (without pagination limit)
$all_recipients_export = [];
$export_res = $conn->query("SELECT * FROM recipients $where_sql ORDER BY is_active DESC, $order_sql");
if ($export_res) {
    while ($row = $export_res->fetch_assoc()) {
        $all_recipients_export[] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'is_active' => $row['is_active'] ? 'Active' : 'Inactive',
            'created_at' => $row['created_at'] ? date('Y-m-d H:i:s', strtotime($row['created_at'])) : ''
        ];
    }
}

$active_recipients = (int)(($conn->query("SELECT COUNT(*) as total FROM recipients WHERE is_active = 1"))->fetch_assoc()['total'] ?? 0);
$inactive_recipients = (int)(($conn->query("SELECT COUNT(*) as total FROM recipients WHERE is_active = 0"))->fetch_assoc()['total'] ?? 0);
$has_active_filters = $search !== '' || $status_filter !== 'all' || $sort_filter !== 'name_asc';

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

$delete_base_url = buildRecipientsUrl([], ['delete', 'activate', 'deactivate', 'page']);
$delete_separator = strpos($delete_base_url, '?') !== false ? '&' : '?';
$activate_base_url = buildRecipientsUrl([], ['activate', 'delete', 'deactivate', 'page']);
$activate_separator = strpos($activate_base_url, '?') !== false ? '&' : '?';
$deactivate_base_url = buildRecipientsUrl([], ['deactivate', 'activate', 'delete', 'page']);
$deactivate_separator = strpos($deactivate_base_url, '?') !== false ? '&' : '?';

// Preserve current filters on POST forms so the redirect keeps them
$state_query = buildRecipientsUrl([], ['page', 'delete', 'activate', 'deactivate']);
$state_query_url = $state_query === 'recipients.php' ? 'recipients.php' : $state_query;

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
    <title>Recipients - Mailroom Ops</title>
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
                        <a href="index.php">Management</a>
                        <span class="sep">/</span>
                        <span>Recipients</span>
                    </div>
                    <h1 class="page-header-title">Recipients</h1>
                    <p class="page-header-subtitle">Manage the offices and departments that receive distributions.</p>
                </div>
                <div class="header-actions flex items-center gap-2 print-hide">
                    <button onclick="exportRecipients()" class="btn btn-soft">
                        <i class="fa-regular fa-file-excel"></i>
                        <span class="hidden sm:inline">Export CSV</span>
                    </button>
                    <button onclick="MailroomModal.open('addModal')" class="btn btn-primary">
                        <i class="fa-solid fa-plus"></i>
                        <span class="hidden sm:inline">Add Recipient</span>
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

                <!-- Recipients Table -->
                <div class="card">
                    <div class="card-header" style="padding:14px 20px;">
                        <div>
                            <div class="card-title">Recipients List</div>
                            <div class="card-subtitle">Format: Name - Department/Office (e.g., John Doe - HR Department)</div>
                        </div>
                        <div class="flex flex-wrap gap-2 print-hide">
                            <span class="filter-chip"><i class="fa-regular fa-user"></i> Total: <?php echo $total_recipients; ?></span>
                            <span class="filter-chip"><i class="fa-regular fa-circle-check" style="color:var(--green);"></i> Active: <?php echo $active_recipients; ?></span>
                            <span class="filter-chip"><i class="fa-regular fa-circle-xmark" style="color:var(--orange);"></i> Inactive: <?php echo $inactive_recipients; ?></span>
                        </div>
                    </div>

                    <div style="padding:14px 16px;border-bottom:1px solid var(--border);" class="print-hide">
                        <form method="GET" action="recipients.php" id="recipientsFilterForm">
                            <input type="hidden" name="page" value="1">
                            <div class="filter-bar" style="margin-bottom:0;">
                                <div class="search-wrap" style="flex:1;min-width:240px;">
                                    <i class="fa-solid fa-magnifying-glass icon"></i>
                                    <input
                                        type="text"
                                        id="search"
                                        name="search"
                                        value="<?php echo htmlspecialchars($search); ?>"
                                        class="input"
                                        autocomplete="off"
                                        placeholder="Search by recipient name">
                                </div>
                                <select id="status" name="status" class="select">
                                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                    <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                                <select id="sort" name="sort" class="select">
                                    <option value="name_asc" <?php echo $sort_filter === 'name_asc' ? 'selected' : ''; ?>>Name A-Z</option>
                                    <option value="name_desc" <?php echo $sort_filter === 'name_desc' ? 'selected' : ''; ?>>Name Z-A</option>
                                    <option value="newest" <?php echo $sort_filter === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                                    <option value="oldest" <?php echo $sort_filter === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                                </select>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fa-solid fa-magnifying-glass"></i> Search
                                </button>
                                <?php if ($has_active_filters): ?>
                                    <a href="recipients.php" class="btn btn-soft">
                                        <i class="fa-solid fa-rotate-left"></i> Reset
                                    </a>
                                <?php endif; ?>
                            </div>
                        </form>

                        <?php if ($has_active_filters): ?>
                            <div class="flex flex-wrap gap-2" style="margin-top:10px;">
                                <?php if ($search !== ''): ?>
                                    <span class="filter-chip">Search: <?php echo htmlspecialchars($search); ?></span>
                                <?php endif; ?>
                                <?php if ($status_filter !== 'all'): ?>
                                    <span class="filter-chip">Status: <?php echo htmlspecialchars(ucfirst($status_filter)); ?></span>
                                <?php endif; ?>
                                <?php if ($sort_filter !== 'name_asc'): ?>
                                    <span class="filter-chip">Sort: <?php echo htmlspecialchars([
                                            'name_desc' => 'Name Z-A',
                                            'newest' => 'Newest First',
                                            'oldest' => 'Oldest First',
                                        ][$sort_filter] ?? 'Custom'); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($recipients && $recipients->num_rows > 0): ?>
                        <div class="table-wrap">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th style="width:56px;">#</th>
                                        <th>Recipient Name</th>
                                        <th>Status</th>
                                        <th class="hidden md:table-cell">Created</th>
                                        <th style="width:96px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="recipientsTableBody">
                                    <?php $counter = ($page - 1) * $limit + 1;
                                    while ($recipient = $recipients->fetch_assoc()): ?>
                                        <tr class="recipient-row"
                                            data-search="<?php echo strtolower(htmlspecialchars(trim(
                                                                ($recipient['name'] ?? '') . ' ' .
                                                                ($recipient['created_at'] ?? '')
                                                            ))); ?>"
                                            data-status="<?php echo (int)$recipient['is_active'] === 1 ? 'active' : 'inactive'; ?>"
                                            >
                                            <td><span class="text-xs text-[#7d8398]"><?php echo $counter++; ?></span></td>
                                            <td><span class="table-cell-title"><?php echo htmlspecialchars($recipient['name']); ?></span></td>
                                            <td>
                                                <?php if ($recipient['is_active']): ?>
                                                    <span class="badge badge-green">Active</span>
                                                <?php else: ?>
                                                    <span class="badge badge-gray">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="hidden md:table-cell"><span class="text-xs text-[#4b5570]"><?php echo date('M j, Y', strtotime($recipient['created_at'])); ?></span></td>
                                            <td>
                                                <div class="row-actions">
                                                    <button onclick="editRecipient(<?php echo $recipient['id']; ?>, '<?php echo htmlspecialchars(addslashes($recipient['name'])); ?>')"
                                                        class="icon-btn primary" title="Edit">
                                                        <i class="fa-regular fa-pen-to-square"></i>
                                                    </button>
                                                    <?php if ($recipient['is_active']): ?>
                                                        <button onclick="deactivateRecipient(<?php echo $recipient['id']; ?>, '<?php echo htmlspecialchars(addslashes($recipient['name'])); ?>')"
                                                            class="icon-btn" title="Deactivate">
                                                            <i class="fa-solid fa-circle-minus"></i>
                                                        </button>
                                                    <?php else: ?>
                                                        <button onclick="activateRecipient(<?php echo $recipient['id']; ?>, '<?php echo htmlspecialchars(addslashes($recipient['name'])); ?>')"
                                                            class="icon-btn green" title="Activate">
                                                            <i class="fa-solid fa-circle-plus"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <button onclick="deleteRecipient(<?php echo $recipient['id']; ?>, '<?php echo htmlspecialchars(addslashes($recipient['name'])); ?>')"
                                                        class="icon-btn danger" title="Delete Permanent">
                                                        <i class="fa-regular fa-trash-can"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                        <div id="recipientsSearchEmptyState" class="hidden px-4 py-3 text-sm text-[#7d8398] border-t" style="border-color:var(--border);">
                            No recipients on this page match the current search.
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <div class="pagination-shell">
                                <div class="pagination-meta">
                                    <div class="pagination-title">
                                        Showing <?php echo min($limit, $total_recipients - ($page - 1) * $limit); ?> recipient(s) on this page
                                    </div>
                                    <span>Records <?php echo ($page - 1) * $limit + 1; ?>-<?php echo min($page * $limit, $total_recipients); ?> of <?php echo $total_recipients; ?> total</span>
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
                        <div class="empty-state">
                            <div class="empty-state-icon"><i class="fa-regular fa-user"></i></div>
                            <?php if ($has_active_filters): ?>
                                <div class="empty-state-title">No recipients match the current filters</div>
                                <div class="empty-state-text">Try adjusting your search or filter criteria.</div>
                                <a href="recipients.php" class="btn btn-soft btn-sm" style="margin-top:16px;">Clear filters</a>
                            <?php else: ?>
                                <div class="empty-state-title">No recipients found</div>
                                <div class="empty-state-text">Add your first recipient to start distributing documents.</div>
                                <button onclick="MailroomModal.open('addModal')" class="btn btn-primary btn-sm" style="margin-top:16px;">Add Recipient</button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Recipient Modal -->
    <div id="addModal" class="modal-backdrop" style="display:none;">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title">Add Recipient</h3>
                <button type="button" onclick="MailroomModal.close('addModal')" class="modal-close"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST" action="recipients.php">
                <?php csrf_field(); ?>
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-field span-2">
                            <label class="label">Recipient Name <span class="req">*</span></label>
                            <input type="text" name="name" required
                                class="input"
                                placeholder="e.g., John Doe - HR Department" autocomplete="off">
                            <p class="text-xs text-[#7d8398] mt-1">Format: Name - Department/Office</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="MailroomModal.close('addModal')" class="btn btn-soft">Cancel</button>
                    <button type="submit" name="add_recipient" class="btn btn-primary">
                        <i class="fa-solid fa-plus"></i> Add Recipient
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Recipient Modal -->
    <div id="editModal" class="modal-backdrop" style="display:none;">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title">Edit Recipient</h3>
                <button type="button" onclick="MailroomModal.close('editModal')" class="modal-close"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST" action="recipients.php">
                <?php csrf_field(); ?>
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-field span-2">
                            <label class="label">Recipient Name <span class="req">*</span></label>
                            <input type="text" name="name" id="edit_name" required
                                class="input"
                                placeholder="e.g., John Doe - HR Department" autocomplete="off">
                            <p class="text-xs text-[#7d8398] mt-1">Format: Name - Department/Office</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="MailroomModal.close('editModal')" class="btn btn-soft">Cancel</button>
                    <button type="submit" name="edit_recipient" class="btn btn-primary">
                        <i class="fa-regular fa-floppy-disk"></i> Update Recipient
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal-backdrop" style="display:none;">
        <div class="modal-dialog sm">
            <div class="modal-header">
                <h3 class="modal-title">Confirm Permanent Delete</h3>
                <button type="button" onclick="MailroomModal.close('deleteModal')" class="modal-close"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST" action="<?php echo htmlspecialchars($state_query_url); ?>">
                <?php csrf_field(); ?>
                <input type="hidden" name="delete_recipient" id="delete_recipient_id">
                <div class="modal-body">
                    <div class="notice-bar" style="background:var(--red-soft);border-color:var(--red-border);color:var(--red);">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <span>Warning: This action is permanent and cannot be undone. Recipients with existing distribution records cannot be deleted and should be deactivated instead.</span>
                    </div>
                    <p class="text-sm text-[#4b5570] mt-3" id="deleteMessage">Are you sure you want to permanently delete this recipient?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="MailroomModal.close('deleteModal')" class="btn btn-soft">Cancel</button>
                    <button type="submit" id="confirmDeleteBtn" class="btn btn-danger">
                        <i class="fa-regular fa-trash-can"></i> Yes, Delete Permanent
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Deactivate Confirmation Modal -->
    <div id="deactivateModal" class="modal-backdrop" style="display:none;">
        <div class="modal-dialog sm">
            <div class="modal-header">
                <h3 class="modal-title">Confirm Deactivation</h3>
                <button type="button" onclick="MailroomModal.close('deactivateModal')" class="modal-close"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST" action="<?php echo htmlspecialchars($state_query_url); ?>">
                <?php csrf_field(); ?>
                <input type="hidden" name="deactivate_recipient" id="deactivate_recipient_id">
                <div class="modal-body">
                    <div class="notice-bar" style="background:var(--orange-soft);border-color:var(--orange-border);color:var(--orange);">
                        <i class="fa-solid fa-circle-info"></i>
                        <span>Deactivated recipients will not appear in future distribution selection lists.</span>
                    </div>
                    <p class="text-sm text-[#4b5570] mt-3" id="deactivateMessage">Are you sure you want to deactivate this recipient?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="MailroomModal.close('deactivateModal')" class="btn btn-soft">Cancel</button>
                    <button type="submit" id="confirmDeactivateBtn" class="btn btn-primary">
                        <i class="fa-solid fa-circle-minus"></i> Deactivate
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Activate Confirmation Modal -->
    <div id="activateModal" class="modal-backdrop" style="display:none;">
        <div class="modal-dialog sm">
            <div class="modal-header">
                <h3 class="modal-title">Activate Recipient</h3>
                <button type="button" onclick="MailroomModal.close('activateModal')" class="modal-close"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST" action="<?php echo htmlspecialchars($state_query_url); ?>">
                <?php csrf_field(); ?>
                <input type="hidden" name="activate_recipient" id="activate_recipient_id">
                <div class="modal-body">
                    <div class="notice-bar" style="background:var(--green-soft);border-color:var(--green-border);color:var(--green);">
                        <i class="fa-solid fa-circle-info"></i>
                        <span>Activated recipients will appear in future distribution selections.</span>
                    </div>
                    <p class="text-sm text-[#4b5570] mt-3" id="activateMessage">Are you sure you want to activate this recipient?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="MailroomModal.close('activateModal')" class="btn btn-soft">Cancel</button>
                    <button type="submit" id="confirmActivateBtn" class="btn btn-primary">
                        <i class="fa-solid fa-circle-plus"></i> Activate
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        <?php if ($toast): ?>
            document.addEventListener('DOMContentLoaded', function() {
                MailroomToast.<?php echo $toast['type']; ?>(<?php echo json_encode($toast['message']); ?>);
            });
        <?php endif; ?>

        function editRecipient(id, name) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_name').value = name;
            MailroomModal.open('editModal');
        }

        function deleteRecipient(id, name) {
            document.getElementById('delete_recipient_id').value = id;
            document.getElementById('deleteMessage').innerHTML = `Are you sure you want to delete "<strong>${escapeHtml(name)}</strong>"?`;
            MailroomModal.open('deleteModal');
        }

        function activateRecipient(id, name) {
            document.getElementById('activate_recipient_id').value = id;
            document.getElementById('activateMessage').innerHTML = `Are you sure you want to activate "<strong>${escapeHtml(name)}</strong>"?`;
            MailroomModal.open('activateModal');
        }

        function deactivateRecipient(id, name) {
            document.getElementById('deactivate_recipient_id').value = id;
            document.getElementById('deactivateMessage').innerHTML = `Are you sure you want to deactivate "<strong>${escapeHtml(name)}</strong>"?`;
            MailroomModal.open('deactivateModal');
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
            const statusSelect = document.getElementById('status');
            const rows = document.querySelectorAll('.recipient-row');
            const emptyState = document.getElementById('recipientsSearchEmptyState');
            let visibleCount = 0;

            if (!searchInput || rows.length === 0) return;

            const searchTokens = getRecipientSearchTokens(searchInput.value);
            const selectedStatus = statusSelect ? statusSelect.value : 'all';

            rows.forEach(function(row) {
                const searchText = (row.getAttribute('data-search') || '').toLowerCase();
                const rowStatus = row.getAttribute('data-status') || 'active';
                const matchesSearch = searchTokens.length === 0 || searchTokens.every(function(token) {
                    return searchText.includes(token);
                });
                const matchesStatus = selectedStatus === 'all' || rowStatus === selectedStatus;
                const show = matchesSearch && matchesStatus;

                row.style.display = show ? '' : 'none';
                if (show) visibleCount++;
            });

            if (emptyState) {
                emptyState.classList.toggle('hidden', visibleCount > 0);
            }
        }

        document.getElementById('search')?.addEventListener('input', filterRecipientsLive);
        document.getElementById('status')?.addEventListener('change', function() {
            filterRecipientsLive();
            this.form?.requestSubmit();
        });
        document.getElementById('sort')?.addEventListener('change', function() {
            this.form?.requestSubmit();
        });
        document.getElementById('recipientsFilterForm')?.addEventListener('submit', function() {
            const pageInput = this.querySelector('input[name="page"]');
            if (pageInput) pageInput.value = '1';
        });
        filterRecipientsLive();

        function exportRecipients() {
            const data = <?php echo json_encode($all_recipients_export); ?>;
            exportToCSV(data, 'recipients_' + new Date().toISOString().split('T')[0] + '.csv');
            MailroomToast.success('Export completed successfully!');
        }
    </script>
    <script src="assets/app.js"></script>
</body>

</html>