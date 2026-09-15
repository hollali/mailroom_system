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
    'dashboard_refreshed_at' => date('Y-m-d H:i:s'),
    'yesterday_documents' => 0,
    'yesterday_newspapers' => 0,
    'prev_week_newspapers' => 0,
    'picked_today' => 0,
    'awaiting_distribution' => 0,
    'dist_pct' => 0,
    'delta_docs' => 0,
    'delta_docs_pct' => 0,
    'delta_week_news' => 0,
    'delta_week_news_pct' => 0
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

    // Prior-period comparisons for KPI deltas
    $result = $conn->query("SELECT COUNT(*) as total FROM documents WHERE date_received = DATE_SUB(CURDATE(), INTERVAL 1 DAY)");
    if ($result) {
        $stats['yesterday_documents'] = (int)$result->fetch_assoc()['total'];
    }

    $result = $conn->query("SELECT COUNT(*) as total FROM newspapers WHERE date_received = DATE_SUB(CURDATE(), INTERVAL 1 DAY)");
    if ($result) {
        $stats['yesterday_newspapers'] = (int)$result->fetch_assoc()['total'];
    }

    $result = $conn->query("SELECT COUNT(*) as total FROM newspapers WHERE date_received >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND date_received < DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
    if ($result) {
        $stats['prev_week_newspapers'] = (int)$result->fetch_assoc()['total'];
    }

    $result = $conn->query("SELECT COUNT(*) as total FROM parcels_pickup WHERE DATE(date_picked) = CURDATE()");
    if ($result) {
        $stats['picked_today'] = (int)$result->fetch_assoc()['total'];
    }

    // Documents awaiting distribution (uncapped count)
    $res = $conn->query("
        SELECT COUNT(*) as total FROM documents d
        LEFT JOIN (SELECT document_id, SUM(number_distributed) s FROM document_distribution GROUP BY document_id) x ON x.document_id = d.id
        WHERE COALESCE(x.s, 0) < d.copies_received
    ");
    if ($res) {
        $stats['awaiting_distribution'] = (int)$res->fetch_assoc()['total'];
    }

    // Distribution progress (% of all copies distributed)
    $stats['dist_pct'] = $stats['total_copies_received'] > 0
        ? round($stats['total_copies_distributed'] / $stats['total_copies_received'] * 100, 1)
        : 0;

    // KPI deltas
    $stats['delta_docs'] = $stats['today_documents'] - $stats['yesterday_documents'];
    $stats['delta_docs_pct'] = $stats['yesterday_documents'] > 0
        ? round($stats['delta_docs'] / $stats['yesterday_documents'] * 100)
        : ($stats['delta_docs'] > 0 ? 100 : 0);
    $stats['delta_week_news'] = $stats['week_newspapers'] - $stats['prev_week_newspapers'];
    $stats['delta_week_news_pct'] = $stats['prev_week_newspapers'] > 0
        ? round($stats['delta_week_news'] / $stats['prev_week_newspapers'] * 100)
        : ($stats['delta_week_news'] > 0 ? 100 : 0);

    // ── Daily trend series (last 30 days) ─────────────────────
    $trend_days = 30;
    $trend_dates = [];
    $trend_index = [];
    for ($i = $trend_days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $trend_dates[] = $d;
        $trend_index[$d] = $trend_days - 1 - $i;
    }

    $series_docs = array_fill(0, $trend_days, 0);
    $series_parcels = array_fill(0, $trend_days, 0);
    $series_papers = array_fill(0, $trend_days, 0);
    $series_pickups = array_fill(0, $trend_days, 0);
    $series_docdist = array_fill(0, $trend_days, 0);
    $series_newsdist = array_fill(0, $trend_days, 0);

    $fill_trend = function ($res, &$arr) use ($trend_index) {
        if (!$res) return;
        while ($r = $res->fetch_assoc()) {
            $k = isset($r['d']) ? $r['d'] : null;
            if ($k !== null && isset($trend_index[$k])) {
                $arr[$trend_index[$k]] = (int)$r['c'];
            }
        }
    };

    $fill_trend($conn->query("SELECT DATE(date_received) d, COUNT(*) c FROM documents WHERE date_received >= DATE_SUB(CURDATE(), INTERVAL " . ($trend_days - 1) . " DAY) GROUP BY DATE(date_received)"), $series_docs);
    $fill_trend($conn->query("SELECT DATE(date_received) d, COUNT(*) c FROM parcels_received WHERE date_received >= DATE_SUB(CURDATE(), INTERVAL " . ($trend_days - 1) . " DAY) GROUP BY DATE(date_received)"), $series_parcels);
    $fill_trend($conn->query("SELECT DATE(date_received) d, COUNT(*) c FROM newspapers WHERE date_received >= DATE_SUB(CURDATE(), INTERVAL " . ($trend_days - 1) . " DAY) GROUP BY DATE(date_received)"), $series_papers);
    $fill_trend($conn->query("SELECT DATE(date_picked) d, COUNT(*) c FROM parcels_pickup WHERE date_picked >= DATE_SUB(CURDATE(), INTERVAL " . ($trend_days - 1) . " DAY) GROUP BY DATE(date_picked)"), $series_pickups);
    $fill_trend($conn->query("SELECT DATE(date_distributed) d, COUNT(*) c FROM document_distribution WHERE date_distributed >= DATE_SUB(CURDATE(), INTERVAL " . ($trend_days - 1) . " DAY) GROUP BY DATE(date_distributed)"), $series_docdist);
    $fill_trend($conn->query("SELECT DATE(date_distributed) d, COUNT(*) c FROM distribution WHERE date_distributed >= DATE_SUB(CURDATE(), INTERVAL " . ($trend_days - 1) . " DAY) GROUP BY DATE(date_distributed)"), $series_newsdist);

    $trends = [
        'dates' => array_map(fn($d) => date('M j', strtotime($d)), $trend_dates),
        'documents' => $series_docs,
        'parcels' => $series_parcels,
        'newspapers' => $series_papers,
        'pickups' => $series_pickups,
        'docdist' => $series_docdist,
        'newsdist' => $series_newsdist,
    ];

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
                    <a href="#" id="dashCustomizeBtn" class="btn btn-soft" title="Customize dashboard">
                        <i class="fa-solid fa-sliders"></i>
                        <span class="hidden sm:inline">Customize</span>
                    </a>
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

                <!-- Dashboard sections (visibility & order editable) -->
                <div id="dashSections" class="dash-sections">

                    <!-- KPI Scorecards -->
                    <section data-dash-section="stats">
                        <div class="stat-grid">
                            <div class="stat-card">
                                <div class="stat-icon green"><i class="fa-solid fa-file-lines"></i></div>
                                <div class="stat-label">Total Documents</div>
                                <div class="stat-value"><?php echo number_format($stats['documents']); ?></div>
                                <div class="stat-delta <?php echo $stats['delta_docs'] >= 0 ? 'up' : 'down'; ?>">
                                    <i class="fa-solid <?php echo $stats['delta_docs'] >= 0 ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down'; ?>"></i>
                                    <?php echo ($stats['delta_docs'] > 0 ? '+' : '') . $stats['delta_docs']; ?>
                                    <span class="stat-delta-ctx">vs yesterday</span>
                                </div>
                                <div class="stat-hint"><?php echo number_format($stats['today_documents']); ?> received today</div>
                                <div class="stat-spark" data-spark="documents"></div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-icon orange"><i class="fa-solid fa-box"></i></div>
                                <div class="stat-label">Parcels Awaiting Pickup</div>
                                <div class="stat-value"><?php echo number_format($stats['pending_parcels']); ?></div>
                                <div class="stat-delta up">
                                    <i class="fa-solid fa-box-open"></i>
                                    <?php echo number_format($stats['picked_today']); ?>
                                    <span class="stat-delta-ctx">picked today</span>
                                </div>
                                <div class="stat-hint"><?php echo number_format($stats['today_parcels']); ?> received today</div>
                                <div class="stat-spark" data-spark="parcels"></div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-icon blue"><i class="fa-solid fa-file-signature"></i></div>
                                <div class="stat-label">Documents Awaiting Distribution</div>
                                <div class="stat-value"><?php echo number_format($stats['awaiting_distribution']); ?></div>
                                <div class="stat-progress"><div class="stat-progress-bar" style="width:<?php echo min(100, max(0, (float)$stats['dist_pct'])); ?>%;"></div></div>
                                <div class="stat-hint"><?php echo (float)$stats['dist_pct']; ?>% of all copies distributed</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-icon gray"><i class="fa-regular fa-newspaper"></i></div>
                                <div class="stat-label">Newspapers Today</div>
                                <div class="stat-value"><?php echo number_format($stats['today_newspapers']); ?></div>
                                <div class="stat-delta <?php echo $stats['delta_week_news'] >= 0 ? 'up' : 'down'; ?>">
                                    <i class="fa-solid <?php echo $stats['delta_week_news'] >= 0 ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down'; ?>"></i>
                                    <?php echo ($stats['delta_week_news'] > 0 ? '+' : '') . $stats['delta_week_news']; ?>
                                    <span class="stat-delta-ctx">vs last week</span>
                                </div>
                                <div class="stat-hint"><?php echo number_format($stats['week_newspapers']); ?> this week</div>
                                <div class="stat-spark" data-spark="newspapers"></div>
                            </div>
                        </div>
                    </section>

                    <!-- Trends & Activity charts -->
                    <section data-dash-section="charts">
                        <div class="card card-unclip">
                            <div class="card-header">
                                <div>
                                    <h2 class="card-title"><i class="fa-solid fa-chart-line" style="margin-right:6px;color:var(--accent);"></i>Trends</h2>
                                    <p class="card-subtitle">Daily intake and processing volumes across the mailroom.</p>
                                </div>
                                <div class="dash-period" id="dashPeriod">
                                    <button type="button" data-period="7">7D</button>
                                    <button type="button" data-period="14" class="active">14D</button>
                                    <button type="button" data-period="30">30D</button>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="dash-chart-grid">
                                    <div class="dash-chart-box">
                                        <div class="dash-chart-title"><i class="fa-solid fa-inbox" style="color:var(--gold);"></i>Incoming Mail</div>
                                        <div class="dash-chart" id="dashChartIntake"></div>
                                        <div class="dash-chart-legend" id="dashLegendIntake"></div>
                                    </div>
                                    <div class="dash-chart-box">
                                        <div class="dash-chart-title"><i class="fa-solid fa-arrows-rotate" style="color:var(--accent);"></i>Processing Activity</div>
                                        <div class="dash-chart" id="dashChartActivity"></div>
                                        <div class="dash-chart-legend" id="dashLegendActivity"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                <!-- Quick actions -->
                <section data-dash-section="quick">
                    <div class="card">
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
                </section>

                <!-- Requires Attention -->
                    <section data-dash-section="attention">
                        <div class="card">
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
                    </section>

                    <!-- Recent Activity -->
                    <section data-dash-section="activity">
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
                    </section>

                <!-- Recent Parcels -->
                <section data-dash-section="parcels">
                    <div class="card">
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
                </section>
                </div>
            </div>
        </main>
    </div>

    <!-- Customize dashboard drawer -->
    <div class="drawer-backdrop" id="dashLayoutBackdrop"></div>
    <div class="drawer" id="dashLayoutDrawer">
        <div class="drawer-header">
            <div>
                <div class="drawer-title"><i class="fa-solid fa-sliders" style="margin-right:8px;color:var(--accent);"></i>Customize Dashboard</div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">Show, hide, or reorder your sections.</div>
            </div>
            <button class="modal-close" data-dash-layout-close title="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="drawer-body">
            <div class="dash-layout-rows" id="dashLayoutRows"></div>
            <button type="button" class="btn btn-soft btn-sm" data-dash-layout-reset style="width:100%;margin-top:6px;">
                <i class="fa-solid fa-rotate-left"></i> Reset to default layout
            </button>
        </div>
        <div class="drawer-footer">
            <button type="button" class="btn btn-primary" data-dash-layout-done><i class="fa-solid fa-check"></i> Done</button>
        </div>
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

    <script>
        (function() {
            /* ── Dashboard trends, charts & sparklines ───────── */
            const DB = window.DASH_TRENDS = <?php echo json_encode($trends); ?>;
            const PALETTE = { documents: '#2e7d52', parcels: '#3a5a9a', newspapers: '#b08a3e', pickups: '#5a5f6e', docdist: '#a9641d', newsdist: '#8b2635' };
            const SERIES_INTAKE = [{ key: 'documents', label: 'Documents' }, { key: 'parcels', label: 'Parcels' }, { key: 'newspapers', label: 'Newspapers' }];
            const SERIES_ACTIVITY = [{ key: 'pickups', label: 'Parcel pickups' }, { key: 'docdist', label: 'Doc distributions' }, { key: 'newsdist', label: 'News distributions' }];
            let curPeriod = 14;

            function buildChart(el, dates, series, legend) {
                if (!el) return;
                el.innerHTML = '';
                const w = Math.max(180, el.clientWidth - 2);
                const h = 190, padL = 34, padR = 8, padT = 10, padB = 20;
                const allMax = Math.max.apply(null, series.map(s => Math.max.apply(null, s.data)));
                const maxV = allMax <= 0 ? 1 : allMax;
                const n = dates.length;
                const X = i => n <= 1 ? padL : padL + (w - padL - padR) * i / (n - 1);
                const Y = v => padT + (h - padT - padB) * (1 - v / maxV);
                const G = 4;
                let svg = '<svg width="' + w + '" height="' + h + '" viewBox="0 0 ' + w + ' ' + h + '" role="img" aria-label="Trend chart">';
                for (let g = 0; g <= G; g++) {
                    const v = Math.round(maxV * g / G);
                    const y = Y(maxV * g / G);
                    svg += '<line x1="' + padL + '" y1="' + y + '" x2="' + (w - padR) + '" y2="' + y + '" stroke="#e0d7c5" stroke-width="1" stroke-dasharray="' + (g === 0 ? '' : '3 3') + '"/>';
                    svg += '<text x="' + (padL - 7) + '" y="' + (y + 3) + '" text-anchor="end" fill="#9aa0b5" font-size="10">' + v + '</text>';
                }
                const xStep = Math.max(1, Math.ceil(n / 5));
                for (let i = 0; i < n; i += xStep) {
                    svg += '<text x="' + X(i) + '" y="' + (h - 6) + '" text-anchor="middle" fill="#9aa0b5" font-size="9.5">' + dates[i] + '</text>';
                }
                if (maxV > 0) {
                    series.forEach(s => {
                        const pts = s.data.map((v, i) => [X(i), Y(v)]);
                        const line = pts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join(' ');
                        const area = line + ' L ' + pts[pts.length - 1][0].toFixed(1) + ' ' + Y(0) + ' L ' + pts[0][0].toFixed(1) + ' ' + Y(0) + ' Z';
                        svg += '<path d="' + area + '" fill="' + s.color + '" opacity="0.10"/>';
                        svg += '<path d="' + line + '" fill="none" stroke="' + s.color + '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';
                    });
                }
                svg += '<rect class="dc-hit" x="' + padL + '" y="' + padT + '" width="' + Math.max(0, w - padL - padR) + '" height="' + Math.max(0, h - padT - padB) + '" fill="transparent"/>';
                svg += '<g class="dc-hover"></g></svg>';
                el.innerHTML = svg;

                if (legend) {
                    legend.innerHTML = series.map(s =>
                        '<span><span class="lg-dot" style="background:' + s.color + '"></span>' + s.label +
                        ' <b style="color:var(--text-secondary)">' + s.data[n - 1] + '</b></span>').join('');
                }

                const tip = document.createElement('div');
                tip.className = 'dash-chart-tip';
                el.appendChild(tip);
                const hitRect = el.querySelector('.dc-hit');
                const hoverG = el.querySelector('.dc-hover');
                if (!hitRect) return;
                hitRect.addEventListener('mousemove', function(ev) {
                    const r = el.getBoundingClientRect();
                    const px = ev.clientX - r.left - padL;
                    let i = Math.round(px / (w - padL - padR) * (n - 1));
                    i = Math.max(0, Math.min(n - 1, i));
                    let marks = '<line x1="' + X(i) + '" y1="' + padT + '" x2="' + X(i) + '" y2="' + Y(0) + '" stroke="#cdbfa4" stroke-width="1"/>';
                    series.forEach(s => {
                        marks += '<circle cx="' + X(i) + '" cy="' + Y(s.data[i]) + '" r="3.5" fill="' + s.color + '" stroke="#fff" stroke-width="1.5"/>';
                    });
                    hoverG.innerHTML = marks;
                    const rows = series.map(s =>
                        '<div style="display:flex;justify-content:space-between;gap:14px;margin-top:2px;"><span style="color:var(--text-muted)">' + s.label + '</span><b>' + s.data[i] + '</b></div>').join('');
                    tip.innerHTML = '<div style="font-weight:600;color:var(--text);">' + dates[i] + '</div>' + rows;
                    const tipW = Math.max(0, tip.offsetWidth || 140);
                    let left = ev.clientX - r.left + 12;
                    if (left + tipW > w) left = ev.clientX - r.left - tipW - 12;
                    tip.style.left = left + 'px';
                    tip.style.top = '4px';
                    tip.classList.add('show');
                });
                hitRect.addEventListener('mouseleave', function() {
                    hoverG.innerHTML = '';
                    tip.classList.remove('show');
                });
            }

            function renderCharts() {
                if (!document.getElementById('dashChartIntake')) return;
                const start = DB.documents.length - curPeriod;
                const dates = DB.dates.slice(start, DB.documents.length);
                const mk = list => list.map(s => ({ label: s.label, color: PALETTE[s.key], data: DB[s.key].slice(start, DB[s.key].length) }));
                buildChart(document.getElementById('dashChartIntake'), dates, mk(SERIES_INTAKE), document.getElementById('dashLegendIntake'));
                buildChart(document.getElementById('dashChartActivity'), dates, mk(SERIES_ACTIVITY), document.getElementById('dashLegendActivity'));
            }

            const dashPeriod = document.getElementById('dashPeriod');
            if (dashPeriod) {
                dashPeriod.addEventListener('click', function(e) {
                    const b = e.target.closest('button[data-period]');
                    if (!b) return;
                    curPeriod = parseInt(b.dataset.period, 10);
                    this.querySelectorAll('button').forEach(x => x.classList.toggle('active', x === b));
                    renderCharts();
                });
            }

            function sparkHTML(data, color) {
                if (!data || data.length < 2) return '';
                const pad = 1, w = 108, h = 30;
                const maxV = Math.max.apply(null, data), minV = Math.min.apply(null, data);
                const span = (maxV - minV) || 1;
                const pt = v => {
                    const x = pad + (w - 2 * pad) * v / (data.length - 1);
                    const y = h - pad - ((data[v] - minV) / span) * (h - 2 * pad);
                    return x.toFixed(1) + ',' + y.toFixed(1);
                };
                let pts = [];
                for (let i = 0; i < data.length; i++) pts.push(pt(i));
                const area = pts.join(' ') + ' ' + (w - pad) + ',' + (h - pad) + ' ' + pad + ',' + (h - pad);
                return '<svg width="' + w + '" height="' + h + '" viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none">' +
                    '<polygon points="' + area + '" fill="' + color + '" opacity="0.12"/>' +
                    '<polyline points="' + pts.join(' ') + '" fill="none" stroke="' + color + '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/></svg>';
            }

            function renderSparklines() {
                const map = { documents: '#2e7d52', parcels: '#3a5a9a', newspapers: '#b08a3e' };
                document.querySelectorAll('.stat-spark[data-spark]').forEach(el => {
                    const key = el.dataset.spark;
                    const vals = DB[key] ? DB[key].slice(DB[key].length - 14) : [];
                    el.innerHTML = sparkHTML(vals, map[key] || '#b08a3e');
                });
            }

            /* ── Customizable layout ─────────────────────────── */
            const DL_KEY = 'mr_dash_layout';
            const DL_DEFAULT = ['stats', 'charts', 'quick', 'attention', 'activity', 'parcels'];
            const DL_META = {
                stats: { label: 'KPI Scorecards', icon: 'fa-chart-simple' },
                charts: { label: 'Trends', icon: 'fa-chart-line' },
                quick: { label: 'Quick Actions', icon: 'fa-bolt' },
                attention: { label: 'Requires Attention', icon: 'fa-triangle-exclamation' },
                activity: { label: 'Recent Activity', icon: 'fa-clock-rotate-left' },
                parcels: { label: 'Recent Parcels', icon: 'fa-box' }
            };
            const DL = {
                get() {
                    const def = { order: DL_DEFAULT.slice(), hidden: [] };
                    try {
                        const raw = JSON.parse(localStorage.getItem(DL_KEY) || 'null');
                        if (raw && Array.isArray(raw.order)) {
                            const o = raw.order.filter(id => DL_META[id]);
                            const missing = DL_DEFAULT.filter(id => !o.includes(id));
                            return { order: [...o, ...missing], hidden: (raw.hidden || []).filter(id => DL_META[id]) };
                        }
                    } catch (e) {}
                    return def;
                },
                set(s) { localStorage.setItem(DL_KEY, JSON.stringify({ order: s.order, hidden: s.hidden })); },
                apply() {
                    const s = this.get();
                    const container = document.getElementById('dashSections');
                    if (!container) return;
                    const nodes = {};
                    container.querySelectorAll('[data-dash-section]').forEach(el => { nodes[el.dataset.dashSection] = el; });
                    container.innerHTML = '';
                    const visible = s.order.filter(id => !s.hidden.includes(id));
                    visible.forEach(id => { if (nodes[id]) container.appendChild(nodes[id]); });
                    this.wrapPair(container);
                    renderCharts();
                    renderSparklines();
                },
                wrapPair(container) {
                    const kids = Array.from(container.children);
                    const iA = kids.findIndex(k => k.dataset && k.dataset.dashSection === 'attention');
                    const iB = kids.findIndex(k => k.dataset && k.dataset.dashSection === 'activity');
                    if (iA < 0 || iB < 0 || Math.abs(iA - iB) !== 1) return;
                    const low = Math.min(iA, iB), high = Math.max(iA, iB);
                    let a = kids[low], b = kids[high];
                    if (a.dataset.dashSection === 'activity') { const t = a; a = b; b = t; }
                    const row = document.createElement('div');
                    row.className = 'dash-row-2col';
                    a.classList.add('dash-span-2');
                    b.classList.add('dash-span-1');
                    row.appendChild(a);
                    row.appendChild(b);
                    container.insertBefore(row, container.children[low]);
                }
            };

            function buildRows() {
                const s = DL.get();
                const rows = document.getElementById('dashLayoutRows');
                if (!rows) return;
                rows.innerHTML = s.order.map(id =>
                    '<div class="dash-layout-row" data-id="' + id + '">' +
                    '<span class="dash-layout-icon"><i class="fa-solid ' + DL_META[id].icon + '"></i></span>' +
                    '<span class="dash-layout-name">' + DL_META[id].label + '</span>' +
                    '<button type="button" class="dash-layout-arrow" data-act="up" title="Move up" ' + (s.order.indexOf(id) === 0 ? 'disabled' : '') + '><i class="fa-solid fa-chevron-up"></i></button>' +
                    '<button type="button" class="dash-layout-arrow" data-act="down" title="Move down" ' + (s.order.indexOf(id) === s.order.length - 1 ? 'disabled' : '') + '><i class="fa-solid fa-chevron-down"></i></button>' +
                    '<label class="switch"><input type="checkbox" ' + (s.hidden.includes(id) ? '' : 'checked') + ' data-act="toggle"><span class="switch-slider"></span></label>' +
                    '</div>').join('');
                rows.querySelectorAll('.dash-layout-row').forEach(row => {
                    const id = row.dataset.id;
                    row.querySelector('[data-act="up"]').addEventListener('click', () => moveSection(id, -1));
                    row.querySelector('[data-act="down"]').addEventListener('click', () => moveSection(id, 1));
                    row.querySelector('[data-act="toggle"]').addEventListener('change', e => toggleSection(id, e.target.checked));
                });
            }

            function moveSection(id, step) {
                const s = DL.get();
                const i = s.order.indexOf(id);
                const ni = i + step;
                if (ni < 0 || ni >= s.order.length) return;
                [s.order[i], s.order[ni]] = [s.order[ni], s.order[i]];
                DL.set(s);
                DL.apply();
                buildRows();
            }

            function toggleSection(id, visible) {
                const s = DL.get();
                const shown = s.order.filter(x => !s.hidden.includes(x)).length;
                if (!visible) {
                    if (shown <= 1) return;
                    if (!s.hidden.includes(id)) s.hidden.push(id);
                } else {
                    s.hidden = s.hidden.filter(h => h !== id);
                }
                DL.set(s);
                DL.apply();
                buildRows();
            }

            function resetLayout() {
                localStorage.removeItem(DL_KEY);
                DL.apply();
                buildRows();
            }

            const customBtn = document.getElementById('dashCustomizeBtn');
            if (customBtn) customBtn.addEventListener('click', function(e) { e.preventDefault(); buildRows(); MailroomDrawer.open('dashLayoutDrawer'); });
            const layoutClose = document.querySelector('[data-dash-layout-close]');
            if (layoutClose) layoutClose.addEventListener('click', () => MailroomDrawer.close('dashLayoutDrawer'));
            const layoutDone = document.querySelector('[data-dash-layout-done]');
            if (layoutDone) layoutDone.addEventListener('click', () => MailroomDrawer.close('dashLayoutDrawer'));
            const layoutReset = document.querySelector('[data-dash-layout-reset]');
            if (layoutReset) layoutReset.addEventListener('click', resetLayout);

            let resizeT;
            window.addEventListener('resize', function() {
                clearTimeout(resizeT);
                resizeT = setTimeout(function() { renderCharts(); renderSparklines(); }, 150);
            });

            DL.apply();
        })();
    </script>
</body>

</html>