<?php

require_once 'config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/csrf.php';
session_start();

// Initialize stats array with defaults
$stats = [
    'documents' => 0,
    'parcels_received' => 0,
    'pending_parcels' => 0,
    'newspapers' => 0,
    'document_types' => 0,
    'newspaper_categories' => 0,
    'today_documents' => 0,
    'today_parcels' => 0,
    'today_newspapers' => 0,
    'week_documents' => 0,
    'week_parcels' => 0,
    'week_newspapers' => 0,
    'month_documents' => 0,
    'month_parcels' => 0,
    'month_newspapers' => 0,
    'total_distributions' => 0,
    'total_pickups' => 0,
    'total_copies_received' => 0,
    'total_copies_distributed' => 0,
    'latest_parcel_received' => null,
    'latest_parcel_picked' => null,
    'dashboard_refreshed_at' => date('Y-m-d H:i:s')
];

$dashboard_parcels = null;
$attention_items = [];
$recent_activity = [];

// Check connection
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

// Get statistics with error handling
try {
    $result = $conn->query("SELECT COUNT(*) as total FROM documents");
    if ($result) {
        $stats['documents'] = $result->fetch_assoc()['total'];
    }

    $result = $conn->query("SELECT COUNT(*) as total FROM document_types");
    if ($result) {
        $stats['document_types'] = $result->fetch_assoc()['total'];
    }

    $result = $conn->query("SELECT COUNT(*) as total FROM parcels_received");
    if ($result) {
        $stats['parcels_received'] = $result->fetch_assoc()['total'];
    }

    // Pending parcels (not picked up)
    $result = $conn->query("
        SELECT COUNT(*) as total 
        FROM parcels_received pr 
        LEFT JOIN parcels_pickup pp ON pr.id = pp.parcel_id 
        WHERE pp.id IS NULL
    ");
    if ($result) {
        $stats['pending_parcels'] = $result->fetch_assoc()['total'];
    }

    $result = $conn->query("SELECT COUNT(*) as total FROM parcels_pickup");
    if ($result) {
        $stats['total_pickups'] = $result->fetch_assoc()['total'];
    }

    $result = $conn->query("SELECT COUNT(*) as total FROM newspapers");
    if ($result) {
        $stats['newspapers'] = $result->fetch_assoc()['total'];
    }

    $result = $conn->query("SELECT COUNT(*) as total FROM newspaper_categories");
    if ($result) {
        $stats['newspaper_categories'] = $result->fetch_assoc()['total'];
    }

    $result = $conn->query("SELECT COUNT(*) as total FROM document_distribution");
    if ($result) {
        $stats['total_distributions'] = $result->fetch_assoc()['total'];
    }

    $result = $conn->query("SELECT SUM(copies_received) as total FROM documents");
    if ($result) {
        $stats['total_copies_received'] = $result->fetch_assoc()['total'] ?? 0;
    }

    $result = $conn->query("SELECT SUM(number_distributed) as total FROM document_distribution");
    if ($result) {
        $stats['total_copies_distributed'] = $result->fetch_assoc()['total'] ?? 0;
    }

    $today = date('Y-m-d');
    $week_start = date('Y-m-d', strtotime('monday this week'));
    $month_start = date('Y-m-01');

    $result = $conn->query("SELECT COUNT(*) as total FROM documents WHERE date_received = '$today'");
    if ($result) {
        $stats['today_documents'] = $result->fetch_assoc()['total'];
    }

    $result = $conn->query("SELECT COUNT(*) as total FROM documents WHERE date_received >= '$week_start'");
    if ($result) {
        $stats['week_documents'] = $result->fetch_assoc()['total'];
    }

    $result = $conn->query("SELECT COUNT(*) as total FROM documents WHERE date_received >= '$month_start'");
    if ($result) {
        $stats['month_documents'] = $result->fetch_assoc()['total'];
    }

    $result = $conn->query("SELECT COUNT(*) as total FROM parcels_received WHERE DATE(date_received) = '$today'");
    if ($result) {
        $stats['today_parcels'] = $result->fetch_assoc()['total'];
    }

    $result = $conn->query("SELECT COUNT(*) as total FROM parcels_received WHERE date_received >= '$week_start'");
    if ($result) {
        $stats['week_parcels'] = $result->fetch_assoc()['total'];
    }

    $result = $conn->query("SELECT COUNT(*) as total FROM parcels_received WHERE date_received >= '$month_start'");
    if ($result) {
        $stats['month_parcels'] = $result->fetch_assoc()['total'];
    }

    $result = $conn->query("SELECT MAX(date_received) as latest_date FROM parcels_received");
    if ($result) {
        $stats['latest_parcel_received'] = $result->fetch_assoc()['latest_date'] ?? null;
    }

    $result = $conn->query("SELECT MAX(date_picked) as latest_date FROM parcels_pickup");
    if ($result) {
        $stats['latest_parcel_picked'] = $result->fetch_assoc()['latest_date'] ?? null;
    }

    $result = $conn->query("SELECT COUNT(*) as total FROM newspapers WHERE date_received = '$today'");
    if ($result) {
        $stats['today_newspapers'] = $result->fetch_assoc()['total'];
    }

    $result = $conn->query("SELECT COUNT(*) as total FROM newspapers WHERE date_received >= '$week_start'");
    if ($result) {
        $stats['week_newspapers'] = $result->fetch_assoc()['total'];
    }

    $result = $conn->query("SELECT COUNT(*) as total FROM newspapers WHERE date_received >= '$month_start'");
    if ($result) {
        $stats['month_newspapers'] = $result->fetch_assoc()['total'];
    }

    // Documents awaiting distribution (received but never distributed)
    $doc_awaiting = $conn->query("
        SELECT d.id, d.document_name, d.copies_received,
               COALESCE((SELECT SUM(number_distributed) FROM document_distribution WHERE document_id = d.id), 0) as distributed
        FROM documents d
        HAVING distributed < d.copies_received
        ORDER BY d.date_received DESC
        LIMIT 5
    ");
    if ($doc_awaiting) {
        while ($row = $doc_awaiting->fetch_assoc()) {
            $remaining = (int)$row['copies_received'] - (int)$row['distributed'];
            $attention_items[] = [
                'type' => 'document',
                'priority' => 'orange',
                'title' => $row['document_name'],
                'detail' => "{$remaining} copies awaiting distribution",
                'url' => "documents.php"
            ];
        }
    }

    // Parcels pending pickup (overdue / waiting)
    $pending_parcels = $conn->query("
        SELECT pr.tracking_id, pr.addressed_to, pr.date_received,
               DATEDIFF('$today', pr.date_received) as days_waiting
        FROM parcels_received pr
        LEFT JOIN parcels_pickup pp ON pr.id = pp.parcel_id
        WHERE pp.id IS NULL
        ORDER BY pr.date_received ASC
        LIMIT 5
    ");
    if ($pending_parcels) {
        while ($row = $pending_parcels->fetch_assoc()) {
            $days = (int)$row['days_waiting'];
            $attention_items[] = [
                'type' => 'parcel',
                'priority' => $days >= 3 ? 'red' : ($days >= 1 ? 'orange' : 'green'),
                'title' => $row['tracking_id'] . ' — ' . $row['addressed_to'],
                'detail' => $days == 0 ? 'Awaiting pickup today' : "Awaiting pickup for {$days} day" . ($days === 1 ? '' : 's'),
                'url' => "parcels.php"
            ];
        }
    }

    // Recent activity - combined timeline
    $recent_activity = [];

    // Document received
    $res = $conn->query("
        SELECT 'doc_received' as kind, document_name as title, date_received as date,
               CONCAT('Received ', copies_received, ' copy', IF(copies_received=1,'','s')) as detail
        FROM documents ORDER BY date_received DESC, id DESC LIMIT 6
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $recent_activity[] = $row + ['color' => 'green', 'icon' => 'fa-file-lines'];
        }
    }

    // Parcel received
    $res = $conn->query("
        SELECT 'parcel_received' as kind, tracking_id as title, date_received as date,
               CONCAT('Received from ', sender) as detail
        FROM parcels_received ORDER BY date_received DESC, id DESC LIMIT 6
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $recent_activity[] = $row + ['color' => 'blue', 'icon' => 'fa-box'];
        }
    }

    // Parcel pickup
    $res = $conn->query("
        SELECT 'parcel_picked' as kind, pr.tracking_id as title, pp.date_picked as date,
               CONCAT('Picked up by ', pp.picked_by) as detail
        FROM parcels_pickup pp JOIN parcels_received pr ON pp.parcel_id = pr.id
        ORDER BY pp.date_picked DESC, pp.id DESC LIMIT 6
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $recent_activity[] = $row + ['color' => 'green', 'icon' => 'fa-box-open'];
        }
    }

    // Document distributed
    $res = $conn->query("
        SELECT 'doc_distributed' as kind, d.document_name as title, dd.date_distributed as date,
               CONCAT('Distributed ', dd.number_distributed, ' copy', IF(dd.number_distributed=1,'','s')) as detail
        FROM document_distribution dd JOIN documents d ON dd.document_id = d.id
        ORDER BY dd.date_distributed DESC, dd.id DESC LIMIT 6
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $recent_activity[] = $row + ['color' => 'orange', 'icon' => 'fa-share-from-square'];
        }
    }

    // Newspaper received
    $res = $conn->query("
        SELECT 'newspaper_received' as kind, newspaper_name as title, date_received as date,
               CONCAT(newspaper_number, ' — ', available_copies, ' copies available') as detail
        FROM newspapers ORDER BY date_received DESC, id DESC LIMIT 6
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $recent_activity[] = $row + ['color' => 'gray', 'icon' => 'fa-newspaper'];
        }
    }

    // Newspaper distributed
    $res = $conn->query("
        SELECT 'news_distributed' as kind, distributed_to as title, date_distributed as date,
               CONCAT('Received ', copies, ' edition(s)') as detail
        FROM distribution ORDER BY date_distributed DESC, id DESC LIMIT 6
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $recent_activity[] = $row + ['color' => 'orange', 'icon' => 'fa-share-from-square'];
        }
    }

    // Sort by date desc, take top 10
    usort($recent_activity, function ($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });
    $recent_activity = array_slice($recent_activity, 0, 10);

    // Dashboard parcel table
    $dashboard_parcels = $conn->query("
        SELECT pr.id, pr.tracking_id, pr.sender, pr.addressed_to, pr.date_received,
               CASE WHEN pp.id IS NULL THEN 'pending' ELSE 'picked' END as status
        FROM parcels_received pr
        LEFT JOIN parcels_pickup pp ON pr.id = pp.parcel_id
        ORDER BY pr.date_received DESC, pr.id DESC
        LIMIT 6
    ");
} catch (Exception $e) {
    $error = "Error loading data: " . $e->getMessage();
}

