<?php
// document_types.php

// Start session for messages
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include database configuration
require_once 'config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/audit.php';

// Create connection with error handling
function getConnection()
{
    global $conn;

    // Check if connection exists from db.php
    if (!isset($conn) || $conn->connect_error) {
        error_log("Database connection not available");
        return null;
    }

    return $conn;
}

// Set flash message
function setFlashMessage($type, $message)
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

// Get all document types with pagination
function getAllDocumentTypes($sort_by = 'type_name', $sort_order = 'ASC', $filter = '', $limit = 10, $offset = 0)
{
    $conn = getConnection();
    if (!$conn) {
        return ['data' => [], 'total' => 0];
    }

    // Validate sort parameters to prevent SQL injection
    $allowed_sort = ['id', 'type_name', 'description', 'created_at'];
    $sort_by = in_array($sort_by, $allowed_sort) ? $sort_by : 'type_name';
    $sort_order = strtoupper($sort_order) == 'DESC' ? 'DESC' : 'ASC';

    // Build WHERE clause
    $where_sql = '';
    $params = [];
    $types = '';
    if (!empty($filter)) {
        $where_sql = " WHERE dt.type_name LIKE ? OR dt.description LIKE ?";
        $filter_pattern = '%' . $filter . '%';
        $params[] = $filter_pattern;
        $params[] = $filter_pattern;
        $types .= 'ss';
    }

    // Get total count for pagination
    $count_sql = "SELECT COUNT(DISTINCT dt.id) as total FROM document_types dt" . $where_sql;
    $count_stmt = $conn->prepare($count_sql);
    if (!empty($params)) {
        $count_stmt->bind_param($types, ...$params);
    }
    $count_stmt->execute();
    $total = $count_stmt->get_result()->fetch_assoc()['total'];
    $count_stmt->close();

    // Main query with pagination
    $sql = "SELECT dt.* FROM document_types dt" . $where_sql . " ORDER BY $sort_by $sort_order LIMIT $limit OFFSET $offset";
    $result = $conn->prepare($sql);
    if (!empty($params)) {
        $result->bind_param($types, ...$params);
    }
    $result->execute();
    $result = $result->get_result();

    if (!$result) {
        error_log("Error in getAllDocumentTypes: " . $conn->error);
        return ['data' => [], 'total' => 0];
    }

    $types = [];
    while ($row = $result->fetch_assoc()) {
        $types[] = $row;
    }

    return ['data' => $types, 'total' => $total];
}

// Get single document type by ID
function getDocumentTypeById($id)
{
    $conn = getConnection();
    if (!$conn) {
        return null;
    }

    $stmt = $conn->prepare("SELECT * FROM document_types WHERE id = ?");
    if (!$stmt) {
        error_log("Error preparing statement: " . $conn->error);
        return null;
    }

    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $type = $result->fetch_assoc();
    $stmt->close();

    return $type;
}

// Create new document type
function createDocumentType($type_name, $description = '')
{
    $conn = getConnection();
    if (!$conn) {
        return ['success' => false, 'message' => 'Database connection error'];
    }

    // Check if type already exists
    $check = $conn->prepare("SELECT id FROM document_types WHERE type_name = ?");
    if (!$check) {
        return ['success' => false, 'message' => 'Error preparing statement'];
    }

    $check->bind_param("s", $type_name);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $check->close();
        return ['success' => false, 'message' => 'Document type already exists!'];
    }
    $check->close();

    // Insert new type
    $stmt = $conn->prepare("INSERT INTO document_types (type_name, description) VALUES (?, ?)");
    if (!$stmt) {
        return ['success' => false, 'message' => 'Error preparing insert statement'];
    }

    $stmt->bind_param("ss", $type_name, $description);

    if ($stmt->execute()) {
        $stmt->close();
        return ['success' => true, 'message' => 'Document type created successfully!'];
    } else {
        $error = $stmt->error;
        $stmt->close();
        return ['success' => false, 'message' => 'Error: ' . $error];
    }
}

// Update document type
function updateDocumentType($id, $type_name, $description = '')
{
    $conn = getConnection();
    if (!$conn) {
        return ['success' => false, 'message' => 'Database connection error'];
    }

    // Check if type name already exists (excluding current ID)
    $check = $conn->prepare("SELECT id FROM document_types WHERE type_name = ? AND id != ?");
    if (!$check) {
        return ['success' => false, 'message' => 'Error preparing check statement'];
    }

    $check->bind_param("si", $type_name, $id);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $check->close();
        return ['success' => false, 'message' => 'Document type name already exists!'];
    }
    $check->close();

    // Update type
    $stmt = $conn->prepare("UPDATE document_types SET type_name = ?, description = ? WHERE id = ?");
    if (!$stmt) {
        return ['success' => false, 'message' => 'Error preparing update statement'];
    }

    $stmt->bind_param("ssi", $type_name, $description, $id);

    if ($stmt->execute()) {
        $stmt->close();
        return ['success' => true, 'message' => 'Document type updated successfully!'];
    } else {
        $error = $stmt->error;
        $stmt->close();
        return ['success' => false, 'message' => 'Error: ' . $error];
    }
}

