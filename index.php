<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/db.php';
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

// Check connection
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

// Get statistics with error handling
try {
    // Documents count
    $result = $conn->query("SELECT COUNT(*) as total FROM documents");
    if ($result) {
        $stats['documents'] = $result->fetch_assoc()['total'];
    }

    // Document types count
    $result = $conn->query("SELECT COUNT(*) as total FROM document_types");
    if ($result) {
        $stats['document_types'] = $result->fetch_assoc()['total'];
    }

    // Parcels received
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

    // Total pickups
    $result = $conn->query("SELECT COUNT(*) as total FROM parcels_pickup");
    if ($result) {
        $stats['total_pickups'] = $result->fetch_assoc()['total'];
    }

    // Newspapers count
    $result = $conn->query("SELECT COUNT(*) as total FROM newspapers");
    if ($result) {
        $stats['newspapers'] = $result->fetch_assoc()['total'];
    }

    // Newspaper categories count
    $result = $conn->query("SELECT COUNT(*) as total FROM newspaper_categories");
    if ($result) {
        $stats['newspaper_categories'] = $result->fetch_assoc()['total'];
    }

    // Document distributions
    $result = $conn->query("SELECT COUNT(*) as total FROM document_distribution");
    if ($result) {
        $stats['total_distributions'] = $result->fetch_assoc()['total'];
    }

    // Total copies received
    $result = $conn->query("SELECT SUM(copies_received) as total FROM documents");
    if ($result) {
        $stats['total_copies_received'] = $result->fetch_assoc()['total'] ?? 0;
    }

    // Total copies distributed
    $result = $conn->query("SELECT SUM(number_distributed) as total FROM document_distribution");
    if ($result) {
        $stats['total_copies_distributed'] = $result->fetch_assoc()['total'] ?? 0;
    }

    // Today's dates
    $today = date('Y-m-d');
    $week_start = date('Y-m-d', strtotime('monday this week'));
    $month_start = date('Y-m-01');

    // Today's documents
    $result = $conn->query("SELECT COUNT(*) as total FROM documents WHERE date_received = '$today'");
    if ($result) {
        $stats['today_documents'] = $result->fetch_assoc()['total'];
    }

    // This week's documents
    $result = $conn->query("SELECT COUNT(*) as total FROM documents WHERE date_received >= '$week_start'");
    if ($result) {
        $stats['week_documents'] = $result->fetch_assoc()['total'];
    }

    // This month's documents
    $result = $conn->query("SELECT COUNT(*) as total FROM documents WHERE date_received >= '$month_start'");
    if ($result) {
        $stats['month_documents'] = $result->fetch_assoc()['total'];
    }

    // Today's parcels
    $result = $conn->query("SELECT COUNT(*) as total FROM parcels_received WHERE DATE(date_received) = '$today'");
    if ($result) {
        $stats['today_parcels'] = $result->fetch_assoc()['total'];
    }

    // This week's parcels
    $result = $conn->query("SELECT COUNT(*) as total FROM parcels_received WHERE date_received >= '$week_start'");
    if ($result) {
        $stats['week_parcels'] = $result->fetch_assoc()['total'];
    }

    // This month's parcels
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

    // Today's newspapers
    $result = $conn->query("SELECT COUNT(*) as total FROM newspapers WHERE date_received = '$today'");
    if ($result) {
        $stats['today_newspapers'] = $result->fetch_assoc()['total'];
    }

    // This week's newspapers
    $result = $conn->query("SELECT COUNT(*) as total FROM newspapers WHERE date_received >= '$week_start'");
    if ($result) {
        $stats['week_newspapers'] = $result->fetch_assoc()['total'];
    }

    // This month's newspapers
    $result = $conn->query("SELECT COUNT(*) as total FROM newspapers WHERE date_received >= '$month_start'");
    if ($result) {
        $stats['month_newspapers'] = $result->fetch_assoc()['total'];
    }

    // Recent activities - combine all recent items
    $recent_activities = [];

    // Get recent documents
    $doc_result = $conn->query("
        SELECT 'document' as type, document_name as title, date_received as date,
               CONCAT('Received: ', copies_received, ' copies') as details
        FROM documents 
        ORDER BY date_received DESC 
        LIMIT 5
    ");
    if ($doc_result) {
        while ($row = $doc_result->fetch_assoc()) {
            $recent_activities[] = $row;
        }
    }

    // Get recent parcels
    $parcel_result = $conn->query("
        SELECT 'parcel' as type, 
               CONCAT(pr.tracking_id, ' - ', LEFT(pr.description, 30)) as title, 
               pr.date_received as date,
               CASE WHEN pp.id IS NULL THEN 'Pending' ELSE 'Picked up' END as status,
               CONCAT('From: ', pr.sender) as details
        FROM parcels_received pr 
        LEFT JOIN parcels_pickup pp ON pr.id = pp.parcel_id 
        ORDER BY pr.date_received DESC 
        LIMIT 5
    ");
    if ($parcel_result) {
        while ($row = $parcel_result->fetch_assoc()) {
            $recent_activities[] = $row;
        }
    }

    // Get recent newspapers
    $news_result = $conn->query("
        SELECT 'newspaper' as type, 
               CONCAT(newspaper_name, ' #', newspaper_number) as title, 
               date_received as date,
               CONCAT('Available: ', available_copies, ' copies') as details
        FROM newspapers 
        ORDER BY date_received DESC 
        LIMIT 5
    ");
    if ($news_result) {
        while ($row = $news_result->fetch_assoc()) {
            $recent_activities[] = $row;
        }
    }

    // Get recent distributions
    $dist_result = $conn->query("
        SELECT 'distribution' as type, 
               d.document_name as title, 
               dd.date_distributed as date,
               CONCAT('To: ', dd.department, ' - ', dd.recipient_name, ' (', dd.number_distributed, ' copies)') as details
        FROM document_distribution dd
        JOIN documents d ON dd.document_id = d.id
        ORDER BY dd.date_distributed DESC 
        LIMIT 5
    ");
    if ($dist_result) {
        while ($row = $dist_result->fetch_assoc()) {
            $recent_activities[] = $row;
        }
    }

    // Get recent pickups
    $pickup_result = $conn->query("
        SELECT 'pickup' as type, 
               pr.tracking_id as title, 
               pp.date_picked as date,
               CONCAT('Picked by: ', pp.picked_by) as details
        FROM parcels_pickup pp
        JOIN parcels_received pr ON pp.parcel_id = pr.id
        ORDER BY pp.date_picked DESC 
        LIMIT 5
    ");
    if ($pickup_result) {
        while ($row = $pickup_result->fetch_assoc()) {
            $recent_activities[] = $row;
        }
    }

    // Sort by date (most recent first)
    usort($recent_activities, function ($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });

    // Get recent pickups detailed
    $recent_pickups = $conn->query("
        SELECT pp.*, pr.tracking_id, pr.description, pr.sender
        FROM parcels_pickup pp
        JOIN parcels_received pr ON pp.parcel_id = pr.id
        ORDER BY pp.date_picked DESC 
        LIMIT 5
    ");

    // Dashboard ledger rows
    $dashboard_parcels = $conn->query("
        SELECT pr.id, pr.tracking_id, pr.sender, pr.addressed_to, pr.date_received,
               CASE WHEN pp.id IS NULL THEN 'Pending' ELSE 'Picked Up' END as status
        FROM parcels_received pr
        LEFT JOIN parcels_pickup pp ON pr.id = pp.parcel_id
        ORDER BY pr.date_received DESC, pr.id DESC
        LIMIT 6
    ");
} catch (Exception $e) {
    $error = "Error loading data: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mailroom Dashboard</title>
    <link rel="icon" type="image/png" href="./images/logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
            background: #f5f5f4;
            color: #1c1917;
        }

        .panel {
            background: #ffffff;
            border: 1px solid #e7e5e4;
            border-radius: 28px;
        }

        .panel-header {
            padding: 18px 20px;
            border-bottom: 1px solid #e7e5e4;
        }

        .panel-body {
            padding: 20px;
        }

        .stat-box {
            background: #ffffff;
            border: 1px solid #e7e5e4;
            border-radius: 28px;
            padding: 16px;
        }

        .stat-label {
            font-size: 14px;
            color: #57534e;
            margin-bottom: 6px;
        }

        .stat-value {
            font-size: 28px;
            line-height: 1.1;
            font-weight: 600;
            color: #1c1917;
        }

        .muted {
            color: #78716c;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 500;
            border: 1px solid transparent;
        }

        .status-pending {
            background: #fff7ed;
            color: #9a3412;
            border-color: #fed7aa;
        }

        .status-picked {
            background: #f0fdf4;
            color: #166534;
            border-color: #bbf7d0;
        }

        .simple-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 999px;
            border: 1px solid #d6d3d1;
            background: #ffffff;
            color: #1c1917;
            font-size: 14px;
            font-weight: 500;
        }

        .simple-button:hover {
            background: #fafaf9;
        }

        .primary-button {
            background: #1c1917;
            color: #ffffff;
            border-color: #1c1917;
        }

        .primary-button:hover {
            background: #292524;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            font-size: 13px;
            font-weight: 500;
            color: #57534e;
            padding: 12px 20px;
            background: #fafaf9;
            border-bottom: 1px solid #e7e5e4;
        }

        td {
            padding: 14px 20px;
            border-bottom: 1px solid #f0ece8;
            vertical-align: top;
            font-size: 14px;
        }

        tr:hover td {
            background: #fcfcfb;
        }

        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .activity-item {
            padding-bottom: 16px;
            border-bottom: 1px solid #f0ece8;
        }

        .activity-item:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }

        .circular-panel {
            border-radius: 28px;
            overflow: hidden;
        }

        .circular-panel .panel-header {
            padding: 22px 24px;
        }

        .circular-table-wrap {
            padding: 0 14px 14px;
        }

        .circular-table-wrap table {
            overflow: hidden;
            border: 1px solid #ece7e2;
            border-radius: 22px;
        }

        .circular-table-wrap thead th:first-child {
            border-top-left-radius: 22px;
        }

        .circular-table-wrap thead th:last-child {
            border-top-right-radius: 22px;
        }

        .circular-body {
            padding: 14px;
        }

        .circular-list {
            gap: 12px;
        }

        .circular-list .activity-item {
            border: 1px solid #ece7e2;
            border-radius: 22px;
            padding: 16px 18px;
            background: #fcfcfb;
        }
    </style>
</head>

<body>
    <div class="flex">
        <?php include 'sidebar.php'; ?>

        <main class="flex-1 ml-60 min-h-screen bg-[#f5f5f4]">
            <div class="px-8 py-6 border-b border-[#e7e5e4] bg-white">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 class="text-[28px] font-semibold text-[#1c1917]">Dashboard</h1>
                        <p id="dashboardClock" class="mt-1 text-sm text-[#78716c]" data-server-time="<?php echo htmlspecialchars($stats['dashboard_refreshed_at']); ?>">
                            <?php echo date('l, F j, Y g:i:s A', strtotime($stats['dashboard_refreshed_at'])); ?>
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <a href="parcels.php" class="simple-button">
                            <i class="fa-solid fa-box"></i>
                            View parcels
                        </a>
                        <a href="documents.php" class="simple-button primary-button">
                            <i class="fa-solid fa-file-lines"></i>
                            Open documents
                        </a>
                    </div>
                </div>
            </div>

            <div class="p-8">
                <?php if (isset($error)): ?>
                    <div class="mb-6 rounded-[28px] bg-[#ffdad6] px-5 py-4 text-[#93000a]">
                        <i class="fa-regular fa-circle-exclamation mr-2"></i>
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-8">
                    <div class="stat-box">
                        <div class="stat-label">Documents</div>
                        <div class="stat-value"><?php echo number_format($stats['documents']); ?></div>
                        <div class="mt-2 text-sm muted">Today: <?php echo number_format($stats['today_documents']); ?></div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-label">Parcels received</div>
                        <div class="stat-value"><?php echo number_format($stats['parcels_received']); ?></div>
                        <div class="mt-2 text-sm muted">Pending: <?php echo number_format($stats['pending_parcels']); ?></div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-label">Pickups</div>
                        <div class="stat-value"><?php echo number_format($stats['total_pickups']); ?></div>
                        <div class="mt-2 text-sm muted">This week: <?php echo number_format($stats['week_parcels']); ?></div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-label">Latest parcel update</div>
                        <div class="text-lg font-medium text-[#1c1917]">
                            <?php echo $stats['latest_parcel_received'] ? date('M j, Y', strtotime($stats['latest_parcel_received'])) : 'No record'; ?>
                        </div>
                        <div class="mt-2 text-sm muted">Last pickup: <?php echo $stats['latest_parcel_picked'] ? date('M j, Y', strtotime($stats['latest_parcel_picked'])) : 'No record'; ?></div>
                    </div>
                </div>

                <section class="grid grid-cols-1 xl:grid-cols-3 gap-6">
                    <div class="xl:col-span-2 flex flex-col gap-6">
                        <div class="panel circular-panel">
                            <div class="panel-header flex items-center justify-between">
                                <h2 class="text-lg font-semibold text-[#1c1917]">Recent parcels</h2>
                                <a href="parcels.php" class="text-sm text-[#57534e] hover:text-[#1c1917]">Open all</a>
                            </div>
                            <div class="overflow-x-auto circular-table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Tracking ID</th>
                                            <th>Recipient</th>
                                            <th>Sender</th>
                                            <th>Status</th>
                                            <th>Received</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($dashboard_parcels && $dashboard_parcels->num_rows > 0): ?>
                                            <?php while ($parcel = $dashboard_parcels->fetch_assoc()): ?>
                                                <tr>
                                                    <td class="font-medium text-[#1c1917]"><?php echo htmlspecialchars($parcel['tracking_id']); ?></td>
                                                    <td><?php echo htmlspecialchars($parcel['addressed_to']); ?></td>
                                                    <td class="muted"><?php echo htmlspecialchars($parcel['sender']); ?></td>
                                                    <td>
                                                        <span class="status-badge <?php echo $parcel['status'] === 'Picked Up' ? 'status-picked' : 'status-pending'; ?>">
                                                            <?php echo htmlspecialchars($parcel['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td class="muted"><?php echo date('M j, Y', strtotime($parcel['date_received'])); ?></td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="5" class="text-center muted py-8">No parcel records available.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div id="dashboardParcelsPagination" class="px-6 pb-5 flex flex-wrap items-center justify-between gap-3 <?php echo (!$dashboard_parcels || $dashboard_parcels->num_rows === 0) ? 'hidden' : ''; ?>">
                                <span id="dashboardParcelsPaginationInfo" class="text-xs muted"></span>
                                <div class="flex items-center gap-2" id="dashboardParcelsPaginationControls"></div>
                            </div>
                        </div>

                        <div class="panel circular-panel">
                            <div class="panel-header flex items-center justify-between">
                                <h2 class="text-lg font-semibold text-[#1c1917]">Recent pickups</h2>
                                <span class="text-sm muted"><?php echo number_format($stats['total_pickups']); ?> total</span>
                            </div>
                            <div class="panel-body circular-body">
                                <?php if ($recent_pickups && $recent_pickups->num_rows > 0): ?>
                                    <div class="activity-list circular-list">
                                        <?php while ($pickup = $recent_pickups->fetch_assoc()): ?>
                                            <div class="activity-item">
                                                <div class="flex items-start justify-between gap-4">
                                                    <div>
                                                        <p class="text-sm font-medium text-[#1c1917]"><?php echo htmlspecialchars($pickup['tracking_id']); ?></p>
                                                        <p class="mt-1 text-sm muted"><?php echo htmlspecialchars($pickup['picked_by']); ?></p>
                                                        <p class="mt-1 text-sm muted"><?php echo date('M j, Y', strtotime($pickup['date_picked'])); ?></p>
                                                    </div>
                                                    <span class="status-badge status-picked">Picked up</span>
                                                </div>
                                            </div>
                                        <?php endwhile; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-sm muted">No recent pickups available.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-col gap-6">
                        <div class="panel">
                            <div class="panel-header">
                                <h2 class="text-lg font-semibold text-[#1c1917]">Recent activity</h2>
                            </div>
                            <div class="panel-body">
                                <?php if (!empty($recent_activities)): ?>
                                    <div class="activity-list">
                                        <?php foreach (array_slice($recent_activities, 0, 6) as $activity): ?>
                                            <div class="activity-item">
                                                <p class="text-sm font-medium text-[#1c1917]"><?php echo htmlspecialchars(substr($activity['title'], 0, 52)); ?></p>
                                                <p class="mt-1 text-sm muted"><?php echo htmlspecialchars($activity['details']); ?></p>
                                                <p class="mt-2 text-xs muted"><?php echo ucfirst($activity['type']); ?> • <?php echo date('M j, Y', strtotime($activity['date'])); ?></p>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-sm muted">No recent activities found.</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="panel">
                            <div class="panel-header">
                                <h2 class="text-lg font-semibold text-[#1c1917]">Overview</h2>
                            </div>
                            <div class="panel-body space-y-4">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm muted">Documents this month</span>
                                    <span class="text-sm font-medium text-[#1c1917]"><?php echo number_format($stats['month_documents']); ?></span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-sm muted">Parcels this month</span>
                                    <span class="text-sm font-medium text-[#1c1917]"><?php echo number_format($stats['month_parcels']); ?></span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-sm muted">Pending parcels</span>
                                    <span class="text-sm font-medium text-[#1c1917]"><?php echo number_format($stats['pending_parcels']); ?></span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-sm muted">Total copies received</span>
                                    <span class="text-sm font-medium text-[#1c1917]"><?php echo number_format($stats['total_copies_received']); ?></span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-sm muted">Total copies distributed</span>
                                    <span class="text-sm font-medium text-[#1c1917]"><?php echo number_format($stats['total_copies_distributed']); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </main>
    </div>
    <script>
        (function() {
            const clock = document.getElementById('dashboardClock');

            if (!clock) {
                return;
            }

            const serverTime = clock.dataset.serverTime;
            const baseTime = serverTime ? new Date(serverTime.replace(' ', 'T')) : new Date();

            if (Number.isNaN(baseTime.getTime())) {
                return;
            }

            let currentTime = baseTime;

            function formatDateTime(date) {
                return new Intl.DateTimeFormat('en-US', {
                    weekday: 'long',
                    month: 'long',
                    day: 'numeric',
                    year: 'numeric',
                    hour: 'numeric',
                    minute: '2-digit',
                    second: '2-digit',
                    hour12: true
                }).format(date);
            }

            function renderClock() {
                clock.textContent = formatDateTime(currentTime);
                currentTime = new Date(currentTime.getTime() + 1000);
            }

            renderClock();
            setInterval(renderClock, 1000);
        })();

        (function() {
            const rows = Array.from(document.querySelectorAll('.circular-table-wrap tbody tr'));
                const info = document.getElementById('dashboardParcelsPaginationInfo');
                const controls = document.getElementById('dashboardParcelsPaginationControls');
                const wrapper = document.getElementById('dashboardParcelsPagination');
            const pageSize = 5;
            let currentPage = 1;

            if (!rows.length || !info || !controls || !wrapper) {
                return;
            }

            function render() {
                const totalPages = Math.max(1, Math.ceil(rows.length / pageSize));
                if (currentPage > totalPages) {
                    currentPage = totalPages;
                }

                const startIndex = (currentPage - 1) * pageSize;
                const endIndex = startIndex + pageSize;

                rows.forEach((row, index) => {
                    row.style.display = index >= startIndex && index < endIndex ? '' : 'none';
                });

                const from = startIndex + 1;
                const to = Math.min(endIndex, rows.length);
                info.textContent = `Page ${currentPage} of ${totalPages} • Showing ${from}-${to} of ${rows.length}`;
                wrapper.classList.toggle('hidden', rows.length <= pageSize);
                controls.innerHTML = `
                    <button class="simple-button" ${currentPage === 1 ? 'disabled' : ''}>Prev</button>
                    <button class="simple-button" ${currentPage === totalPages ? 'disabled' : ''}>Next</button>
                `;

                const [prevButton, nextButton] = controls.querySelectorAll('button');
                prevButton.addEventListener('click', function() {
                    currentPage = Math.max(1, currentPage - 1);
                    render();
                });
                nextButton.addEventListener('click', function() {
                    currentPage = Math.min(totalPages, currentPage + 1);
                    render();
                });
            }

            render();
        })();
    </script>
</body>

</html>
