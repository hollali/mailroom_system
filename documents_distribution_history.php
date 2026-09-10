<?php
// documents_distribution_history.php - View all document distribution records

require_once './config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/csrf.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// ─── AJAX: Get single record for View modal ───────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_record' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $id = (int)$_GET['id'];

    $stmt = $conn->prepare("
        SELECT dd.*, COALESCE(dd.status, 'distributed') AS distribution_status, d.document_name, d.origin, dt.type_name AS document_type
        FROM document_distribution dd
        JOIN documents d ON dd.document_id = d.id
        LEFT JOIN document_types dt ON d.type_id = dt.id
        WHERE dd.id = ?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        echo json_encode(['success' => true, 'record' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Record not found']);
    }
    $stmt->close();
    exit();
}

// ─── Handle Delete ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_record'])) {
    csrf_check_post();
    $id = (int)$_POST['delete_record'];

    $conn->begin_transaction();
    try {
        // Fetch before deleting so we can restore copies
        $get = $conn->prepare("SELECT document_id, number_distributed FROM document_distribution WHERE id = ?");
        $get->bind_param("i", $id);
        $get->execute();
        $dist = $get->get_result()->fetch_assoc();
        $get->close();

        if (!$dist) throw new Exception("Record not found");

        $del = $conn->prepare("DELETE FROM document_distribution WHERE id = ?");
        $del->bind_param("i", $id);
        if (!$del->execute()) throw new Exception("Delete failed: " . $conn->error);
        $del->close();

        // Restore copies to the document
        $upd = $conn->prepare("UPDATE documents SET copies_received = copies_received + ? WHERE id = ?");
        $upd->bind_param("ii", $dist['number_distributed'], $dist['document_id']);
        if (!$upd->execute()) throw new Exception("Restore copies failed: " . $conn->error);
        $upd->close();

        $conn->commit();
        $_SESSION['toast'] = ['type' => 'success', 'message' => 'Distribution record deleted and copies restored successfully'];
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['toast'] = ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()];
    }

    header('Location: documents_distribution_history.php');
    exit();
}

// ─── Pagination & Filters ─────────────────────────────────────────────────────
$page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit  = 10;
$offset = ($page - 1) * $limit;

$search       = isset($_GET['search'])      ? trim($_GET['search'])      : '';
$type_filter  = isset($_GET['type'])        ? trim($_GET['type'])        : '';
$status_filter = isset($_GET['status'])     ? trim($_GET['status'])      : '';
$date_from    = isset($_GET['date_from'])   ? trim($_GET['date_from'])   : '';
$date_to      = isset($_GET['date_to'])     ? trim($_GET['date_to'])     : '';

$has_active_filters = $search !== '' || $type_filter !== '' || $status_filter !== ''
    || $date_from !== '' || $date_to !== '';

// Build WHERE clause
$where_clauses = [];
$params        = [];
$types         = "";

if (!empty($search)) {
    $where_clauses[] = "(CONCAT('DDIST-', DATE_FORMAT(dd.date_distributed,'%Y%m%d'),'-',LPAD(dd.id,4,'0')) LIKE ?
                        OR d.document_name LIKE ?
                        OR dt.type_name LIKE ?
                        OR d.origin LIKE ?)";
    $sp = "%$search%";
    $params = array_merge($params, [$sp, $sp, $sp, $sp]);
    $types .= "ssss";
}

if ($type_filter !== '') {
    $where_clauses[] = "dt.type_name = ?";
    $params[] = $type_filter;
    $types .= "s";
}

if ($status_filter !== '') {
    $where_clauses[] = "COALESCE(dd.status, 'distributed') = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($date_from)) {
    $where_clauses[] = "dd.date_distributed >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    $where_clauses[] = "dd.date_distributed <= ?";
    $params[] = $date_to;
    $types .= "s";
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// ─── Total count ──────────────────────────────────────────────────────────────
$count_sql = "
    SELECT COUNT(*) AS total
    FROM document_distribution dd
    JOIN documents d ON dd.document_id = d.id
    LEFT JOIN document_types dt ON d.type_id = dt.id
    $where_sql
";
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) $count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total_records = $count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();