// Delete document type
function deleteDocumentType($id)
{
    $conn = getConnection();
    if (!$conn) {
        return ['success' => false, 'message' => 'Database connection error'];
    }

    // Check if type is being used in documents
    $check = $conn->prepare("SELECT COUNT(*) as count FROM documents WHERE type_id = ?");
    if (!$check) {
        return ['success' => false, 'message' => 'Error preparing check statement'];
    }

    $check->bind_param("i", $id);
    $check->execute();
    $result = $check->get_result();
    $row = $result->fetch_assoc();
    $check->close();

    if ($row['count'] > 0) {
        return ['success' => false, 'message' => 'Cannot delete: This document type is used by ' . $row['count'] . ' document(s)'];
    }

    // Delete type
    $stmt = $conn->prepare("DELETE FROM document_types WHERE id = ?");
    if (!$stmt) {
        return ['success' => false, 'message' => 'Error preparing delete statement'];
    }

    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        $stmt->close();
        return ['success' => true, 'message' => 'Document type deleted successfully!'];
    } else {
        $error = $stmt->error;
        $stmt->close();
        return ['success' => false, 'message' => 'Error: ' . $error];
    }
}

// Get documents by type
function getDocumentsByType($type_id)
{
    $conn = getConnection();
    if (!$conn) {
        return [];
    }

    $stmt = $conn->prepare("SELECT * FROM documents WHERE type_id = ? ORDER BY date_received DESC");
    if (!$stmt) {
        error_log("Error preparing statement: " . $conn->error);
        return [];
    }

    $stmt->bind_param("i", $type_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $documents = [];
    while ($row = $result->fetch_assoc()) {
        $documents[] = $row;
    }
    $stmt->close();

    return $documents;
}

// Handle AJAX requests
if (isset($_POST['ajax_action'])) {
    // CSRF check for state-changing actions
    if (in_array($_POST['ajax_action'], ['create', 'update', 'delete'])) {
        if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit();
        }
    }
    header('Content-Type: application/json');

    if ($_POST['ajax_action'] == 'create') {
        $type_name = trim($_POST['type_name']);
        $description = trim($_POST['description']);

        if (empty($type_name)) {
            echo json_encode(['success' => false, 'message' => 'Document type name is required!']);
        } else {
            $result = createDocumentType($type_name, $description);
            if ($result['success']) {
                audit_log('create', 'doc_type', $conn->insert_id, "Created document type '" . addslashes($type_name) . "'");
            }
            echo json_encode($result);
        }
        exit();
    }

    if ($_POST['ajax_action'] == 'update') {
        $id = $_POST['id'];
        $type_name = trim($_POST['type_name']);
        $description = trim($_POST['description']);

        if (empty($type_name)) {
            echo json_encode(['success' => false, 'message' => 'Document type name is required!']);
        } else {
            $result = updateDocumentType($id, $type_name, $description);
            if ($result['success']) {
                audit_log('update', 'doc_type', $id, "Updated document type '" . addslashes($type_name) . "'");
            }
            echo json_encode($result);
        }
        exit();
    }

    if ($_POST['ajax_action'] == 'delete') {
        $id = $_POST['id'];
        $del_type = getDocumentTypeById($id);
        $del_type_name = $del_type['type_name'] ?? '';
        $result = deleteDocumentType($id);
        if ($result['success']) {
            audit_log('delete', 'doc_type', $id, "Deleted document type '" . addslashes($del_type_name) . "'");
        }
        echo json_encode($result);
        exit();
    }

    if ($_POST['ajax_action'] == 'get_type') {
        $id = $_POST['id'];
        $type = getDocumentTypeById($id);
        if ($type) {
            $documents = getDocumentsByType($id);
            echo json_encode(['success' => true, 'type' => $type, 'documents' => $documents]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Document type not found!']);
        }
        exit();
    }
}

// Handle form submissions
$action = isset($_GET['action']) ? $_GET['action'] : 'list';

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_action'])) {
    if (isset($_POST['create'])) {
        $type_name = trim($_POST['type_name']);
        $description = trim($_POST['description']);

        if (empty($type_name)) {
            setFlashMessage('danger', 'Document type name is required!');
        } else {
            $result = createDocumentType($type_name, $description);
            if ($result['success']) {
                audit_log('create', 'doc_type', $conn->insert_id, "Created document type '" . addslashes($type_name) . "'");
            }
            setFlashMessage($result['success'] ? 'success' : 'danger', $result['message']);
            if ($result['success']) {
                header('Location: document_types.php?action=list');
                exit();
            }
        }
    } elseif (isset($_POST['update'])) {
        $id = $_POST['id'];
        $type_name = trim($_POST['type_name']);
        $description = trim($_POST['description']);

        if (empty($type_name)) {
            setFlashMessage('danger', 'Document type name is required!');
        } else {
            $result = updateDocumentType($id, $type_name, $description);
            if ($result['success']) {
                audit_log('update', 'doc_type', $id, "Updated document type '" . addslashes($type_name) . "'");
            }
            setFlashMessage($result['success'] ? 'success' : 'danger', $result['message']);
            if ($result['success']) {
                header('Location: document_types.php?action=list');
                exit();
            }
        }
    }
}

