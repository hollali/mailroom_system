<?php
// audit_trail.php - Audit trail viewer
require_once './config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/audit.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$action_meta = [
    'create'      => ['label' => 'Created',       'badge' => 'badge-green',  'icon' => 'fa-solid fa-plus'],
    'update'      => ['label' => 'Updated',       'badge' => 'badge-blue',   'icon' => 'fa-regular fa-pen-to-square'],
    'delete'      => ['label' => 'Deleted',       'badge' => 'badge-red',    'icon' => 'fa-regular fa-trash-can'],
    'distribute'  => ['label' => 'Distributed',   'badge' => 'badge-orange', 'icon' => 'fa-solid fa-share-from-square'],
    'pickup'      => ['label' => 'Picked up',     'badge' => 'badge-blue',   'icon' => 'fa-solid fa-box'],
    'activate'    => ['label' => 'Activated',     'badge' => 'badge-green',  'icon' => 'fa-solid fa-circle-check'],
    'deactivate'  => ['label' => 'Deactivated',   'badge' => 'badge-orange', 'icon' => 'fa-solid fa-circle-minus'],
    'restock'     => ['label' => 'Restocked',     'badge' => 'badge-blue',   'icon' => 'fa-solid fa-boxes-stacked'],
    'status'      => ['label' => 'Status change', 'badge' => 'badge-blue',   'icon' => 'fa-solid fa-arrows-rotate'],
    'restore'     => ['label' => 'Restored',      'badge' => 'badge-green',  'icon' => 'fa-solid fa-rotate-left'],
    'withdraw'    => ['label' => 'Withdrawn',     'badge' => 'badge-red',    'icon' => 'fa-solid fa-rotate-left'],
    'backup'      => ['label' => 'Backup',        'badge' => 'badge-gray',   'icon' => 'fa-solid fa-download'],
    'restore_db'  => ['label' => 'DB restored',   'badge' => 'badge-gray',   'icon' => 'fa-solid fa-upload'],
    'clear'       => ['label' => 'Log cleared',   'badge' => 'badge-red',    'icon' => 'fa-solid fa-eraser'],
];

$module_icons = [
    'documents'             => ['label' => 'Documents',            'icon' => 'fa-regular fa-file-lines',      'color' => 'blue'],
    'document_distribution' => ['label' => 'Document distribution', 'icon' => 'fa-solid fa-share-from-square', 'color' => 'orange'],
    'parcels'               => ['label' => 'Parcels',              'icon' => 'fa-solid fa-box',               'color' => 'green'],
    'recipients'            => ['label' => 'Recipients',           'icon' => 'fa-solid fa-user',              'color' => 'blue'],
    'document_types'        => ['label' => 'Document types',       'icon' => 'fa-solid fa-tags',              'color' => 'gray'],
    'newspapers'            => ['label' => 'Newspapers',           'icon' => 'fa-regular fa-newspaper',       'color' => 'green'],
    'newspaper_categories'  => ['label' => 'Newspaper categories', 'icon' => 'fa-solid fa-layer-group',       'color' => 'gray'],
    'distribution'          => ['label' => 'Distribution',         'icon' => 'fa-solid fa-paper-plane',       'color' => 'blue'],
    'settings'              => ['label' => 'Settings',             'icon' => 'fa-solid fa-gear',              'color' => 'gray'],
    'audit'                 => ['label' => 'Audit trail',          'icon' => 'fa-solid fa-clipboard-list',    'color' => 'red'],
];

function auditMeta(array $map, $key)
{
    return $map[$key] ?? ['label' => null, 'icon' => 'fa-solid fa-circle-info', 'color' => 'gray'];
}

function friendlyModuleLabel($moduleKey)
{
    $labels = [
        'document_distribution' => 'Document distribution',
        'newspaper_categories'  => 'Newspaper categories',
        'document_types'        => 'Document types',
        'restore_db'            => 'Database restore',
    ];
    return $labels[$moduleKey] ?? ucwords(str_replace('_', ' ', $moduleKey));
}

// ─── Clear audit history ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_audit'])) {
    csrf_check_post();
    $conn->query("DELETE FROM audit_logs");
    audit_log($conn, 'audit', 'clear', null, 'Audit Trail', 'All audit log entries were cleared.');
    $_SESSION['toast'] = ['type' => 'success', 'message' => 'Audit trail cleared.'];
    header('Location: audit_trail.php');
    exit();
}

// ─── Filters ─────────────────────────────────────────────────────────────────
$filter_module    = trim($_GET['module'] ?? '');
$filter_action    = trim($_GET['action'] ?? '');
$filter_q         = trim($_GET['q'] ?? '');
$filter_date_from = trim($_GET['date_from'] ?? '');
$filter_date_to   = trim($_GET['date_to'] ?? '');
$page             = max(1, (int)($_GET['page'] ?? 1));
$per_page         = 50;