$total_pages = $total_records > 0 ? ceil($total_records / $limit) : 1;

// ─── Main records query ───────────────────────────────────────────────────────
$sql = "
    SELECT dd.id, dd.document_id, dd.number_received, dd.number_distributed,
           dd.date_distributed, dd.created_at, COALESCE(dd.status, 'distributed') AS distribution_status,
           d.document_name, d.origin,
           dt.type_name AS document_type
    FROM document_distribution dd
    JOIN documents d ON dd.document_id = d.id
    LEFT JOIN document_types dt ON d.type_id = dt.id
    $where_sql
    ORDER BY dd.date_distributed DESC, dd.id DESC
    LIMIT ? OFFSET ?
";

$params[] = $limit;
$params[] = $offset;
$types   .= "ii";

$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$records = $stmt->get_result();
$stmt->close();

// ─── Document types for filter dropdown ───────────────────────────────────────
$types_result = $conn->query("SELECT DISTINCT dt.type_name FROM document_types dt
    INNER JOIN documents d ON d.type_id = dt.id
    INNER JOIN document_distribution dd ON dd.document_id = d.id
    ORDER BY dt.type_name ASC");
$doc_types = [];
while ($tr = $types_result->fetch_assoc()) {
    $doc_types[] = $tr['type_name'];
}

// ─── Summary statistics ───────────────────────────────────────────────────────
$summary = $conn->query("
    SELECT
        COUNT(dd.id)                          AS total_distributions,
        COALESCE(SUM(dd.number_distributed),0) AS total_copies_distributed,
        COUNT(DISTINCT dd.document_id)         AS unique_documents
    FROM document_distribution dd
")->fetch_assoc();

// ─── Build a pagination URL preserving current filters ────────────────────────
function buildDocDistUrl($overrides = [])
{
    $params = $_GET;
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    return 'documents_distribution_history.php' . (!empty($params) ? '?' . http_build_query($params) : '');
}

// ─── Toast ────────────────────────────────────────────────────────────────────
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
    <title>Document Distribution History - Mailroom</title>
    <meta name="description" content="View and manage all document distribution records in the mailroom system.">
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
                        <span>Document Distribution History</span>
                    </div>
                    <h1 class="page-header-title">Document Distribution History</h1>
                    <p class="page-header-subtitle">View and manage all document distribution records.</p>
                </div>
                <div class="header-actions flex items-center gap-2 no-print">
                    <button onclick="window.print()" class="btn btn-soft">
                        <i class="fa-solid fa-print"></i>
                        <span class="hidden sm:inline">Print</span>
                    </button>
                    <a href="distribution.php" class="btn btn-primary">
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

                <div class="print-only mb-5">
                    <h1 class="text-xl font-semibold text-[#1b2a4a]">Document Distribution History Statement</h1>
                    <p class="text-sm text-[#4b5570] mt-1">Generated on <?php echo date('F j, Y g:i A'); ?></p>
                    <p class="text-sm text-[#4b5570]">Total records: <?php echo number_format($total_records); ?> | Total copies distributed: <?php echo number_format($summary['total_copies_distributed']); ?></p>
                </div>

                <!-- Stat Cards -->
                <div class="stat-grid mb-6">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fa-solid fa-file-export"></i></div>
                        <div class="stat-label">Total Distributions</div>
                        <div class="stat-value"><?php echo number_format($summary['total_distributions']); ?></div>
                        <div class="stat-hint">Distribution records</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon gold"><i class="fa-solid fa-copy"></i></div>
                        <div class="stat-label">Copies Distributed</div>
                        <div class="stat-value"><?php echo number_format($summary['total_copies_distributed']); ?></div>
                        <div class="stat-hint">Total copies issued</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fa-regular fa-file-lines"></i></div>
                        <div class="stat-label">Unique Documents</div>
                        <div class="stat-value"><?php echo number_format($summary['unique_documents']); ?></div>
                        <div class="stat-hint">Documents with records</div>
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
                                    placeholder="Document, reference, type, origin..."
                                    value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <select id="typeFilter" name="type" class="select">
                                <option value="">All Types</option>
                                <?php foreach ($doc_types as $dt): ?>
                                    <option value="<?php echo htmlspecialchars($dt); ?>"
                                        <?php echo $type_filter === $dt ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dt); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <select id="statusFilter" name="status" class="select">
                                <option value="">All Statuses</option>
                                <option value="distributed" <?php echo $status_filter === 'distributed' ? 'selected' : ''; ?>>Distributed</option>
                                <option value="withdrawn" <?php echo $status_filter === 'withdrawn' ? 'selected' : ''; ?>>Withdrawn</option>
                            </select>
                            <input type="date" name="date_from" class="input" value="<?php echo htmlspecialchars($date_from); ?>">
                            <input type="date" name="date_to" class="input" value="<?php echo htmlspecialchars($date_to); ?>">
                            <button type="submit" class="btn btn-primary">
                                <i class="fa-solid fa-filter"></i> Filter
                            </button>
                            <a href="documents_distribution_history.php" class="btn btn-soft">
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
                                <?php if ($total_records > 0): ?>
                                    Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $limit, $total_records); ?> of <?php echo number_format($total_records); ?>
                                <?php else: ?>
                                    No records to display
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="table-wrap">
                        <table class="table" id="historyTable">
                            <thead>
                                <tr>
                                    <th class="table-sortable" onclick="sortTable(0)">Reference <i class="fa-solid fa-sort sort-ic"></i></th>
                                    <th class="table-sortable" onclick="sortTable(1)">Document <i class="fa-solid fa-sort sort-ic"></i></th>
                                    <th class="table-sortable hidden md:table-cell" onclick="sortTable(2)">Type <i class="fa-solid fa-sort sort-ic"></i></th>
                                    <th class="table-sortable" onclick="sortTable(3)">Copies Distributed <i class="fa-solid fa-sort sort-ic"></i></th>
                                    <th class="table-sortable" onclick="sortTable(4)">Date <i class="fa-solid fa-sort sort-ic"></i></th>
                                    <th class="table-sortable hidden md:table-cell" onclick="sortTable(5)">Recorded At <i class="fa-solid fa-sort sort-ic"></i></th>
                                    <th>Status</th>
                                    <th class="no-print text-right" style="width:92px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="tableBody">
                                <?php if ($records && $records->num_rows > 0): ?>
                                    <?php while ($row = $records->fetch_assoc()): ?>
                                        <?php $ref = generateDocDistRef($row['id'], $row['date_distributed']); ?>
                                        <?php $status = $row['distribution_status'] ?? 'distributed'; ?>
                                        <tr>
                                            <td><span class="pill table-cell-mono"><?php echo htmlspecialchars($ref); ?></span></td>
                                            <td>
                                                <a href="list.php?search=<?php echo urlencode($row['document_name']); ?>"
                                                    class="table-cell-title" style="text-decoration:none;">
                                                    <?php echo htmlspecialchars($row['document_name']); ?>
                                                </a>
                                            </td>
                                            <td class="hidden md:table-cell">
                                                <?php if (!empty($row['document_type'])): ?>
                                                    <span class="badge badge-blue"><i class="fa-solid fa-tag"></i> <?php echo htmlspecialchars($row['document_type']); ?></span>
                                                <?php else: ?>
                                                    <span class="text-[#7d8398]">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="table-cell-mono"><?php echo (int)$row['number_distributed']; ?></span></td>
                                            <td><?php echo date('M j, Y', strtotime($row['date_distributed'])); ?></td>
                                            <td class="whitespace-nowrap hidden md:table-cell table-cell-subtitle">
                                                <?php echo formatTimestampDisplay($row['created_at']); ?>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $status === 'withdrawn' ? 'badge-red' : 'badge-green'; ?>">
                                                    <?php echo ucfirst($status); ?>
                                                </span>
                                            </td>
                                            <td class="no-print">
                                                <div class="row-actions" style="justify-content:flex-end;">
                                                    <button class="icon-btn primary" onclick="viewRecord(<?php echo $row['id']; ?>)" title="View details">
                                                        <i class="fa-regular fa-eye"></i>
                                                    </button>
                                                    <button class="icon-btn danger"
                                                        onclick="confirmDelete(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars(addslashes($row['document_name'])); ?>', <?php echo (int)$row['number_distributed']; ?>)"
                                                        title="Delete">
                                                        <i class="fa-regular fa-trash-can"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8">
                                            <div class="empty-state">
                                                <div class="empty-state-icon"><i class="fa-regular fa-folder-open"></i></div>
                                                <div class="empty-state-title">No distribution records found</div>
                                                <div class="empty-state-text">
                                                    <?php if ($has_active_filters): ?>
                                                        Try adjusting your search or filter criteria.
                                                    <?php else: ?>
                                                        Document distributions will appear here once created.
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($has_active_filters): ?>
                                                    <a href="documents_distribution_history.php" class="btn btn-soft btn-sm" style="margin-top:16px;">Clear filters</a>
                                                <?php else: ?>
                                                    <a href="distribution.php" class="btn btn-primary btn-sm" style="margin-top:16px;">
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
                        <div class="pagination-meta"><?php echo number_format($total_records); ?> total record(s)</div>
                        <div class="pagination-meta">Total copies distributed: <strong><?php echo number_format($summary['total_copies_distributed']); ?></strong></div>
                    </div>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination-shell mt-4 no-print">
                        <div class="pagination-meta">
                            <div class="pagination-title">Showing <?php echo min($limit, $total_records - ($page - 1) * $limit); ?> record(s) on this page</div>
                            <span>Records <?php echo ($page - 1) * $limit + 1; ?>–<?php echo min($page * $limit, $total_records); ?> of <?php echo number_format($total_records); ?> total</span>
                        </div>
                        <div class="pagination-controls">
                            <div class="pagination-page-indicator">Page <?php echo $page; ?> of <?php echo $total_pages; ?></div>
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                    <a href="<?php echo htmlspecialchars(buildDocDistUrl(['page' => 1])); ?>" class="pagination-item compact" aria-label="First page">
                                        <i class="fa-solid fa-chevrons-left"></i>
                                    </a>
                                    <a href="<?php echo htmlspecialchars(buildDocDistUrl(['page' => $page - 1])); ?>" class="pagination-item compact" aria-label="Previous page">
                                        <i class="fa-solid fa-chevron-left"></i>
                                    </a>
                                <?php endif; ?>

                                <?php
                                $start = max(1, $page - 2);
                                $end = min($total_pages, $page + 2);

                                if ($start > 1) {
                                    echo '<a href="' . htmlspecialchars(buildDocDistUrl(['page' => 1])) . '" class="pagination-item">1</a>';
                                    if ($start > 2) {
                                        echo '<span class="pagination-ellipsis">...</span>';
                                    }
                                }

                                for ($i = $start; $i <= $end; $i++) {
                                    $active_class = ($i == $page) ? 'active' : '';
                                    echo '<a href="' . htmlspecialchars(buildDocDistUrl(['page' => $i])) . '" class="pagination-item ' . $active_class . '">' . $i . '</a>';
                                }

                                if ($end < $total_pages) {
                                    if ($end < $total_pages - 1) {
                                        echo '<span class="pagination-ellipsis">...</span>';
                                    }
                                    echo '<a href="' . htmlspecialchars(buildDocDistUrl(['page' => $total_pages])) . '" class="pagination-item">' . $total_pages . '</a>';
                                }
                                ?>

                                <?php if ($page < $total_pages): ?>
                                    <a href="<?php echo htmlspecialchars(buildDocDistUrl(['page' => $page + 1])); ?>" class="pagination-item compact" aria-label="Next page">
                                        <i class="fa-solid fa-chevron-right"></i>
                                    </a>
                                    <a href="<?php echo htmlspecialchars(buildDocDistUrl(['page' => $total_pages])); ?>" class="pagination-item compact" aria-label="Last page">
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

    <!-- ── View Modal ── -->
    <div id="viewModal" class="modal-backdrop" style="display:none;">
        <div class="modal-dialog lg">
            <div class="modal-header">
                <h2 class="modal-title">Distribution Details</h2>
                <button type="button" onclick="MailroomModal.close('viewModal')" class="modal-close"><i class="fa-solid fa-xmark text-xl"></i></button>
            </div>
            <div class="modal-body">
                <div id="viewModalContent">
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

    <!-- ── Delete Confirmation Modal ── -->
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
                        <p class="font-medium text-sm" id="deleteDocName"></p>
                        <p class="text-xs mt-1" id="deleteDocCopies"></p>
                        <p class="text-xs mt-2" style="opacity:.85;">The distributed copies will be restored to the document's available stock. This action cannot be undone.</p>
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
        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            const d = document.createElement('div');
            d.appendChild(document.createTextNode(String(str)));
            return d.innerHTML;
        }
        // app.js provides a global esc() helper; do not redeclare it here.

        // ── View Record ────────────────────────────────────────────────────────
        function viewRecord(id) {
            MailroomModal.open('viewModal');
            document.getElementById('viewModalContent').innerHTML = `
                <div class="text-center py-10">
                    <i class="fa-solid fa-spinner fa-spin text-[#9aa0b5] text-2xl"></i>
                    <p class="text-[#7d8398] mt-3 text-sm">Loading...</p>
                </div>`;

            fetch(`documents_distribution_history.php?ajax=get_record&id=${id}`)
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        document.getElementById('viewModalContent').innerHTML =
                            `<div class="text-center py-10 text-[#7d8398]">
                                <i class="fa-regular fa-circle-exclamation text-red-400 text-3xl mb-3 block"></i>
                                <p>${esc(data.message || 'Record not found')}</p>
                            </div>`;
                        return;
                    }
                    const r = data.record;
                    const ref = `DDIST-${String(r.date_distributed).replace(/-/g, '').slice(0, 8)}-${String(r.id).padStart(4, '0')}`;
                    const distDate = r.date_distributed ?
                        new Date(r.date_distributed).toLocaleDateString('en-US', {
                            year: 'numeric',
                            month: 'long',
                            day: 'numeric'
                        }) : 'N/A';
                    const createdAt = r.created_at ?
                        new Date(r.created_at.replace(' ', 'T')).toLocaleDateString('en-US', {
                            year: 'numeric',
                            month: 'long',
                            day: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit'
                        }) : 'N/A';
                    const status = r.distribution_status === 'withdrawn' ? 'withdrawn' : 'distributed';
                    const statusClass = status === 'withdrawn' ? 'badge-red' : 'badge-green';

                    document.getElementById('viewModalContent').innerHTML = `
                        <div class="grid grid-cols-2 gap-3">
                            <div class="col-span-2">
                                <p style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:4px;">Reference</p>
                                <p><span class="pill table-cell-mono">${esc(ref)}</span></p>
                            </div>
                            <div class="col-span-2">
                                <p style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:4px;">Document</p>
                                <p class="text-sm font-medium" style="color:var(--text);">${esc(r.document_name)}</p>
                            </div>
                            <div>
                                <p style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:4px;">Document Type</p>
                                <p class="text-sm" style="color:var(--text-secondary);">${r.document_type ? `<span class="badge badge-blue"><i class="fa-solid fa-tag"></i> ${esc(r.document_type)}</span>` : '—'}</p>
                            </div>
                            <div>
                                <p style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:4px;">Origin</p>
                                <p class="text-sm" style="color:var(--text-secondary);">${esc(r.origin || '—')}</p>
                            </div>
                            <div>
                                <p style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:4px;">Copies Distributed</p>
                                <p class="text-sm font-mono" style="color:var(--text);">${Number(r.number_distributed) || 0}</p>
                            </div>
                            <div>
                                <p style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:4px;">Date Distributed</p>
                                <p class="text-sm" style="color:var(--text-secondary);">${esc(distDate)}</p>
                            </div>
                            <div>
                                <p style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:4px;">Recorded At</p>
                                <p class="text-sm" style="color:var(--text-secondary);">${esc(createdAt)}</p>
                            </div>
                            <div>
                                <p style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:4px;">Status</p>
                                <p><span class="badge ${statusClass}">${esc(status.charAt(0).toUpperCase() + status.slice(1))}</span></p>
                            </div>
                        </div>
                    `;
                })
                .catch(() => {
                    document.getElementById('viewModalContent').innerHTML =
                        `<div class="text-center py-10 text-[#7d8398]">
                            <i class="fa-regular fa-circle-exclamation text-red-400 text-3xl mb-3 block"></i>
                            <p>Error loading record details.</p>
                        </div>`;
                });
        }

        // ── Delete ─────────────────────────────────────────────────────────────
        function confirmDelete(id, docName, copies) {
            document.getElementById('deleteDocName').textContent = docName;
            document.getElementById('deleteDocCopies').textContent = copies + ' cop' + (copies === 1 ? 'y' : 'ies') + ' will be restored to the document.';
            document.getElementById('confirmDeleteBtn').onclick = function(e) {
                e.preventDefault();
                submitPostForm('documents_distribution_history.php', {
                    delete_record: id
                });
            };
            MailroomModal.open('deleteModal');
        }

        // ── Live search debounce ───────────────────────────────────────────────
        let searchTimer;
        document.getElementById('searchInput').addEventListener('input', function() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => document.getElementById('filterForm').submit(), 380);
        });

        // Auto-submit on type or status filter change
        document.getElementById('typeFilter').addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });
        document.getElementById('statusFilter').addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });

        // ── Client-side table sort ─────────────────────────────────────────────
        let sortDir = {};

        function sortTable(colIndex) {
            const tbody = document.getElementById('tableBody');
            const rows = Array.from(tbody.querySelectorAll('tr')).filter(r => r.cells.length > 1);
            if (!rows.length) return;

            sortDir[colIndex] = !sortDir[colIndex];
            const asc = sortDir[colIndex];

            rows.sort((a, b) => {
                const av = a.cells[colIndex]?.innerText.trim() || '';
                const bv = b.cells[colIndex]?.innerText.trim() || '';
                const an = parseFloat(av.replace(/[^0-9.-]/g, ''));
                const bn = parseFloat(bv.replace(/[^0-9.-]/g, ''));
                if (!isNaN(an) && !isNaN(bn)) return asc ? an - bn : bn - an;
                return asc ? av.localeCompare(bv) : bv.localeCompare(av);
            });

            rows.forEach(r => tbody.appendChild(r));

            document.querySelectorAll('#historyTable thead th .sort-ic').forEach(ic => {
                ic.className = 'fa-solid fa-sort sort-ic';
            });
            const ths = document.querySelectorAll('#historyTable thead th');
            if (ths[colIndex]) {
                const icon = ths[colIndex].querySelector('.sort-ic');
                if (icon) icon.className = `fa-solid fa-sort-${asc ? 'up' : 'down'} sort-ic`;
            }
        }
    </script>
    <script src="assets/app.js"></script>
</body>

</html>