// Handle POST requests for delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete' && isset($_POST['id'])) {
    $del_id = (int)$_POST['id'];
    $del_type = getDocumentTypeById($del_id);
    $del_type_name = $del_type['type_name'] ?? '';
    $result = deleteDocumentType($del_id);
    if ($result['success']) {
        audit_log('delete', 'doc_type', $del_id, "Deleted document type '" . addslashes($del_type_name) . "'");
    }
    setFlashMessage($result['success'] ? 'success' : 'danger', $result['message']);
    header('Location: document_types.php?action=list');
    exit();
}

// Get sorting, filtering and pagination parameters
$allowed_sort_cols = ['id', 'type_name', 'description', 'created_at'];
$sort_by = isset($_GET['sort_by']) && in_array($_GET['sort_by'], $allowed_sort_cols, true) ? $_GET['sort_by'] : 'type_name';
$sort_order = isset($_GET['sort_order']) && strtoupper($_GET['sort_order']) === 'DESC' ? 'DESC' : 'ASC';
$filter = isset($_GET['filter']) ? $_GET['filter'] : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
$offset = ($page - 1) * $limit;

// Get flash message
$flashMessage = '';
$flashType = '';
if (isset($_SESSION['flash'])) {
    $flashMessage = $_SESSION['flash']['message'];
    $flashType = $_SESSION['flash']['type'];
    unset($_SESSION['flash']);
}

// Get all document types for list view
$typesData = ['data' => [], 'total' => 0];
$types = [];
$totalRecords = 0;
$totalPages = 0;
$stats_types = [];

