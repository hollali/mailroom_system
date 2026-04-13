<?php
// documents_distribution_history.php - View all document distribution records
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once './config/db.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// ─── Helper Functions ────────────────────────────────────────────────────────

function generateDocDistRef($id, $date_distributed = null)
{
    $date_part = date('Ymd', strtotime($date_distributed ?: 'now'));
    return 'DDIST-' . $date_part . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}

function formatTimestampDisplay($value)
{
    if (empty($value)) return 'N/A';
    $ts = strtotime($value);
    return $ts === false ? htmlspecialchars($value) : date('M j, Y g:i A', $ts);
}

// ─── AJAX: Get single record for View modal ───────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_record' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $id = (int)$_GET['id'];

    $stmt = $conn->prepare("
        SELECT dd.*, d.document_name, d.origin, dt.type_name AS document_type
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
if (isset($_GET['delete_record'])) {
    $id = (int)$_GET['delete_record'];

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

        /* ── Table ── */
        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 11px 16px;
            background: #fafaf9;
            border-bottom: 1px solid #e5e5e5;
            font-weight: 500;
            font-size: 12px;
            color: #57534e;
            text-transform: uppercase;
            letter-spacing: .04em;
            white-space: nowrap;
            cursor: pointer;
            user-select: none;
        }

        th:hover {
            background: #f4f4f3;
        }

        th .sort-icon {
            color: #d6d3d1;
            margin-left: 4px;
            font-size: 10px;
        }

        td {
            padding: 12px 16px;
            border-bottom: 1px solid #e5e5e5;
            font-size: 14px;
            color: #1c1917;
            vertical-align: middle;
        }

        tr:hover td {
            background: #fafaf9;
        }

        /* ── Badges ── */
        .badge {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
        }

        .badge-type {
            background: #e3f2fd;
            color: #0b5e8a;
        }

        .badge-count {
            background: #f0fdf4;
            color: #166534;
            font-family: monospace;
            font-size: 13px;
        }

        /* ── Action buttons ── */
        .action-btn {
            color: #a8a29e;
            background: none;
            border: none;
            cursor: pointer;
            padding: 5px 7px;
            font-size: 14px;
            border-radius: 4px;
            transition: all .15s;
        }

        .action-btn:hover {
            color: #1c1917;
            background: #f5f5f4;
        }

        .delete-btn:hover {
            color: #dc2626;
            background: #fef2f2;
        }

        /* ── Stat cards ── */
        .stat-card {
            background: white;
            border: 1px solid #e7e5e4;
            border-radius: 8px;
            padding: 16px 20px;
            transition: all .2s;
        }

        .stat-card:hover {
            border-color: #a8a29e;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .06);
        }

        .stat-number {
            font-size: 28px;
            font-weight: 600;
            color: #1c1917;
            line-height: 1;
        }

        .stat-label {
            font-size: 12px;
            color: #78716c;
            margin-top: 4px;
        }

        .stat-icon {
            font-size: 20px;
            color: #d6d3d1;
        }

        /* ── Filters ── */
        .filter-input {
            padding: 7px 10px;
            border: 1px solid #e5e5e5;
            border-radius: 6px;
            font-size: 13px;
            background: white;
            outline: none;
            transition: border-color .15s;
        }

        .filter-input:focus {
            border-color: #a8a29e;
        }

        .btn-primary {
            background: #1c1917;
            color: white;
            padding: 7px 14px;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            transition: background .15s;
        }

        .btn-primary:hover {
            background: #292524;
        }

        .btn-secondary {
            background: white;
            color: #1c1917;
            padding: 7px 14px;
            border: 1px solid #e5e5e5;
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            transition: background .15s;
        }

        .btn-secondary:hover {
            background: #fafaf9;
        }

        .btn-danger {
            background: #dc2626;
            color: white;
            padding: 7px 14px;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            transition: background .15s;
        }

        .btn-danger:hover {
            background: #b91c1c;
        }

        /* ── Modal ── */
        .modal {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .35);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 16px;
        }

        .modal-content {
            background: white;
            border-radius: 10px;
            width: 100%;
            max-width: 540px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .15);
            overflow: hidden;
        }

        .modal-header {
            padding: 18px 22px;
            border-bottom: 1px solid #e5e5e5;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 15px;
            font-weight: 600;
        }

        .modal-body {
            padding: 20px 22px;
        }

        .modal-footer {
            padding: 14px 22px;
            border-top: 1px solid #e5e5e5;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        .detail-row {
            display: flex;
            padding: 9px 0;
            border-bottom: 1px solid #f0f0f0;
            align-items: baseline;
            gap: 12px;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            width: 150px;
            min-width: 150px;
            font-size: 12px;
            font-weight: 500;
            color: #78716c;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .detail-value {
            flex: 1;
            font-size: 14px;
            color: #1c1917;
        }

        /* ── Pagination ── */
        .pagination {
            display: flex;
            gap: 4px;
            align-items: center;
            flex-wrap: wrap;
        }

        .page-link {
            padding: 6px 12px;
            border: 1px solid #e5e5e5;
            border-radius: 6px;
            background: white;
            font-size: 13px;
            text-decoration: none;
            color: #1c1917;
            transition: all .15s;
        }

        .page-link:hover {
            background: #fafaf9;
            border-color: #d6d3d1;
        }

        .page-link.active {
            background: #1c1917;
            color: white;
            border-color: #1c1917;
        }

        .page-link.disabled {
            opacity: .45;
            pointer-events: none;
        }

        /* ── Toast ── */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 2000;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .toast {
            background: white;
            border: 1px solid #e5e5e5;
            border-radius: 8px;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 280px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, .1);
            animation: slideIn .25s ease;
        }

        .toast-success {
            border-left: 3px solid #10b981;
        }

        .toast-error {
            border-left: 3px solid #ef4444;
        }

        .toast-warning {
            border-left: 3px solid #f59e0b;
        }

        @keyframes slideIn {
            from {
                transform: translateX(40px);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        /* ── Ref badge ── */
        .ref-badge {
            font-family: monospace;
            font-size: 12px;
            background: #f5f5f4;
            border: 1px solid #e5e5e5;
            border-radius: 4px;
            padding: 2px 8px;
            color: #57534e;
            white-space: nowrap;
        }

        /* ── Empty state ── */
        .empty-state {
            text-align: center;
            padding: 56px 24px;
            color: #78716c;
        }

        .empty-state i {
            font-size: 40px;
            color: #d6d3d1;
            margin-bottom: 12px;
            display: block;
        }

        .empty-state p {
            font-size: 15px;
        }

        /* ── Print ── */
        @media print {

            #toastContainer,
            #sidebar,
            #mobileMenuBtn,
            #sidebarOverlay,
            .modal,
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
    <?php include './sidebar.php'; ?>

    <div id="toastContainer" class="toast-container"></div>

    <main class="ml-60 min-h-screen">
        <div class="px-8 py-6 border-b border-[#e5e5e5] bg-white flex justify-between items-center">
            <div>
                <h1 class="text-2xl font-medium text-[#1e1e1e]">Document Distribution History</h1>
                <p class="text-sm text-[#6e6e6e] mt-1">View and manage all document distribution records</p>
            </div>
            <div class="no-print flex gap-2">
                <a href="distribution.php" class="btn-primary">
                    <i class="fa-regular fa-plus"></i> New Distribution
                </a>
                <button onclick="window.print()" class="btn-secondary">
                    <i class="fa-solid fa-print"></i> Print
                </button>
            </div>
        </div>

        <div class="p-8">

            <!-- ── Summary Cards ── -->
            <div class="grid grid-cols-3 gap-4 mb-6 no-print">
                <div class="stat-card flex items-center justify-between">
                    <div>
                        <div class="stat-number"><?php echo number_format($summary['total_distributions']); ?></div>
                        <div class="stat-label">Total Distributions</div>
                    </div>
                    <i class="fa-solid fa-file-export stat-icon"></i>
                </div>
                <div class="stat-card flex items-center justify-between">
                    <div>
                        <div class="stat-number"><?php echo number_format($summary['total_copies_distributed']); ?></div>
                        <div class="stat-label">Copies Distributed</div>
                    </div>
                    <i class="fa-solid fa-copy stat-icon"></i>
                </div>
                <div class="stat-card flex items-center justify-between">
                    <div>
                        <div class="stat-number"><?php echo number_format($summary['unique_documents']); ?></div>
                        <div class="stat-label">Unique Documents</div>
                    </div>
                    <i class="fa-regular fa-file-lines stat-icon"></i>
                </div>
            </div>

            <!-- ── Filter Bar ── -->
            <div class="bg-white border border-[#e5e5e5] rounded-lg mb-4 no-print">
                <form method="GET" id="filterForm" class="p-4 flex flex-wrap gap-3 items-end">
                    <div class="flex-1 min-w-[200px]">
                        <label class="block text-xs text-[#6e6e6e] mb-1 font-medium">Search</label>
                        <div class="relative">
                            <i class="fa-solid fa-magnifying-glass absolute left-3 top-2.5 text-xs text-[#9e9e9e]"></i>
                            <input type="text" id="searchInput" name="search"
                                class="filter-input w-full pl-8"
                                autocomplete="off"
                                placeholder="Document name, reference, type, origin…"
                                value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                    </div>
                    <div class="w-[180px]">
                        <label class="block text-xs text-[#6e6e6e] mb-1 font-medium">Document Type</label>
                        <select id="typeFilter" name="type" class="filter-input w-full">
                            <option value="">All Types</option>
                            <?php foreach ($doc_types as $dt): ?>
                                <option value="<?php echo htmlspecialchars($dt); ?>"
                                    <?php echo $type_filter === $dt ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dt); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="w-[145px]">
                        <label class="block text-xs text-[#6e6e6e] mb-1 font-medium">Status</label>
                        <select id="statusFilter" name="status" class="filter-input w-full">
                            <option value="">All Statuses</option>
                            <option value="distributed" <?php echo $status_filter === 'distributed' ? 'selected' : ''; ?>>Distributed</option>
                            <option value="withdrawn" <?php echo $status_filter === 'withdrawn' ? 'selected' : ''; ?>>Withdrawn</option>
                        </select>
                    </div>
                    <div class="w-[145px]">
                        <label class="block text-xs text-[#6e6e6e] mb-1 font-medium">From Date</label>
                        <input type="date" name="date_from" class="filter-input w-full"
                            value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    <div class="w-[145px]">
                        <label class="block text-xs text-[#6e6e6e] mb-1 font-medium">To Date</label>
                        <input type="date" name="date_to" class="filter-input w-full"
                            value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="btn-primary">
                            <i class="fa-solid fa-filter"></i> Filter
                        </button>
                        <a href="documents_distribution_history.php" class="btn-secondary">
                            <i class="fa-solid fa-rotate-left"></i> Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- ── Records Table ── -->
            <div class="bg-white border border-[#e5e5e5] rounded-lg overflow-hidden">
                <div class="px-5 py-3 border-b border-[#e5e5e5] bg-[#fafaf9] flex justify-between items-center">
                    <h2 class="text-sm font-medium text-[#1c1917]">Distribution Records</h2>
                    <span class="text-xs text-[#78716c]">
                        <?php if ($total_records > 0): ?>
                            Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $limit, $total_records); ?> of <?php echo number_format($total_records); ?>
                        <?php else: ?>
                            No records
                        <?php endif; ?>
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table id="historyTable">
                        <thead>
                            <tr>
                                <th onclick="sortTable(0)">Reference <i class="fa-solid fa-sort sort-icon"></i></th>
                                <th onclick="sortTable(1)">Document <i class="fa-solid fa-sort sort-icon"></i></th>
                                <th onclick="sortTable(2)">Type <i class="fa-solid fa-sort sort-icon"></i></th>
                                <th onclick="sortTable(3)">Copies Distributed <i class="fa-solid fa-sort sort-icon"></i></th>
                                <th onclick="sortTable(4)">Date <i class="fa-solid fa-sort sort-icon"></i></th>
                                <th onclick="sortTable(5)">Recorded At <i class="fa-solid fa-sort sort-icon"></i></th>
                                <th>Status</th>
                                <th class="no-print"></th>
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                            <?php if ($records && $records->num_rows > 0): ?>
                                <?php while ($row = $records->fetch_assoc()): ?>
                                    <?php $ref = generateDocDistRef($row['id'], $row['date_distributed']); ?>
                                    <tr>
                                        <td><span class="ref-badge"><?php echo htmlspecialchars($ref); ?></span></td>
                                        <td class="font-medium">
                                            <a href="list.php?search=<?php echo urlencode($row['document_name']); ?>"
                                                class="hover:underline text-[#1c1917]">
                                                <?php echo htmlspecialchars($row['document_name']); ?>
                                            </a>
                                        </td>
                                        <td>
                                            <?php if (!empty($row['document_type'])): ?>
                                                <span class="badge badge-type">
                                                    <i class="fa-solid fa-tag mr-1" style="font-size:10px"></i>
                                                    <?php echo htmlspecialchars($row['document_type']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-[#a8a29e]">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-count"><?php echo (int)$row['number_distributed']; ?></span>
                                        </td>
                                        <td><?php echo date('M j, Y', strtotime($row['date_distributed'])); ?></td>
                                        <td class="text-[#78716c] text-sm whitespace-nowrap">
                                            <?php echo formatTimestampDisplay($row['created_at']); ?>
                                        </td>
                                        <td>
                                            <?php $status = $row['distribution_status'] ?? 'distributed'; ?>
                                            <span class="badge <?php echo $status === 'withdrawn' ? 'badge-danger' : 'badge-count'; ?>">
                                                <?php echo ucfirst($status); ?>
                                            </span>
                                        </td>
                                        <td class="whitespace-nowrap no-print">
                                            <button onclick="viewRecord(<?php echo $row['id']; ?>)"
                                                class="action-btn" title="View details">
                                                <i class="fa-regular fa-eye"></i>
                                            </button>
                                            <button onclick="confirmDelete(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars(addslashes($row['document_name'])); ?>', <?php echo (int)$row['number_distributed']; ?>)"
                                                class="action-btn delete-btn" title="Delete">
                                                <i class="fa-regular fa-trash-can"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8">
                                        <div class="empty-state">
                                            <i class="fa-regular fa-folder-open"></i>
                                            <p>No distribution records found.</p>
                                            <?php if (!empty($search) || !empty($type_filter) || !empty($status_filter) || !empty($date_from) || !empty($date_to)): ?>
                                                <a href="documents_distribution_history.php"
                                                    class="text-sm text-[#1c1917] underline mt-2 inline-block">
                                                    Clear filters
                                                </a>
                                            <?php else: ?>
                                                <a href="distribution.php"
                                                    class="text-sm text-[#1c1917] underline mt-2 inline-block">
                                                    Start distributing documents
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
                <div class="px-5 py-3 border-t border-[#e5e5e5] bg-[#fafaf9] flex justify-between items-center text-xs text-[#78716c]">
                    <span><?php echo number_format($total_records); ?> total record(s)</span>
                    <span>Total copies distributed: <strong><?php echo number_format($summary['total_copies_distributed']); ?></strong></span>
                </div>
            </div>

            <!-- ── Pagination ── -->
            <?php if ($total_pages > 1): ?>
                <?php
                $qs_base = http_build_query(array_filter([
                    'search'    => $search,
                    'type'      => $type_filter,
                    'status'    => $status_filter,
                    'date_from' => $date_from,
                    'date_to'   => $date_to,
                ]));
                $qs = $qs_base ? "&$qs_base" : '';
                ?>
                <div class="mt-4 flex justify-between items-center">
                    <div class="text-sm text-[#78716c]">
                        Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                    </div>
                    <div class="pagination">
                        <a href="?page=1<?php echo $qs; ?>" class="page-link <?php echo $page == 1 ? 'disabled' : ''; ?>">
                            <i class="fa-solid fa-angles-left text-xs"></i>
                        </a>
                        <a href="?page=<?php echo max(1, $page - 1); ?><?php echo $qs; ?>" class="page-link <?php echo $page == 1 ? 'disabled' : ''; ?>">
                            <i class="fa-solid fa-angle-left text-xs"></i>
                        </a>

                        <?php
                        $start = max(1, $page - 2);
                        $end   = min($total_pages, $page + 2);
                        if ($start > 1) echo '<span class="page-link" style="pointer-events:none;border:none;background:none">…</span>';
                        for ($i = $start; $i <= $end; $i++):
                        ?>
                            <a href="?page=<?php echo $i; ?><?php echo $qs; ?>"
                                class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor;
                        if ($end < $total_pages) echo '<span class="page-link" style="pointer-events:none;border:none;background:none">…</span>';
                        ?>

                        <a href="?page=<?php echo min($total_pages, $page + 1); ?><?php echo $qs; ?>" class="page-link <?php echo $page == $total_pages ? 'disabled' : ''; ?>">
                            <i class="fa-solid fa-angle-right text-xs"></i>
                        </a>
                        <a href="?page=<?php echo $total_pages; ?><?php echo $qs; ?>" class="page-link <?php echo $page == $total_pages ? 'disabled' : ''; ?>">
                            <i class="fa-solid fa-angles-right text-xs"></i>
                        </a>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </main>

    <!-- ── View Modal ── -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Distribution Details</h3>
                <button onclick="closeModal('viewModal')" class="text-[#a8a29e] hover:text-[#1c1917]">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>
            <div class="modal-body" id="viewModalBody">
                <div class="text-center py-10">
                    <i class="fa-solid fa-spinner fa-spin text-[#a8a29e] text-2xl"></i>
                    <p class="text-[#78716c] mt-3 text-sm">Loading…</p>
                </div>
            </div>
            <div class="modal-footer">
                <button onclick="closeModal('viewModal')" class="btn-secondary">Close</button>
            </div>
        </div>
    </div>

    <!-- ── Delete Confirmation Modal ── -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Confirm Delete</h3>
                <button onclick="closeModal('deleteModal')" class="text-[#a8a29e] hover:text-[#1c1917]">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>
            <div class="modal-body">
                <p class="text-[#44403c] mb-4">Are you sure you want to delete this distribution record?</p>
                <div class="bg-red-50 border border-red-200 rounded-md p-4">
                    <p class="font-medium text-red-800" id="deleteDocName"></p>
                    <p class="text-sm text-red-600 mt-1" id="deleteDocCopies"></p>
                    <p class="text-xs text-red-500 mt-2">
                        <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                        The distributed copies will be restored to the document's available stock.
                        This action cannot be undone.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button onclick="closeModal('deleteModal')" class="btn-secondary">Cancel</button>
                <a href="#" id="confirmDeleteBtn" class="btn-danger">
                    <i class="fa-regular fa-trash-can mr-1"></i> Delete Record
                </a>
            </div>
        </div>
    </div>

    <script>
        // ── Toast ──────────────────────────────────────────────────────────────
        function showToast(type, message) {
            const container = document.getElementById('toastContainer');
            const icons = {
                success: 'fa-circle-check',
                error: 'fa-circle-xmark',
                warning: 'fa-triangle-exclamation'
            };
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.innerHTML = `
                <i class="fa-regular ${icons[type] || icons.success} text-${type === 'success' ? 'green' : type === 'error' ? 'red' : 'yellow'}-500"></i>
                <span class="flex-1 text-sm">${escapeHtml(message)}</span>
                <button onclick="this.parentElement.remove()" class="text-[#a8a29e] hover:text-[#1c1917] text-lg leading-none">&times;</button>
            `;
            container.appendChild(toast);
            setTimeout(() => toast.remove(), 5000);
        }

        function escapeHtml(str) {
            const d = document.createElement('div');
            d.appendChild(document.createTextNode(str));
            return d.innerHTML;
        }

        <?php if ($toast): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showToast('<?php echo $toast['type']; ?>', '<?php echo addslashes($toast['message']); ?>');
            });
        <?php endif; ?>

        // ── Modal helpers ──────────────────────────────────────────────────────
        function openModal(id) {
            document.getElementById(id).style.display = 'flex';
        }

        function closeModal(id) {
            document.getElementById(id).style.display = 'none';
        }

        // Close on backdrop click
        document.querySelectorAll('.modal').forEach(m => {
            m.addEventListener('click', e => {
                if (e.target === m) closeModal(m.id);
            });
        });

        // ── View Record ────────────────────────────────────────────────────────
        function viewRecord(id) {
            openModal('viewModal');
            document.getElementById('viewModalBody').innerHTML = `
                <div class="text-center py-10">
                    <i class="fa-solid fa-spinner fa-spin text-[#a8a29e] text-2xl"></i>
                    <p class="text-[#78716c] mt-3 text-sm">Loading…</p>
                </div>`;

            fetch(`documents_distribution_history.php?ajax=get_record&id=${id}`)
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        document.getElementById('viewModalBody').innerHTML =
                            `<div class="text-center py-10 text-[#78716c]">
                                <i class="fa-regular fa-circle-exclamation text-red-400 text-3xl mb-3 block"></i>
                                <p>${escapeHtml(data.message || 'Record not found')}</p>
                            </div>`;
                        return;
                    }
                    const r = data.record;
                    const ref = `DDIST-${r.date_distributed.replace(/-/g,'').slice(0,8)}-${String(r.id).padStart(4,'0')}`;
                    const distDate = new Date(r.date_distributed).toLocaleDateString('en-US', {
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric'
                    });
                    const createdAt = r.created_at ?
                        new Date(r.created_at).toLocaleDateString('en-US', {
                            year: 'numeric',
                            month: 'long',
                            day: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit'
                        }) :
                        'N/A';

                    document.getElementById('viewModalBody').innerHTML = `
                        <div class="detail-row"><div class="detail-label">Reference</div><div class="detail-value"><span class="ref-badge">${escapeHtml(ref)}</span></div></div>
                        <div class="detail-row"><div class="detail-label">Document</div><div class="detail-value font-medium">${escapeHtml(r.document_name)}</div></div>
                        <div class="detail-row"><div class="detail-label">Type</div><div class="detail-value">${r.document_type ? `<span class="badge badge-type"><i class="fa-solid fa-tag mr-1" style="font-size:9px"></i>${escapeHtml(r.document_type)}</span>` : '—'}</div></div>
                        <div class="detail-row"><div class="detail-label">Origin</div><div class="detail-value">${escapeHtml(r.origin || '—')}</div></div>
                        <div class="detail-row"><div class="detail-label">Copies Distributed</div><div class="detail-value"><span class="badge badge-count">${r.number_distributed}</span></div></div>
                        <div class="detail-row"><div class="detail-label">Date Distributed</div><div class="detail-value">${distDate}</div></div>
                        <div class="detail-row"><div class="detail-label">Recorded At</div><div class="detail-value text-[#78716c] text-sm">${createdAt}</div></div>
                    `;
                })
                .catch(() => {
                    document.getElementById('viewModalBody').innerHTML =
                        `<div class="text-center py-10 text-[#78716c]">
                            <i class="fa-regular fa-circle-exclamation text-red-400 text-3xl mb-3 block"></i>
                            <p>Error loading record details.</p>
                        </div>`;
                });
        }

        // ── Delete ─────────────────────────────────────────────────────────────
        let deleteId = null;

        function confirmDelete(id, docName, copies) {
            deleteId = id;
            document.getElementById('deleteDocName').textContent = docName;
            document.getElementById('deleteDocCopies').textContent = copies + ' cop' + (copies === 1 ? 'y' : 'ies') + ' will be restored to the document.';
            document.getElementById('confirmDeleteBtn').href = `documents_distribution_history.php?delete_record=${id}`;
            openModal('deleteModal');
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

            // Update sort icons
            document.querySelectorAll('th .sort-icon').forEach(ic => {
                ic.className = 'fa-solid fa-sort sort-icon';
            });
            const ths = document.querySelectorAll('#historyTable thead th');
            if (ths[colIndex]) {
                const icon = ths[colIndex].querySelector('.sort-icon');
                if (icon) icon.className = `fa-solid fa-sort-${asc ? 'up' : 'down'} sort-icon`;
            }
        }
    </script>
</body>

</html>