$where  = [];
$params = [];
$types  = '';

if ($filter_module !== '')    { $where[] = 'module = ?';                     $params[] = $filter_module;        $types .= 's'; }
if ($filter_action !== '')    { $where[] = 'action_type = ?';                $params[] = $filter_action;        $types .= 's'; }
if ($filter_q !== '') {
    $where[] = '(entity_name LIKE ? OR description LIKE ? OR user LIKE ?)';
    $like = '%' . $filter_q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'sss';
}
if ($filter_date_from !== '') { $where[] = 'DATE(created_at) >= ?';          $params[] = $filter_date_from;     $types .= 's'; }
if ($filter_date_to !== '')   { $where[] = 'DATE(created_at) <= ?';          $params[] = $filter_date_to;       $types .= 's'; }

$where_sql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

// ─── Paginated rows ──────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM audit_logs" . $where_sql);
if ($stmt) {
    if ($types) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $total_rows = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
} else {
    $total_rows = 0;
}

$total_pages = max(1, (int)ceil($total_rows / $per_page));
if ($page > $total_pages) {
    $page = $total_pages;
}
$offset = ($page - 1) * $per_page;

$stmt = $conn->prepare("SELECT * FROM audit_logs" . $where_sql . " ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?");
$audit_entries = [];
if ($stmt) {
    $stmt_values = array_merge($params, [$per_page, $offset]);
    $stmt->bind_param($types . 'ii', ...$stmt_values);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $audit_entries[] = $row;
    }
    $stmt->close();
}

// ─── Dropdown option lists ───────────────────────────────────────────────────
$modules_list = [];
$res = $conn->query("SELECT DISTINCT module FROM audit_logs ORDER BY module");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $modules_list[] = $row['module'];
    }
}
$actions_list = [];
$res = $conn->query("SELECT DISTINCT action_type FROM audit_logs ORDER BY action_type");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $actions_list[] = $row['action_type'];
    }
}

function buildAuditUrl(array $overrides = [])
{
    $base = [];
    foreach (['module', 'action', 'q', 'date_from', 'date_to'] as $key) {
        if (isset($_GET[$key]) && trim($_GET[$key]) !== '') {
            $base[$key] = trim($_GET[$key]);
        }
    }
    foreach ($overrides as $key => $val) {
        if ($val === '' || $val === null) {
            unset($base[$key]);
        } else {
            $base[$key] = $val;
        }
    }
    return 'audit_trail.php?' . http_build_query($base);
}