// Format activity icon
function activityDotColor($color) {
    return match($color) {
        'green' => 'green',
        'orange' => 'orange',
        'red' => 'red',
        'blue' => '',
        default => 'gray'
    };
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — Mailroom Operations</title>
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
            <!-- Page header -->
            <div class="page-header flex items-center justify-between gap-4">
                <div>
                    <div class="breadcrumb">
                        <span>Overview</span>
                    </div>
                    <h1 class="page-header-title">Dashboard</h1>
                    <p id="dashboardClock" class="mt-1 text-[13px] text-[#7d8398]" data-server-time="<?php echo htmlspecialchars($stats['dashboard_refreshed_at']); ?>">
                        <?php echo date('l, F j, Y g:i A', strtotime($stats['dashboard_refreshed_at'])); ?>
                    </p>
                </div>
                <div class="header-actions flex items-center gap-2 print-hide">
                    <a href="#" onclick="document.getElementById('dashSearchToggle').classList.toggle('hidden');document.getElementById('dashSearchInput') && document.getElementById('dashSearchInput').focus();return false;" class="btn btn-soft" id="dashSearchToggleBtn">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <span class="hidden sm:inline">Search</span>
                    </a>
                    <a href="documents.php" class="btn btn-soft">
                        <i class="fa-solid fa-file-lines"></i>
                        <span class="hidden sm:inline">Documents</span>
                    </a>
                </div>
            </div>

            <div class="page-body">
                <?php if (isset($error)): ?>
                    <div class="alert alert-red">
                        <i class="fa-regular fa-circle-exclamation"></i>
                        <span><?php echo $error; ?></span>
                    </div>
                <?php endif; ?>

                <!-- Global Search -->
                <div id="dashSearchToggle" class="hidden">
                    <div class="card mb-6">
                        <div class="card-header">
                            <div>
                                <h2 class="card-title"><i class="fa-solid fa-magnifying-glass" style="margin-right:6px;color:var(--accent);"></i>Global Search</h2>
                                <p class="card-subtitle">Find any document, parcel, newspaper, distribution, or recipient.</p>
                            </div>
                            <button class="modal-close" onclick="document.getElementById('dashSearchToggle').classList.add('hidden')"><i class="fa-solid fa-xmark"></i></button>
                        </div>
                        <div class="card-body">
                            <div style="display:flex;gap:10px;align-items:center;margin-bottom:14px;">
                                <div style="flex:1;position:relative;">
                                    <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--text-faint);font-size:14px;"></i>
                                    <input type="text" id="dashSearchInput" class="input" style="padding-left:40px;padding-right:40px;"
                                           placeholder="Search documents, parcels, newspapers, recipients..."
                                           autocomplete="off">
                                    <a href="#" onclick="document.getElementById('dashSearchInput').value='';renderDashSearch('');return false;"
                                       style="position:absolute;right:14px;top:50%;transform:translateY(-50%);color:var(--text-faint);font-size:13px;display:none;" id="dashSearchClear" title="Clear">
                                        <i class="fa-solid fa-xmark"></i>
                                    </a>
                                </div>
                            </div>
                            <p id="dashSearchHint" style="font-size:12px;color:var(--text-faint);margin:0;">Type at least 2 characters to search all modules.</p>

                            <div id="dashResultsSection" style="display:none;margin-top:16px;">
                                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:12px;">
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <h3 id="dashResultsCount" style="font-size:14px;font-weight:600;color:var(--text);">0 results</h3>
                                        <span class="pill badge-blue" id="dashSearchStatus" style="display:none;">searching...</span>
                                    </div>
                                    <div style="display:flex;gap:8px;" class="search-filters">
                                        <button type="button" class="filter-chip active" data-filter="all">All</button>
                                        <button type="button" class="filter-chip" data-filter="document">Documents</button>
                                        <button type="button" class="filter-chip" data-filter="parcel">Parcels</button>
                                        <button type="button" class="filter-chip" data-filter="newspaper">Newspapers</button>
                                    </div>
                                </div>
                                <div id="dashResultsList" style="display:flex;flex-direction:column;gap:10px;"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Top Stats -->
                <div class="stat-grid mb-6">
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fa-solid fa-file-lines"></i></div>
                        <div class="stat-label">Total Documents</div>
                        <div class="stat-value"><?php echo number_format($stats['documents']); ?></div>
                        <div class="stat-hint"><?php echo number_format($stats['today_documents']); ?> received today</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon orange"><i class="fa-solid fa-box"></i></div>
                        <div class="stat-label">Parcels Awaiting Pickup</div>
                        <div class="stat-value"><?php echo number_format($stats['pending_parcels']); ?></div>
                        <div class="stat-hint"><?php echo number_format($stats['today_parcels']); ?> received today</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fa-solid fa-file-signature"></i></div>
                        <div class="stat-label">Documents Awaiting Distribution</div>
                        <div class="stat-value"><?php echo number_format(count(array_filter($attention_items, fn($i) => $i['type'] === 'document'))); ?></div>
                        <div class="stat-hint">Copies not yet distributed</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon gray"><i class="fa-regular fa-newspaper"></i></div>
                        <div class="stat-label">Newspapers Today</div>
                        <div class="stat-value"><?php echo number_format($stats['today_newspapers']); ?></div>
                        <div class="stat-hint"><?php echo number_format($stats['week_newspapers']); ?> this week</div>
                    </div>
                </div>

                <!-- Quick actions -->
                <div class="card mb-6">
                    <div class="card-header">
                        <div>
                            <h2 class="card-title">Quick Actions</h2>
                            <p class="card-subtitle">Common operations</p>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="quick-actions">
                            <a href="documents.php" class="quick-action">
                                <span class="quick-action-icon"><i class="fa-solid fa-file-circle-plus"></i></span>
                                <span class="quick-action-label">Receive Document</span>
                            </a>
                            <a href="parcels.php" class="quick-action">
                                <span class="quick-action-icon"><i class="fa-solid fa-box-open"></i></span>
                                <span class="quick-action-label">Register Parcel</span>
                            </a>
                            <a href="list.php" class="quick-action">
                                <span class="quick-action-icon"><i class="fa-solid fa-newspaper"></i></span>
                                <span class="quick-action-label">Newspaper</span>
                            </a>
                            <a href="settings.php" class="quick-action">
                                <span class="quick-action-icon"><i class="fa-solid fa-gear"></i></span>
                                <span class="quick-action-label">Backup</span>
                            </a>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Attention Required -->
                    <div class="card lg:col-span-2">
                        <div class="card-header">
                            <div>
                                <h2 class="card-title">Requires Attention</h2>
                                <p class="card-subtitle">Items needing action today</p>
                            </div>
                            <?php if (count($attention_items) > 0): ?>
                                <span class="pill badge-red"><?php echo count($attention_items); ?> items</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <?php if (empty($attention_items)): ?>
                                <div class="empty-state">
                                    <div class="empty-state-icon"><i class="fa-solid fa-check"></i></div>
                                    <div class="empty-state-title">All caught up</div>
                                    <div class="empty-state-text">No items currently require your attention.</div>
                                </div>
                            <?php else: ?>
                                <div style="display:flex;flex-direction:column;gap:8px;">
                                    <?php foreach ($attention_items as $i => $item): ?>
                                        <?php
                                        $colorClass = match($item['priority']) {
                                            'red' => 'badge-red',
                                            'orange' => 'badge-orange',
                                            default => 'badge-green'
                                        };
                                        $icon = match($item['type']) {
                                            'document' => 'fa-file-lines',
                                            'parcel' => 'fa-box',
                                            default => 'fa-circle-exclamation'
                                        };
                                        ?>
                                        <a href="<?php echo $item['url']; ?>"
                                           style="display:flex;align-items:center;gap:12px;padding:10px 12px;border:1px solid var(--border);border-radius:8px;text-decoration:none;transition:all .15s;">
                                            <div style="width:32px;height:32px;border-radius:8px;background:<?php echo $item['priority']==='red' ? 'var(--red-soft)' : ($item['priority']==='orange' ? 'var(--orange-soft)' : 'var(--green-soft)'); ?>;display:flex;align-items:center;justify-content:center;color:<?php echo $item['priority']==='red' ? 'var(--red)' : ($item['priority']==='orange' ? 'var(--orange)' : 'var(--green)'); ?>;font-size:13px;flex-shrink:0;">
                                                <i class="fa-solid <?php echo $icon; ?>"></i>
                                            </div>
                                            <div style="flex:1;min-width:0;">
                                                <div style="font-size:13px;font-weight:550;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($item['title']); ?></div>
                                                <div style="font-size:12px;color:var(--text-muted);"><?php echo htmlspecialchars($item['detail']); ?></div>
                                            </div>
                                            <span class="badge <?php echo $colorClass; ?>"><?php echo $item['type'] === 'document' ? 'Document' : 'Parcel'; ?></span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Activity Timeline -->
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <h2 class="card-title">Recent Activity</h2>
                                <p class="card-subtitle">Latest system events</p>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_activity)): ?>
                                <div class="empty-state">
                                    <div class="empty-state-icon"><i class="fa-regular fa-clock"></i></div>
                                    <div class="empty-state-title">No activity yet</div>
                                    <div class="empty-state-text">Events will appear here as you process mail.</div>
                                </div>
                            <?php else: ?>
                                <div class="timeline">
                                    <?php foreach ($recent_activity as $act): ?>
                                        <div class="timeline-item">
                                            <div class="timeline-dot <?php echo activityDotColor($act['color']); ?>">
                                                <i class="fa-solid <?php echo isset($act['icon']) ? $act['icon'] : 'fa-circle'; ?>"></i>
                                            </div>
                                            <div class="timeline-date"><?php echo date('g:i A', strtotime($act['date'])); ?><?php echo isset($act['date']) && date('Y-m-d', strtotime($act['date'])) == date('Y-m-d') ? '' : ' · ' . date('M j', strtotime($act['date'])); ?></div>
                                            <div class="timeline-title"><?php echo htmlspecialchars($act['title']); ?></div>
                                            <div class="timeline-detail"><?php echo htmlspecialchars($act['detail']); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Recent Parcels -->
                <div class="card mt-6">
                    <div class="card-header">
                        <div>
                            <h2 class="card-title">Recent Parcels</h2>
                            <p class="card-subtitle">Latest incoming shipments</p>
                        </div>
                        <a href="parcels.php" class="btn btn-soft btn-sm">
                            View all <i class="fa-solid fa-arrow-right"></i>
                        </a>
                    </div>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Tracking ID</th>
                                    <th>Recipient</th>
                                    <th class="hidden md:table-cell">Sender</th>
                                    <th>Status</th>
                                    <th>Received</th>
                                    <th class="print-hide">Track</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($dashboard_parcels && $dashboard_parcels->num_rows > 0): ?>
                                    <?php while ($parcel = $dashboard_parcels->fetch_assoc()): ?>
                                        <tr>
                                            <td class="table-cell-mono font-medium"><?php echo htmlspecialchars($parcel['tracking_id']); ?></td>
                                            <td class="table-cell-title"><?php echo htmlspecialchars($parcel['addressed_to']); ?></td>
                                            <td class="hidden md:table-cell" style="color:var(--text-secondary);"><?php echo htmlspecialchars($parcel['sender']); ?></td>
                                            <td>
                                                <?php if ($parcel['status'] === 'picked'): ?>
                                                    <span class="badge badge-green">Picked Up</span>
                                                <?php else: ?>
                                                    <span class="badge badge-orange">Pending</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="color:var(--text-secondary);"><?php echo date('M j, Y', strtotime($parcel['date_received'])); ?></td>
                                            <td class="print-hide">
                                                <a href="#" onclick="MailroomTracking.open('<?php echo urlencode($parcel['tracking_id']); ?>'); return false;" class="icon-btn" title="Track parcel">
                                                    <i class="fa-solid fa-location-dot"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" style="text-align:center;padding:32px;color:var(--text-muted);">
                                            No parcel records yet.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        (function() {
            const clock = document.getElementById('dashboardClock');
            if (!clock) return;
            const serverTime = clock.dataset.serverTime;
            const baseTime = serverTime ? new Date(serverTime.replace(' ', 'T')) : new Date();
            if (Number.isNaN(baseTime.getTime())) return;
            let currentTime = baseTime;
            function renderClock() {
                clock.textContent = currentTime.toLocaleString('en-US', {
                    weekday: 'long', month: 'long', day: 'numeric', year: 'numeric',
                    hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true
                });
                currentTime = new Date(currentTime.getTime() + 1000);
            }
            renderClock();
            setInterval(renderClock, 1000);
        })();
    </script>

    <script>
        (function() {
            const input = document.getElementById('dashSearchInput');
            if (!input) return;
            const hint = document.getElementById('dashSearchHint');
            const section = document.getElementById('dashResultsSection');
            const countEl = document.getElementById('dashResultsCount');
            const listEl = document.getElementById('dashResultsList');
            const statusEl = document.getElementById('dashSearchStatus');
            const clearBtn = document.getElementById('dashSearchClear');
            let activeFilter = 'all';
            let lastQuery = '';
            let results = [];
            let debounceTimer;
            let reqSeq = 0;

            document.querySelectorAll('.search-filters .filter-chip').forEach(btn => {
                btn.addEventListener('click', function() {
                    document.querySelectorAll('.search-filters .filter-chip').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    activeFilter = this.dataset.filter;
                    renderResults();
                });
            });

            input.addEventListener('input', function() {
                clearTimeout(debounceTimer);
                const value = this.value.trim();
                clearBtn.style.display = value ? 'block' : 'none';
                if (value.length >= 2) {
                    hint.style.display = 'none';
                    debounceTimer = setTimeout(() => runSearch(value), 300);
                } else {
                    hint.style.display = 'block';
                    lastQuery = '';
                    results = [];
                    section.style.display = 'none';
                }
            });

            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const value = this.value.trim();
                    if (value.length >= 2) runSearch(value);
                }
            });

            function runSearch(q) {
                const seq = ++reqSeq;
                lastQuery = q;
                section.style.display = 'block';
                countEl.textContent = 'Searching...';
                statusEl.style.display = 'inline-flex';
                fetch('search_api.php?q=' + encodeURIComponent(q))
                    .then(r => r.json())
                    .then(data => {
                        if (seq !== reqSeq) return;
                        statusEl.style.display = 'none';
                        results = (data && data.results) || [];
                        renderResults();
                    })
                    .catch(() => {
                        if (seq !== reqSeq) return;
                        statusEl.style.display = 'none';
                        results = [];
                        countEl.textContent = '0 results';
                        listEl.innerHTML = '<div class="alert alert-red">Failed to load search results.</div>';
                    });
            }

            function renderResults() {
                const filtered = results.filter(r => activeFilter === 'all' || r.type === activeFilter || r.type.startsWith(activeFilter));
                countEl.textContent = filtered.length + ' result' + (filtered.length === 1 ? '' : 's') + ' found';
                if (filtered.length === 0) {
                    listEl.innerHTML = `
                        <div class="empty-state">
                            <div class="empty-state-icon"><i class="fa-solid fa-magnifying-glass"></i></div>
                            <div class="empty-state-title">No results found for "${esc(lastQuery)}"</div>
                            <div class="empty-state-text">Try different keywords or check your spelling.</div>
                        </div>`;
                    return;
                }
                const colorClasses = { green: 'badge-green', blue: 'badge-blue', orange: 'badge-orange', red: 'badge-red', gray: 'badge-gray' };
                listEl.innerHTML = filtered.map(r => `
                    <a href="${r.url}" class="search-result-item" data-type="${r.type}">
                        <div class="search-result-icon ${r.color}"><i class="fa-solid ${r.icon}"></i></div>
                        <div style="flex:1;min-width:0;">
                            <div class="search-result-title">${esc(r.title)}</div>
                            <div class="search-result-detail">${esc(r.detail)}</div>
                        </div>
                        <div style="text-align:right;flex-shrink:0;">
                            <div class="search-result-meta">${esc(r.meta || '')}</div>
                            ${r.badge ? `<span class="badge ${colorClasses[r.color] || 'badge-gray'}">${esc(r.badge)}</span>` : ''}
                        </div>
                    </a>`).join('');
            }
        })();
    </script>
    <script src="assets/app.js"></script>
</body>

</html>