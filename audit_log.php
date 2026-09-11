<?php
require_once './config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/csrf.php';
session_start();

$module_filter = $_GET['module'] ?? '';
$action_filter = $_GET['action'] ?? '';
$date_filter = $_GET['date'] ?? '';
$search = trim($_GET['q'] ?? '');
$per_page = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

$where = [];
$params = [];
$types = '';

if ($module_filter !== '' && in_array($module_filter, ['parcel', 'document', 'doc_type', 'distribution', 'newspaper', 'newspaper_category', 'recipient', 'backup', 'system'])) {
    $where[] = "module = ?";
    $params[] = $module_filter;
    $types .= 's';
}
if ($action_filter !== '' && in_array($action_filter, ['create', 'update', 'delete', 'receive', 'pickup', 'distribute', 'status_change', 'import', 'restore', 'backup'])) {
    $where[] = "action = ?";
    $params[] = $action_filter;
    $types .= 's';
}
if ($date_filter !== '') {
    $where[] = "DATE(created_at) = ?";
    $params[] = $date_filter;
    $types .= 's';
}
if ($search !== '') {
    $where[] = "(user_name LIKE ? OR details LIKE ? OR module LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= 'sss';
}

$where_sql = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Count total
$count_sql = "SELECT COUNT(*) as total FROM audit_log $where_sql";
$count_stmt = $conn->prepare($count_sql);
if ($types) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total = $count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();
$total_pages = max(1, ceil($total / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

// Fetch records
$sql = "SELECT * FROM audit_log $where_sql ORDER BY created_at DESC LIMIT $per_page OFFSET $offset";
$stmt = $conn->prepare($sql);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Trail - Mailroom Ops</title>
    <link rel="icon" type="image/png" href="./images/logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/app.css">
    <meta name="csrf-token" content="<?php echo csrf_token(); ?>">
</head>

<body>
    <div class="flex">
        <?php include 'sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header flex items-center justify-between gap-4">
                <div>
                    <div class="breadcrumb">
                        <a href="index.php">System</a>
                        <span class="sep">/</span>
                        <span>Audit Trail</span>
                    </div>
                    <h1 class="page-header-title">Audit Trail</h1>
                    <p class="page-header-subtitle">A complete history of actions performed in the system.</p>
                </div>
                <div class="header-actions print-hide">
                    <button class="btn btn-soft" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
                </div>
            </div>

            <div class="page-body">
                <?php
                $action_badges = [
                    'create' => 'badge-green',
                    'update' => 'badge-blue',
                    'delete' => 'badge-red',
                    'receive' => 'badge-blue',
                    'pickup' => 'badge-green',
                    'distribute' => 'badge-orange',
                    'status_change' => 'badge-orange',
                    'import' => 'badge-blue',
                    'restore' => 'badge-red',
                    'backup' => 'badge-gray',
                ];
                $action_icons = [
                    'create' => 'fa-plus',
                    'update' => 'fa-pen',
                    'delete' => 'fa-trash',
                    'receive' => 'fa-inbox',
                    'pickup' => 'fa-box-open',
                    'distribute' => 'fa-share-from-square',
                    'status_change' => 'fa-arrows-rotate',
                    'import' => 'fa-file-import',
                    'restore' => 'fa-rotate-left',
                    'backup' => 'fa-database',
                ];
                $module_labels = [
                    'parcel' => 'Parcel',
                    'document' => 'Document',
                    'doc_type' => 'Document Type',
                    'distribution' => 'Distribution',
                    'newspaper' => 'Newspaper',
                    'newspaper_category' => 'Category',
                    'recipient' => 'Recipient',
                    'backup' => 'Backup',
                    'system' => 'System',
                ];
                ?>

                <!-- Filters -->
                <div class="card" style="margin-bottom:20px;">
                    <div class="card-body" style="padding:16px 20px;">
                        <form method="GET" action="audit_log.php" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" class="input" style="flex:1;min-width:160px;" placeholder="Search details or user...">
                            <select name="module" class="select" style="min-width:140px;">
                                <option value="">All Modules</option>
                                <?php foreach ($module_labels as $mk => $ml): ?>
                                    <option value="<?php echo $mk; ?>" <?php echo $module_filter === $mk ? 'selected' : ''; ?>><?php echo $ml; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="action" class="select" style="min-width:140px;">
                                <option value="">All Actions</option>
                                <?php foreach ($action_badges as $ak => $ab): ?>
                                    <option value="<?php echo $ak; ?>" <?php echo $action_filter === $ak ? 'selected' : ''; ?>><?php echo ucwords(str_replace('_', ' ', $ak)); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="date" name="date" value="<?php echo htmlspecialchars($date_filter); ?>" class="input" style="width:150px;">
                            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
                            <a href="audit_log.php" class="btn btn-soft"><i class="fa-solid fa-rotate"></i></a>
                        </form>
                    </div>
                </div>

                <!-- Stats -->
                <div class="stat-grid mb-6">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fa-regular fa-clock"></i></div>
                        <div class="stat-label">Total Events</div>
                        <div class="stat-value"><?php echo number_format($total); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fa-solid fa-plus"></i></div>
                        <div class="stat-label">Creates</div>
                        <div class="stat-value"><?php echo number_format($conn->query("SELECT COUNT(*) FROM audit_log WHERE action='create'")->fetch_row()[0]); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon orange"><i class="fa-solid fa-pen"></i></div>
                        <div class="stat-label">Changes</div>
                        <div class="stat-value"><?php echo number_format($conn->query("SELECT COUNT(*) FROM audit_log WHERE action IN ('update','status_change','distribute','pickup','restore')")->fetch_row()[0]); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon red"><i class="fa-solid fa-trash"></i></div>
                        <div class="stat-label">Deletes</div>
                        <div class="stat-value"><?php echo number_format($conn->query("SELECT COUNT(*) FROM audit_log WHERE action='delete'")->fetch_row()[0]); ?></div>
                    </div>
                </div>

                <!-- Log table -->
                <div class="card">
                    <div class="card-header">
                        <div>
                            <div class="card-title">Recent Events</div>
                            <div class="card-subtitle"><?php echo number_format($total); ?> logged event(s)</div>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>When</th>
                                    <th>Actor</th>
                                    <th>Action</th>
                                    <th>Module</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($logs)): ?>
                                    <tr>
                                        <td colspan="5">
                                            <div class="empty-state">
                                                <div class="empty-state-icon"><i class="fa-regular fa-comments"></i></div>
                                                <div class="empty-state-title">No activity logged</div>
                                                <div class="empty-state-text">Events will appear here as actions are performed.</div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($logs as $log): ?>
                                        <tr>
                                            <td style="white-space:nowrap;">
                                                <div class="table-cell-subtitle" style="font-size:12px;"><?php echo formatTimestampDisplay($log['created_at']); ?></div>
                                            </td>
                                            <td>
                                                <div style="display:flex;align-items:center;gap:8px;">
                                                    <div style="width:26px;height:26px;border-radius:50%;background:var(--accent-soft);display:flex;align-items:center;justify-content:center;color:var(--accent);font-size:11px;font-weight:600;flex-shrink:0;">
                                                        <?php echo strtoupper(substr($log['user_name'] ?: 'S', 0, 1)); ?>
                                                    </div>
                                                    <span class="table-cell-title"><?php echo htmlspecialchars($log['user_name']); ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $action_badges[$log['action']] ?? 'badge-gray'; ?>">
                                                    <i class="fa-solid <?php echo $action_icons[$log['action']] ?? 'fa-circle'; ?>"></i>
                                                    <?php echo ucwords(str_replace('_', ' ', $log['action'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="text-xs" style="color:var(--text-secondary);">
                                                    <?php echo $module_labels[$log['module']] ?? ucfirst($log['module']); ?>
                                                    <?php if ($log['record_id']): ?>
                                                        <span class="badge badge-gray" style="font-size:10px;">#<?php echo $log['record_id']; ?></span>
                                                    <?php endif; ?>
                                                </span>
                                            </td>
                                            <td style="max-width:320px;">
                                                <span class="table-cell-subtitle" style="font-size:12px;display:block;white-space:normal;line-height:1.5;">
                                                    <?php echo htmlspecialchars($log['details'] ?: '—'); ?>
                                                </span>
                                                <?php if ($log['ip_address']): ?>
                                                    <span class="text-xs" style="color:var(--text-faint);font-size:10px;"><i class="fa-solid fa-globe"></i> <?php echo htmlspecialchars($log['ip_address']); ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($total_pages > 1): ?>
                        <div class="pagination-shell">
                            <div class="pagination-meta">
                                <div class="pagination-title">Audit log entries</div>
                                <span>Page <?php echo $page; ?> of <?php echo $total_pages; ?> (<?php echo number_format($total); ?> events)</span>
                            </div>
                            <div class="pagination-controls">
                                <div class="pagination-page-indicator">Page <?php echo $page; ?> of <?php echo $total_pages; ?></div>
                                <div class="pagination">
                                    <?php
                                    $base = 'audit_log.php?';
                                    $qstring = http_build_query(array_filter(['module' => $module_filter, 'action' => $action_filter, 'date' => $date_filter, 'q' => $search]));
                                    if ($qstring) $base .= $qstring . '&';
                                    $start = max(1, $page - 2);
                                    $end = min($total_pages, $page + 2);
                                    ?>
                                    <a class="pagination-item compact <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo $base; ?>page=1"><i class="fa-regular fa-chevrons-left"></i></a>
                                    <a class="pagination-item compact <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo $base; ?>page=<?php echo max(1, $page - 1); ?>"><i class="fa-regular fa-chevron-left"></i></a>
                                    <?php if ($start > 1): ?>
                                        <a class="pagination-item" href="<?php echo $base; ?>page=1">1</a>
                                        <?php if ($start > 2): ?><span class="pagination-ellipsis">...</span><?php endif; ?>
                                    <?php endif; ?>
                                    <?php for ($i = $start; $i <= $end; $i++): ?>
                                        <?php if ($i == $page): ?>
                                            <span class="pagination-item active"><?php echo $i; ?></span>
                                        <?php else: ?>
                                            <a class="pagination-item" href="<?php echo $base; ?>page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                    <?php if ($end < $total_pages): ?>
                                        <?php if ($end < $total_pages - 1): ?><span class="pagination-ellipsis">...</span><?php endif; ?>
                                        <a class="pagination-item" href="<?php echo $base; ?>page=<?php echo $total_pages; ?>"><?php echo $total_pages; ?></a>
                                    <?php endif; ?>
                                    <a class="pagination-item compact <?php echo $page >= $total_pages ? 'disabled' : ''; ?>" href="<?php echo $base; ?>page=<?php echo min($total_pages, $page + 1); ?>"><i class="fa-regular fa-chevron-right"></i></a>
                                    <a class="pagination-item compact <?php echo $page >= $total_pages ? 'disabled' : ''; ?>" href="<?php echo $base; ?>page=<?php echo $total_pages; ?>"><i class="fa-regular fa-chevrons-right"></i></a>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script src="assets/app.js"></script>
</body>

</html>