// ─── Stats ───────────────────────────────────────────────────────────────────
$stats_total   = (int)$conn->query("SELECT COUNT(*) AS c FROM audit_logs")->fetch_assoc()['c'];
$stats_today   = (int)$conn->query("SELECT COUNT(*) AS c FROM audit_logs WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['c'];
$stats_users   = (int)$conn->query("SELECT COUNT(DISTINCT user) AS c FROM audit_logs WHERE user IS NOT NULL AND user <> ''")->fetch_assoc()['c'];
$stats_modules = (int)$conn->query("SELECT COUNT(DISTINCT module) AS c FROM audit_logs")->fetch_assoc()['c'];

// Get toast message from session
$toast = $_SESSION['toast'] ?? null;
unset($_SESSION['toast']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Trail — Mailroom Operations</title>
    <link rel="icon" type="image/png" href="./images/logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/app.css">
    <meta name="csrf-token" content="<?php echo csrf_token(); ?>">
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
                        <span>Audit Trail</span>
                    </div>
                    <h1 class="page-header-title">Audit Trail</h1>
                    <p class="page-header-subtitle">Record of every action performed in the system.</p>
                </div>
                <?php if ($stats_total > 0): ?>
                    <div class="header-actions flex items-center gap-2 print-hide">
                        <button type="button" class="btn btn-danger" onclick="MailroomModal.open('clearAuditModal')">
                            <i class="fa-regular fa-trash-can"></i>
                            <span class="hidden sm:inline">Clear Log</span>
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <div class="page-body">
                <?php if ($toast): ?>
                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            MailroomToast.show(<?php echo json_encode($toast['message']); ?>, <?php echo json_encode($toast['type']); ?>);
                        });
                    </script>
                <?php endif; ?>

                <!-- Stats -->
                <div class="stat-grid mb-6">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fa-solid fa-clipboard-list"></i></div>
                        <div class="stat-label">Total Events</div>
                        <div class="stat-value"><?php echo number_format($stats_total); ?></div>
                        <div class="stat-hint">Append-only history</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fa-solid fa-calendar-day"></i></div>
                        <div class="stat-label">Today</div>
                        <div class="stat-value"><?php echo number_format($stats_today); ?></div>
                        <div class="stat-hint">Events recorded today</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fa-solid fa-user"></i></div>
                        <div class="stat-label">Operators</div>
                        <div class="stat-value"><?php echo number_format($stats_users); ?></div>
                        <div class="stat-hint">Distinct users logged</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon gray"><i class="fa-solid fa-cubes"></i></div>
                        <div class="stat-label">Modules</div>
                        <div class="stat-value"><?php echo number_format($stats_modules); ?></div>
                        <div class="stat-hint">Areas trackable</div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card mb-4 print-hide">
                    <div class="card-header">
                        <div>
                            <div class="card-title">Filters</div>
                            <div class="card-subtitle">Narrow the log by module, action, keyword, or date range.</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="get" action="audit_trail.php" class="filter-bar" style="margin-bottom:0;">
                            <input type="hidden" name="page" value="1">
                            <select name="module" class="select">
                                <option value="">All modules</option>
                                <?php foreach ($modules_list as $m): ?>
                                    <option value="<?php echo htmlspecialchars($m); ?>" <?php echo $filter_module === $m ? 'selected' : ''; ?>><?php echo htmlspecialchars(friendlyModuleLabel($m)); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="action" class="select">
                                <option value="">All actions</option>
                                <?php foreach ($actions_list as $a): ?>
                                    <option value="<?php echo htmlspecialchars($a); ?>" <?php echo $filter_action === $a ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $a))); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="search-wrap">
                                <i class="fa-solid fa-magnifying-glass icon"></i>
                                <input type="search" name="q" value="<?php echo htmlspecialchars($filter_q); ?>" class="input" placeholder="Search record, description, user..." autocomplete="off">
                            </div>
                            <input type="date" name="date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>" class="input" title="From date">
                            <span class="filter-sep">to</span>
                            <input type="date" name="date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>" class="input" title="To date">
                            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i> Filter</button>
                            <a href="audit_trail.php" class="btn btn-soft <?php echo (!$filter_module && !$filter_action && !$filter_q && !$filter_date_from && !$filter_date_to) ? 'hidden' : ''; ?>">Reset</a>
                        </form>
                    </div>
                </div>

                <!-- Table -->
                <div class="card">
                    <div class="card-header">
                        <div>
                            <div class="card-title">Audit Log</div>
                            <div class="card-subtitle">Newest first — expand "View change details" to inspect field-level edits.</div>
                        </div>
                        <?php if ($total_rows > 0): ?>
                            <span class="pill" style="font-size:12px;"><?php echo number_format($total_rows); ?> total</span>
                        <?php endif; ?>
                    </div>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th class="whitespace-nowrap">Timestamp</th>
                                    <th>Module</th>
                                    <th>Action</th>
                                    <th>Details</th>
                                    <th>Operator</th>
                                    <th class="hidden lg:table-cell">IP</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($audit_entries) > 0): ?>
                                    <?php foreach ($audit_entries as $entry): ?>
                                        <?php
                                        $ameta = auditMeta($action_meta, $entry['action_type']);
                                        $mmeta = auditMeta($module_icons, $entry['module']);
                                        $has_details = !empty($entry['details']);
                                        $pretty_details = '';
                                        if ($has_details) {
                                            $decoded = json_decode($entry['details'], true);
                                            $pretty_details = $decoded !== null
                                                ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                                                : $entry['details'];
                                        }
                                        ?>
                                        <tr>
                                            <td class="whitespace-nowrap" style="color:var(--text-secondary);font-size:12.5px;">
                                                <?php echo formatTimestampDisplay($entry['created_at']); ?>
                                            </td>
                                            <td>
                                                <span class="pill"><i class="<?php echo $mmeta['icon']; ?>" style="margin-right:5px;"></i><?php echo htmlspecialchars(friendlyModuleLabel($entry['module'])); ?></span>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $ameta['badge']; ?>"><i class="<?php echo $ameta['icon']; ?>" style="margin-right:4px;"></i><?php echo $ameta['label']; ?></span>
                                            </td>
                                            <td>
                                                <?php if ($entry['entity_name']): ?>
                                                    <div style="font-weight:600;color:var(--text);font-size:13px;"><?php echo htmlspecialchars($entry['entity_name']); ?></div>
                                                <?php endif; ?>
                                                <?php if ($entry['description']): ?>
                                                    <div style="color:var(--text-secondary);font-size:12.5px;"><?php echo htmlspecialchars($entry['description']); ?></div>
                                                <?php endif; ?>
                                                <?php if ($has_details): ?>
                                                    <details style="margin-top:3px;">
                                                        <summary style="font-size:12px;color:var(--accent);cursor:pointer;">View change details</summary>
                                                        <pre style="margin-top:4px;padding:8px;background:var(--surface-hover);border:1px solid var(--border);border-radius:6px;font-size:11.5px;line-height:1.5;color:var(--text);overflow:auto;max-height:220px;"><?php echo htmlspecialchars($pretty_details); ?></pre>
                                                    </details>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span style="font-size:13px;"><?php echo htmlspecialchars($entry['user'] ?: 'System'); ?></span>
                                            </td>
                                            <td class="hidden lg:table-cell" style="color:var(--text-secondary);font-size:12.5px;"><?php echo htmlspecialchars($entry['ip_address'] ?: '—'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6">
                                            <div class="empty-state">
                                                <div class="empty-state-icon"><i class="fa-solid fa-shield-halved"></i></div>
                                                <div class="empty-state-title">No audit events recorded</div>
                                                <div class="empty-state-text">Actions performed in the system will appear here.</div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($total_pages > 1): ?>
                        <?php
                        $pageStart = $total_rows > 0 ? $offset + 1 : 0;
                        $pageEnd = min($offset + count($audit_entries), $total_rows);
                        $start = max(1, $page - 2);
                        $end = min($total_pages, $page + 2);
                        ?>
                        <div class="pagination-shell no-print">
                            <div class="pagination-meta">
                                <div class="pagination-title">Showing <?php echo count($audit_entries); ?> entr<?php echo count($audit_entries) == 1 ? 'y' : 'ies'; ?> on this page</div>
                                <span>Records <?php echo $pageStart; ?>-<?php echo $pageEnd; ?> of <?php echo $total_rows; ?> total</span>
                            </div>
                            <div class="pagination-controls">
                                <div class="pagination-page-indicator">Page <?php echo $page; ?> of <?php echo $total_pages; ?></div>
                                <div class="pagination">
                                    <a href="<?php echo htmlspecialchars(buildAuditUrl(['page' => 1])); ?>" class="pagination-item compact <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                        <i class="fa-solid fa-chevrons-left"></i>
                                    </a>
                                    <a href="<?php echo htmlspecialchars(buildAuditUrl(['page' => max(1, $page - 1)])); ?>" class="pagination-item compact <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                        <i class="fa-solid fa-chevron-left"></i>
                                    </a>
                                    <?php if ($start > 1): ?>
                                        <a href="<?php echo htmlspecialchars(buildAuditUrl(['page' => 1])); ?>" class="pagination-item">1</a>
                                        <?php if ($start > 2): ?>
                                            <span class="pagination-ellipsis">...</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php for ($i = $start; $i <= $end; $i++): ?>
                                        <a href="<?php echo htmlspecialchars(buildAuditUrl(['page' => $i])); ?>" class="pagination-item <?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                                    <?php endfor; ?>
                                    <?php if ($end < $total_pages): ?>
                                        <?php if ($end < $total_pages - 1): ?>
                                            <span class="pagination-ellipsis">...</span>
                                        <?php endif; ?>
                                        <a href="<?php echo htmlspecialchars(buildAuditUrl(['page' => $total_pages])); ?>" class="pagination-item"><?php echo $total_pages; ?></a>
                                    <?php endif; ?>
                                    <a href="<?php echo htmlspecialchars(buildAuditUrl(['page' => min($total_pages, $page + 1)])); ?>" class="pagination-item compact <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                        <i class="fa-solid fa-chevron-right"></i>
                                    </a>
                                    <a href="<?php echo htmlspecialchars(buildAuditUrl(['page' => $total_pages])); ?>" class="pagination-item compact <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                        <i class="fa-solid fa-chevrons-right"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Clear Audit Confirmation Modal -->
    <div id="clearAuditModal" class="modal-backdrop" style="display:none;">
        <div class="modal-dialog sm">
            <div class="modal-header">
                <h3 class="modal-title">Clear Audit Trail</h3>
                <button type="button" class="modal-close" onclick="MailroomModal.close('clearAuditModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <p style="font-size:13px;color:var(--text-secondary);">
                    Are you sure you want to erase <strong style="color:var(--text);">all <?php echo number_format($stats_total); ?> recorded event<?php echo $stats_total === 1 ? '' : 's'; ?></strong>? A record of this clear will be kept.
                </p>
                <div class="alert alert-red mt-3" style="margin-bottom:0;">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span>Audit history is permanent evidence of system activity. Clearing it cannot be undone.</span>
                </div>
            </div>
            <form method="post" action="audit_trail.php" class="modal-footer" style="display:flex;justify-content:flex-end;gap:10px;">
                <?php echo csrf_field(); ?>
                <button type="button" onclick="MailroomModal.close('clearAuditModal')" class="btn btn-soft">Cancel</button>
                <button type="submit" name="clear_audit" value="1" class="btn btn-danger">
                    <i class="fa-regular fa-trash-can"></i> Clear Log
                </button>
            </form>
        </div>
    </div>

    <div id="toastContainer"></div>
    <script src="assets/app.js"></script>
</body>

</html>