if ($action == 'list') {
    $typesData = getAllDocumentTypes($sort_by, $sort_order, $filter, $limit, $offset);
    $types = $typesData['data'];
    $totalRecords = $typesData['total'];
    $totalPages = ceil($totalRecords / $limit);
} elseif ($action == 'stats') {
    // For stats view, get all records without pagination
    $stats_types = getAllDocumentTypes('type_name', 'ASC', '', 1000, 0)['data'];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Types - Mailroom Ops</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/app.css">
</head>

<body>
    <input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars(csrf_token()); ?>">

    <div class="flex">
        <?php include './sidebar.php'; ?>
        <main class="main-content">
            <!-- Header -->
            <div class="page-header flex items-center justify-between gap-4 flex-wrap">
                <div>
                    <div class="breadcrumb">
                        <a href="index.php">Management</a>
                        <span class="sep">/</span>
                        <span>Document Types</span>
                    </div>
                    <h1 class="page-header-title">Document Types</h1>
                    <p class="page-header-subtitle">Define and manage the categories for incoming documents.</p>
                </div>
                <div class="header-actions flex items-center gap-2 print-hide">
                    <button onclick="exportToCSV()" class="btn btn-soft">
                        <i class="fa-regular fa-file-excel"></i>
                        <span class="hidden sm:inline">Export</span>
                    </button>
                    <a href="?action=<?php echo $action == 'list' ? 'stats' : 'list'; ?>" class="btn btn-soft">
                        <i class="fa-regular fa-chart-bar"></i>
                        <span class="hidden sm:inline"><?php echo $action == 'list' ? 'Stats' : 'List'; ?></span>
                    </a>
                    <button onclick="openCreateModal()" class="btn btn-primary">
                        <i class="fa-solid fa-plus"></i>
                        <span class="hidden sm:inline">New Type</span>
                    </button>
                </div>
            </div>

            <div class="page-body">
                <?php if ($flashMessage): ?>
                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            MailroomToast.show(<?php echo json_encode($flashMessage); ?>, '<?php echo $flashType; ?>');
                        });
                    </script>
                <?php endif; ?>

                <?php
                // Check database connection
                $conn_check = getConnection();
                if (!$conn_check):
                ?>
                    <div class="alert alert-red">
                        <i class="fa-regular fa-circle-exclamation"></i>
                        Database connection error. Please check your configuration.
                    </div>
                <?php
                endif;

                // LIST ALL DOCUMENT TYPES
                if ($action == 'list'):
                ?>
                    <!-- Search and Filter Bar -->
                    <div class="card mb-6 print-hide">
                        <div class="card-body" style="padding:14px 16px;">
                            <div class="filter-bar" style="margin-bottom:0;">
                                <div class="search-wrap" style="flex:1;min-width:260px;">
                                    <i class="fa-solid fa-magnifying-glass icon"></i>
                                    <input type="text" id="searchInput" placeholder="Search by name or description..."
                                        value="<?php echo htmlspecialchars($filter); ?>"
                                        class="input" autocomplete="off">
                                </div>
                                <select id="itemsPerPage" class="select" onchange="changeItemsPerPage()">
                                    <option value="5" <?php echo $limit == 5 ? 'selected' : ''; ?>>5 per page</option>
                                    <option value="10" <?php echo $limit == 10 ? 'selected' : ''; ?>>10 per page</option>
                                    <option value="25" <?php echo $limit == 25 ? 'selected' : ''; ?>>25 per page</option>
                                    <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50 per page</option>
                                    <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100 per page</option>
                                </select>
                                <button onclick="applyFilter()" class="btn btn-primary">
                                    <i class="fa-solid fa-filter"></i> Search
                                </button>
                                <button onclick="clearFilter()" class="btn btn-soft">
                                    <i class="fa-solid fa-rotate-left"></i> Clear
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Table -->
                    <div class="card">
                        <div class="table-wrap">
                            <table id="typesTable" class="table">
                                <thead>
                                    <tr>
                                        <th class="table-sortable hidden md:table-cell <?php echo $sort_by == 'id' ? '' : ''; ?>" onclick="sortTable('id')">
                                            ID
                                            <?php if ($sort_by == 'id'): ?>
                                                <i class="fa-solid fa-chevron-<?php echo $sort_order == 'ASC' ? 'up' : 'down'; ?> sort-ic"></i>
                                            <?php endif; ?>
                                        </th>
                                        <th class="table-sortable <?php echo $sort_by == 'type_name' ? '' : ''; ?>" onclick="sortTable('type_name')">
                                            Type Name
                                            <?php if ($sort_by == 'type_name'): ?>
                                                <i class="fa-solid fa-chevron-<?php echo $sort_order == 'ASC' ? 'up' : 'down'; ?> sort-ic"></i>
                                            <?php endif; ?>
                                        </th>
                                        <th class="table-sortable hidden md:table-cell <?php echo $sort_by == 'description' ? '' : ''; ?>" onclick="sortTable('description')">
                                            Description
                                            <?php if ($sort_by == 'description'): ?>
                                                <i class="fa-solid fa-chevron-<?php echo $sort_order == 'ASC' ? 'up' : 'down'; ?> sort-ic"></i>
                                            <?php endif; ?>
                                        </th>
                                        <th class="table-sortable <?php echo $sort_by == 'created_at' ? '' : ''; ?>" onclick="sortTable('created_at')">
                                            Created
                                            <?php if ($sort_by == 'created_at'): ?>
                                                <i class="fa-solid fa-chevron-<?php echo $sort_order == 'ASC' ? 'up' : 'down'; ?> sort-ic"></i>
                                            <?php endif; ?>
                                        </th>
                                        <th style="width:100px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="tableBody">
                                    <?php if (empty($types)): ?>
                                        <tr>
                                            <td colspan="5">
                                                <div class="empty-state">
                                                    <div class="empty-state-icon"><i class="fa-regular fa-folder-open"></i></div>
                                                    <div class="empty-state-title">No document types found</div>
                                                    <div class="empty-state-text">Create your first document type to get started.</div>
                                                    <button onclick="openCreateModal()" class="btn btn-primary btn-sm" style="margin-top:16px;">Create one</button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($types as $type): ?>
                                            <tr class="type-row" id="row-<?php echo $type['id']; ?>"
                                                data-search="<?php echo strtolower(htmlspecialchars(trim($type['id'] . ' ' . ($type['type_name'] ?? '') . ' ' . ($type['description'] ?? '') . ' ' . date('M j, Y', strtotime($type['created_at']))))); ?>">
                                                <td class="hidden md:table-cell"><span class="table-cell-mono text-[#7d8398]"><?php echo $type['id']; ?></span></td>
                                                <td>
                                                    <span class="table-cell-title"><?php echo htmlspecialchars($type['type_name']); ?></span>
                                                </td>
                                                <td class="hidden md:table-cell">
                                                    <span class="text-xs text-[#4b5570] max-w-[280px] block truncate"><?php echo htmlspecialchars($type['description'] ?? '-'); ?></span>
                                                </td>
                                                <td>
                                                    <span class="text-xs text-[#4b5570]"><?php echo date('M j, Y', strtotime($type['created_at'])); ?></span>
                                                </td>
                                                <td>
                                                    <div class="row-actions">
                                                        <button onclick="viewType(<?php echo $type['id']; ?>)" class="icon-btn" title="View Details">
                                                            <i class="fa-regular fa-eye"></i>
                                                        </button>
                                                        <button onclick="editType(<?php echo $type['id']; ?>)" class="icon-btn primary" title="Edit">
                                                            <i class="fa-regular fa-pen-to-square"></i>
                                                        </button>
                                                        <button onclick="confirmDelete(<?php echo $type['id']; ?>, '<?php echo htmlspecialchars($type['type_name'], ENT_QUOTES); ?>')" class="icon-btn danger" title="Delete">
                                                            <i class="fa-regular fa-trash-can"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <tr id="noResultsRow" class="hidden">
                                            <td colspan="5">
                                                <div class="empty-state">
                                                    <div class="empty-state-icon"><i class="fa-regular fa-magnifying-glass"></i></div>
                                                    <div class="empty-state-title">No matching document types</div>
                                                    <div class="empty-state-text">No document types match your search on this page.</div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($totalPages > 0): ?>
                            <?php
                            $pageStart = $totalRecords > 0 ? $offset + 1 : 0;
                            $pageEnd = min($offset + count($types), $totalRecords);
                            ?>
                            <div class="pagination-shell">
                                <div class="pagination-meta">
                                    <div class="pagination-title">
                                        Showing <span id="visibleTypeCount"><?php echo count($types); ?></span> item<?php echo count($types) === 1 ? '' : 's'; ?> on this page
                                    </div>
                                    <span>Records <?php echo $pageStart; ?>-<?php echo $pageEnd; ?> of <?php echo $totalRecords; ?> total</span>
                                </div>
                                <div class="pagination-controls">
                                    <div class="pagination-page-indicator">Page <?php echo $page; ?> of <?php echo $totalPages; ?></div>
                                    <div class="pagination">
                                        <button onclick="goToPage(1)" class="pagination-item compact <?php echo $page <= 1 ? 'disabled' : ''; ?>" <?php echo $page <= 1 ? 'disabled' : ''; ?> aria-label="First page">
                                            <i class="fa-solid fa-chevrons-left"></i>
                                        </button>
                                        <button onclick="goToPage(<?php echo $page - 1; ?>)" class="pagination-item compact <?php echo $page <= 1 ? 'disabled' : ''; ?>" <?php echo $page <= 1 ? 'disabled' : ''; ?> aria-label="Previous page">
                                            <i class="fa-solid fa-chevron-left"></i>
                                        </button>
                                        <?php
                                        $startPage = max(1, $page - 2);
                                        $endPage = min($totalPages, $page + 2);

                                        if ($startPage > 1) {
                                            echo '<button onclick="goToPage(1)" class="pagination-item">1</button>';
                                            if ($startPage > 2) {
                                                echo '<span class="pagination-ellipsis">...</span>';
                                            }
                                        }

                                        for ($i = $startPage; $i <= $endPage; $i++) {
                                            $activeClass = $i == $page ? 'active' : '';
                                            echo '<button onclick="goToPage(' . $i . ')" class="pagination-item ' . $activeClass . '">' . $i . '</button>';
                                        }

                                        if ($endPage < $totalPages) {
                                            if ($endPage < $totalPages - 1) {
                                                echo '<span class="pagination-ellipsis">...</span>';
                                            }
                                            echo '<button onclick="goToPage(' . $totalPages . ')" class="pagination-item">' . $totalPages . '</button>';
                                        }
                                        ?>
                                        <button onclick="goToPage(<?php echo $page + 1; ?>)" class="pagination-item compact <?php echo $page >= $totalPages ? 'disabled' : ''; ?>" <?php echo $page >= $totalPages ? 'disabled' : ''; ?> aria-label="Next page">
                                            <i class="fa-solid fa-chevron-right"></i>
                                        </button>
                                        <button onclick="goToPage(<?php echo $totalPages; ?>)" class="pagination-item compact <?php echo $page >= $totalPages ? 'disabled' : ''; ?>" <?php echo $page >= $totalPages ? 'disabled' : ''; ?> aria-label="Last page">
                                            <i class="fa-solid fa-chevrons-right"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php
                // STATISTICS
                elseif ($action == 'stats'):
                    $totalTypes = count($stats_types);
                ?>
                    <!-- Stats Overview -->
                    <div class="stat-grid mb-6">
                        <div class="stat-card">
                            <div class="stat-icon blue"><i class="fa-regular fa-folder-open"></i></div>
                            <div class="stat-label">Total Types</div>
                            <div class="stat-value"><?php echo number_format($totalTypes); ?></div>
                            <div class="stat-hint">All documented categories</div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header" style="padding:14px 20px;">
                            <div>
                                <div class="card-title">All Document Types</div>
                                <div class="card-subtitle">Complete list of defined document categories</div>
                            </div>
                        </div>
                        <div class="table-wrap">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Type Name</th>
                                        <th>Description</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($stats_types)): ?>
                                        <tr>
                                            <td colspan="2">
                                                <div class="empty-state">
                                                    <div class="empty-state-icon"><i class="fa-regular fa-folder-open"></i></div>
                                                    <div class="empty-state-title">No document types</div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($stats_types as $type): ?>
                                            <tr>
                                                <td><span class="table-cell-title"><?php echo htmlspecialchars($type['type_name']); ?></span></td>
                                                <td><span class="text-xs text-[#4b5570]"><?php echo htmlspecialchars($type['description'] ?? '-'); ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="mt-4">
                        <a href="?action=list" class="btn btn-soft">
                            <i class="fa-solid fa-arrow-left"></i> Back to List
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Toast Container -->
    <div id="toastContainer" class="toast-container"></div>

    <!-- Create/Edit Modal -->
    <div id="typeModal" class="modal-backdrop" style="display:none;">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 id="modalTitle" class="modal-title">Create Document Type</h3>
                <button type="button" class="modal-close" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form id="typeForm" onsubmit="return false;">
                <input type="hidden" id="typeId" name="id">
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-field span-2">
                            <label class="label">Type Name <span class="req">*</span></label>
                            <input type="text" id="typeName" name="type_name" required
                                placeholder="e.g., Legislative Documents"
                                class="input" autocomplete="off">
                        </div>
                        <div class="form-field span-2">
                            <label class="label">Description</label>
                            <textarea id="typeDescription" name="description" rows="4"
                                placeholder="Optional description"
                                class="input" autocomplete="off"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeModal()" class="btn btn-soft">Cancel</button>
                    <button type="button" onclick="saveType()" class="btn btn-primary">
                        <i class="fa-solid fa-floppy-disk"></i> Save
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Modal -->
    <div id="viewModal" class="modal-backdrop" style="display:none;">
        <div class="modal-dialog lg">
            <div class="modal-header">
                <div class="flex items-center gap-3">
                    <div class="stat-icon blue" style="margin:0;"><i class="fa-regular fa-folder-open"></i></div>
                    <div>
                        <h3 class="modal-title">Document Type Details</h3>
                        <div class="text-xs text-[#7d8398]" id="viewTypeName"></div>
                    </div>
                </div>
                <button type="button" class="modal-close" onclick="closeViewModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <div id="viewContent" class="space-y-4">
                </div>
            </div>
            <div class="modal-footer">
                <button onclick="closeViewModal()" class="btn btn-soft">Close</button>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal-backdrop" style="display:none;">
        <div class="modal-dialog sm">
            <div class="modal-header">
                <h3 class="modal-title">Confirm Delete</h3>
                <button type="button" class="modal-close" onclick="closeDeleteModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <div class="notice-bar" style="background:var(--red-soft);border-color:var(--red-border);color:var(--red);">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span>Are you sure you want to delete <span id="deleteTypeName" class="font-medium"></span>? This action cannot be undone.</span>
                </div>
            </div>
            <div class="modal-footer">
                <button onclick="closeDeleteModal()" class="btn btn-soft">Cancel</button>
                <button onclick="deleteType()" id="confirmDeleteBtn" class="btn btn-danger">
                    <i class="fa-regular fa-trash-can"></i> Delete
                </button>
            </div>
        </div>
    </div>

    <script>
        // Current delete ID
        let currentDeleteId = null;
        let currentEditId = null;

        // Toast notification function
        function showToast(message, type = 'success') {
            if (type === 'success') {
                MailroomToast.success(message);
            } else {
                MailroomToast.error(message);
            }
        }

        function csrfToken() {
            return document.getElementById('csrfToken').value;
        }

        // Modal functions
        function openCreateModal() {
            document.getElementById('modalTitle').textContent = 'Create Document Type';
            document.getElementById('typeId').value = '';
            document.getElementById('typeName').value = '';
            document.getElementById('typeDescription').value = '';
            MailroomModal.open('typeModal');
        }

        function editType(id) {
            currentEditId = id;

            // Fetch type data
            const url = '<?php echo $_SERVER['PHP_SELF']; ?>';
            const body = new URLSearchParams({ ajax_action: 'get_type', id: id, csrf_token: csrfToken() });

            fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('modalTitle').textContent = 'Edit Document Type';
                        document.getElementById('typeId').value = data.type.id;
                        document.getElementById('typeName').value = data.type.type_name;
                        document.getElementById('typeDescription').value = data.type.description || '';
                        MailroomModal.open('typeModal');
                    } else {
                        showToast(data.message, 'danger');
                    }
                });
        }

        function viewType(id) {
            const url = '<?php echo $_SERVER['PHP_SELF']; ?>';
            const body = new URLSearchParams({ ajax_action: 'get_type', id: id, csrf_token: csrfToken() });

            fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const type = data.type;
                        const documents = data.documents;

                        document.getElementById('viewTypeName').textContent = escapeHtml(type.type_name);

                        let documentsHtml = '';
                        if (documents.length > 0) {
                            documentsHtml = documents.map(doc => `
                            <div class="doc-item">
                                <div>
                                    <p class="text-sm font-medium text-[#1b2a4a]">${escapeHtml(doc.document_name)}</p>
                                    <p class="text-xs text-[#7d8398] mt-0.5">Origin: ${escapeHtml(doc.origin || 'N/A')} &middot; Copies: ${doc.copies_received}</p>
                                </div>
                            </div>
                        `).join('');
                        } else {
                            documentsHtml = '<p class="text-sm text-[#7d8398]">No documents of this type.</p>';
                        }

                        document.getElementById('viewContent').innerHTML = `
                        <div class="grid grid-cols-2 gap-5">
                            <div>
                                <div class="label">System ID</div>
                                <p class="text-sm font-mono text-[#7d8398]">${type.id}</p>
                            </div>
                            <div>
                                <div class="label">Name</div>
                                <p class="text-sm font-medium text-[#1b2a4a]">${escapeHtml(type.type_name)}</p>
                            </div>
                            <div class="col-span-2">
                                <div class="label">Description</div>
                                <p class="text-sm text-[#1b2a4a]">${escapeHtml(type.description || 'No description')}</p>
                            </div>
                            <div>
                                <div class="label">Created</div>
                                <p class="text-sm text-[#1b2a4a]">${new Date(type.created_at).toLocaleDateString()}</p>
                            </div>
                            <div>
                                <div class="label">Documents</div>
                                <p class="text-sm text-[#1b2a4a]">${documents.length}</p>
                            </div>
                            <div class="col-span-2">
                                <div class="label" style="margin-bottom:8px;">Documents of this Type</div>
                                <div class="space-y-2 max-h-60 overflow-y-auto">
                                    ${documentsHtml}
                                </div>
                            </div>
                        </div>
                    `;
                        MailroomModal.open('viewModal');
                    } else {
                        showToast(data.message, 'danger');
                    }
                });
        }

        function escapeHtml(text) {
            if (text == null) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function closeModal() {
            MailroomModal.close('typeModal');
        }

        function closeViewModal() {
            MailroomModal.close('viewModal');
        }

        function closeDeleteModal() {
            MailroomModal.close('deleteModal');
            currentDeleteId = null;
        }

        function saveType() {
            const id = document.getElementById('typeId').value;
            const typeName = document.getElementById('typeName').value;
            const description = document.getElementById('typeDescription').value;

            if (!typeName.trim()) {
                showToast('Document type name is required!', 'danger');
                return;
            }

            const action = id ? 'update' : 'create';
            const params = new URLSearchParams({
                ajax_action: action,
                type_name: typeName,
                description: description,
                csrf_token: csrfToken()
            });
            if (id) params.set('id', id);

            fetch('<?php echo $_SERVER['PHP_SELF']; ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: params.toString()
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message, 'success');
                        closeModal();
                        setTimeout(() => {
                            location.reload();
                        }, 1000);
                    } else {
                        showToast(data.message, 'danger');
                    }
                });
        }

        function confirmDelete(id, name) {
            currentDeleteId = id;
            document.getElementById('deleteTypeName').textContent = name;
            MailroomModal.open('deleteModal');
        }

        function deleteType() {
            if (!currentDeleteId) return;

            const params = new URLSearchParams({
                ajax_action: 'delete',
                id: currentDeleteId,
                csrf_token: csrfToken()
            });

            fetch('<?php echo $_SERVER['PHP_SELF']; ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: params.toString()
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message, 'success');
                        closeDeleteModal();
                        // Remove row from table
                        const row = document.getElementById('row-' + currentDeleteId);
                        if (row) {
                            row.style.transition = 'opacity 0.3s';
                            row.style.opacity = '0';
                            setTimeout(() => {
                                row.remove();
                                // Check if table is empty
                                if (document.querySelectorAll('#tableBody tr').length === 0) {
                                    location.reload();
                                }
                            }, 300);
                        } else {
                            location.reload();
                        }
                    } else {
                        showToast(data.message, 'danger');
                        closeDeleteModal();
                    }
                });
        }

        // Sorting
        function sortTable(column) {
            const currentSort = '<?php echo $sort_by; ?>';
            const currentOrder = '<?php echo $sort_order; ?>';
            const filter = '<?php echo $filter; ?>';
            const limit = '<?php echo $limit; ?>';

            let newOrder = 'ASC';
            if (column === currentSort) {
                newOrder = currentOrder === 'ASC' ? 'DESC' : 'ASC';
            }

            window.location.href = `?action=list&sort_by=${column}&sort_order=${newOrder}&filter=${encodeURIComponent(filter)}&limit=${limit}`;
        }

        // Filtering
        function applyFilter() {
            const filter = document.getElementById('searchInput').value;
            const limit = document.getElementById('itemsPerPage').value;
            window.location.href = `?action=list&sort_by=<?php echo $sort_by; ?>&sort_order=<?php echo $sort_order; ?>&filter=${encodeURIComponent(filter)}&limit=${limit}`;
        }

        function clearFilter() {
            document.getElementById('searchInput').value = '';
            const limit = document.getElementById('itemsPerPage').value;
            window.location.href = `?action=list&limit=${limit}`;
        }

        function filterTableLive() {
            const searchTokens = (document.getElementById('searchInput')?.value || '')
                .toLowerCase()
                .split(/\s+/)
                .filter(Boolean);
            const rows = document.querySelectorAll('.type-row');
            const noResultsRow = document.getElementById('noResultsRow');
            let visibleCount = 0;

            rows.forEach(row => {
                const searchText = row.getAttribute('data-search') || '';
                const matches = searchTokens.length === 0 || searchTokens.every(token => searchText.includes(token));
                row.style.display = matches ? '' : 'none';
                if (matches) {
                    visibleCount++;
                }
            });

            if (noResultsRow) {
                noResultsRow.classList.toggle('hidden', visibleCount !== 0 || rows.length === 0);
            }

            const visibleTypeCount = document.getElementById('visibleTypeCount');
            if (visibleTypeCount) {
                visibleTypeCount.textContent = visibleCount;
            }
        }

        // Pagination
        function goToPage(page) {
            const filter = '<?php echo $filter; ?>';
            const sortBy = '<?php echo $sort_by; ?>';
            const sortOrder = '<?php echo $sort_order; ?>';
            const limit = '<?php echo $limit; ?>';

            window.location.href = `?action=list&page=${page}&sort_by=${sortBy}&sort_order=${sortOrder}&filter=${encodeURIComponent(filter)}&limit=${limit}`;
        }

        function changeItemsPerPage() {
            const limit = document.getElementById('itemsPerPage').value;
            const filter = document.getElementById('searchInput').value;
            window.location.href = `?action=list&filter=${encodeURIComponent(filter)}&limit=${limit}`;
        }

        // Export to CSV
        function exportToCSV() {
            const data = [];
            <?php foreach ($types as $type): ?>
                data.push({
                    'ID': '<?php echo $type['id']; ?>',
                    'Type Name': '<?php echo addslashes($type['type_name']); ?>',
                    'Description': '<?php echo addslashes($type['description'] ?? ''); ?>',
                    'Created At': '<?php echo $type['created_at']; ?>'
                });
            <?php endforeach; ?>

            const filename = 'document_types_' + new Date().toISOString().split('T')[0] + '.csv';
            window.exportToCSV(data, filename);
        }

        // Print table
        function printTable() {
            const printContent = `
                <html>
                <head>
                    <title>Document Types</title>
                </head>
                <body>
                    <div class="header">
                        <h1>Document Types</h1>
                        <div class="date">Generated: ${new Date().toLocaleString()}</div>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Type Name</th>
                                <th>Description</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($types as $type): ?>
                            <tr>
                                <td><?php echo $type['id']; ?></td>
                                <td><?php echo htmlspecialchars($type['type_name']); ?></td>
                                <td><?php echo htmlspecialchars($type['description'] ?? '-'); ?></td>
                                <td><?php echo date('M j, Y', strtotime($type['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </body>
                </html>
            `;
            printHtmlOnPage(printContent);

            showToast('Print dialog opened', 'success');
        }

        function printHtmlOnPage(html) {
            const frame = document.createElement('iframe');
            frame.style.position = 'fixed';
            frame.style.right = '0';
            frame.style.bottom = '0';
            frame.style.width = '0';
            frame.style.height = '0';
            frame.style.border = '0';
            document.body.appendChild(frame);

            const frameWindow = frame.contentWindow;
            const frameDocument = frameWindow.document;
            frameDocument.open();
            frameDocument.write(html);
            frameDocument.close();

            frameWindow.focus();
            frameWindow.print();

            setTimeout(() => {
                frame.remove();
            }, 1000);
        }

        // Enter key for search
        document.getElementById('searchInput')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                applyFilter();
            }
        });

        document.getElementById('searchInput')?.addEventListener('input', filterTableLive);
    </script>
    <script src="assets/app.js"></script>
</body>

</html>