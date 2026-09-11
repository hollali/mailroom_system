<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';

$tracking_id = trim($_GET['tracking'] ?? ($_POST['tracking'] ?? ''));
$parcel = null;
$pickup = null;
$not_found = false;

if ($tracking_id !== '') {
    $stmt = $conn->prepare("
        SELECT pr.*, pp.picked_by, pp.phone_number, pp.designation, pp.date_picked, pp.picked_at
        FROM parcels_received pr
        LEFT JOIN parcels_pickup pp ON pr.id = pp.parcel_id
        WHERE pr.tracking_id = ?
    ");
    $stmt->bind_param("s", $tracking_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $parcel = $result->fetch_assoc();
    } else {
        $not_found = true;
    }
    $stmt->close();
}

// Compute lifecycle timeline steps
$timeline_steps = [
    ['received', 'Received', 'Parcel logged at the mailroom', 'fa-inbox'],
    ['in_transit', 'In Transit', 'Handed to dispatch / courier', 'fa-truck-moving'],
    ['out_for_delivery', 'Out for Delivery', 'On its way to destination', 'fa-truck-fast'],
    ['delivered', 'Delivered', 'Handed to the recipient', 'fa-circle-check'],
];

$current_status_raw = $parcel['delivery_status'] ?? 'received';
if (!empty($parcel['picked_by'])) {
    $current_status_raw = 'picked';
}
$status_rank = array_search($current_status_raw, array_column(array_merge($timeline_steps, [['picked', 'Picked Up', 'Parcel collected at the mailroom', 'fa-box-open']]), 0));
$status_rank = $status_rank === false ? 0 : $status_rank;
$is_picked = !empty($parcel['picked_by']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $parcel ? htmlspecialchars($parcel['tracking_id']) . ' - ' : ''; ?>Track Parcel - Mailroom Ops</title>
    <link rel="icon" type="image/png" href="./images/logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/app.css">
    <style>
        .track-wrap {
            min-height: 100vh;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            padding: 32px 16px;
            display: flex;
            align-items: flex-start;
            justify-content: center;
        }
        .track-card {
            width: 100%;
            max-width: 640px;
            background: var(--surface, #fff);
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .track-header {
            background: #8b2635;
            color: #fff;
            padding: 24px 28px;
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .track-header .logo {
            width: 40px;
            height: 40px;
            border-radius: 8px;
        }
        .track-header h1 { font-size: 18px; font-weight: 700; margin: 0; }
        .track-header p { font-size: 12px; opacity: .85; margin: 2px 0 0; }
        .track-body { padding: 28px; }
        .track-search {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
        }
        .track-search input {
            flex: 1;
            padding: 10px 14px;
            border: 1px solid var(--border, #e2e5ea);
            border-radius: 8px;
            font-size: 14px;
            text-transform: uppercase;
            font-family: ui-monospace, monospace;
        }
        .track-summary {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--bg, #f8f9fb);
            border: 1px solid var(--border, #e2e5ea);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 10px;
        }
        .track-summary .tracking { font-family: ui-monospace, monospace; font-size: 15px; font-weight: 700; color: var(--text, #1a1a2e); }
        .track-summary .label { font-size: 11px; color: var(--text-faint, #9aa0b5); text-transform: uppercase; letter-spacing: .04em; }
        .step {
            display: flex;
            gap: 14px;
            position: relative;
            padding-bottom: 26px;
        }
        .step:last-child { padding-bottom: 0; }
        .step::before {
            content: '';
            position: absolute;
            left: 17px;
            top: 36px;
            bottom: -2px;
            width: 2px;
            background: var(--border, #e2e5ea);
        }
        .step:last-child::before { display: none; }
        .step.done::before { background: var(--green, #16a34a); }
        .step-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
            background: var(--gray-soft, #f0ece2);
            color: var(--text-faint, #9aa0b5);
            border: 2px solid transparent;
            z-index: 1;
        }
        .step.done .step-icon { background: var(--green-soft, #dcfce7); color: var(--green, #16a34a); }
        .step.current .step-icon { border-color: var(--accent, #8b2635); color: var(--accent, #8b2635); background: var(--accent-soft, #fdf2f4); }
        .step-title { font-size: 13px; font-weight: 600; color: var(--text-faint, #9aa0b5); }
        .step.done .step-title, .step.current .step-title { color: var(--text, #1a1a2e); }
        .step-detail { font-size: 12px; color: var(--text-faint, #9aa0b5); margin-top: 2px; }
        .badge-lg { display: inline-flex; align-items: center; gap: 6px; }
        .not-found {
            text-align: center;
            padding: 24px;
        }
        .not-found .big-icon { font-size: 40px; color: var(--text-faint, #9aa0b5); margin-bottom: 14px; }
    </style>
</head>

<body>
    <div class="track-wrap">
        <div class="track-card">
            <div class="track-header">
                <img src="images/logo.png" class="logo" alt="Mailroom">
                <div>
                    <h1>Parcel Tracking</h1>
                    <p>Mailroom Operations</p>
                </div>
                <a href="<?php echo isset($_SERVER['HTTP_REFERER']) ? htmlspecialchars($_SERVER['HTTP_REFERER']) : 'index.php'; ?>"
                   style="margin-left:auto;color:#fff;opacity:.85;font-size:13px;text-decoration:none;">
                    <i class="fa-solid fa-xmark"></i>
                </a>
            </div>

            <div class="track-body">
                <form method="POST" class="track-search">
                    <input type="text" name="tracking" value="<?php echo htmlspecialchars($tracking_id); ?>"
                           placeholder="Enter tracking ID e.g. PRCL-20260401-A1B2C3" required autofocus>
                    <button type="submit" class="btn btn-primary" style="white-space:nowrap;">
                        <i class="fa-solid fa-magnifying-glass"></i> Track
                    </button>
                </form>

                <?php if ($not_found): ?>
                    <div class="not-found">
                        <div class="big-icon"><i class="fa-solid fa-box-open"></i></div>
                        <div style="font-size:16px;font-weight:600;color:var(--text);">Parcel Not Found</div>
                        <p style="font-size:13px;color:var(--text-faint);margin-top:6px;">
                            No parcel matches tracking ID <strong class="font-mono"><?php echo htmlspecialchars($tracking_id); ?></strong>.
                            Double-check the ID and try again.
                        </p>
                    </div>

                <?php elseif ($parcel): ?>
                    <div class="track-summary">
                        <div>
                            <div class="label">Tracking ID</div>
                            <div class="tracking"><?php echo htmlspecialchars($parcel['tracking_id']); ?></div>
                        </div>
                        <div>
                            <div class="label">Status</div>
                            <div class="mt-1"><?php echo parcelStatusBadge($current_status_raw, $is_picked); ?></div>
                        </div>
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:24px;">
                        <div>
                            <div class="label" style="font-size:11px;color:var(--text-faint);text-transform:uppercase;letter-spacing:.04em;">Description</div>
                            <div style="font-size:13px;color:var(--text);margin-top:2px;"><?php echo htmlspecialchars($parcel['description'] ?: '—'); ?></div>
                        </div>
                        <div>
                            <div class="label" style="font-size:11px;color:var(--text-faint);text-transform:uppercase;letter-spacing:.04em;">Received</div>
                            <div style="font-size:13px;color:var(--text);margin-top:2px;"><?php echo date('M j, Y', strtotime($parcel['date_received'])); ?></div>
                        </div>
                        <div>
                            <div class="label" style="font-size:11px;color:var(--text-faint);text-transform:uppercase;letter-spacing:.04em;">Sender</div>
                            <div style="font-size:13px;color:var(--text);margin-top:2px;"><?php echo htmlspecialchars($parcel['sender'] ?: '—'); ?></div>
                        </div>
                        <div>
                            <div class="label" style="font-size:11px;color:var(--text-faint);text-transform:uppercase;letter-spacing:.04em;">Addressed To</div>
                            <div style="font-size:13px;color:var(--text);margin-top:2px;"><?php echo htmlspecialchars($parcel['addressed_to'] ?: '—'); ?></div>
                        </div>
                    </div>

                    <div style="border-top:1px solid var(--border,#e2e5ea);padding-top:22px;">
                        <div class="label" style="font-size:11px;color:var(--text-faint);text-transform:uppercase;letter-spacing:.04em;margin-bottom:18px;">Tracking Timeline</div>

                        <?php if ($is_picked): ?>
                            <div class="step done">
                                <div class="step-icon"><i class="fa-solid <?php echo $status_rank === 4 ? 'fa-box-open' : 'fa-check'; ?>"></i></div>
                                <div>
                                    <div class="step-title">Picked Up</div>
                                    <div class="step-detail"><?php echo htmlspecialchars($parcel['picked_by']); ?> collected on <?php echo date('M j, Y \a\t g:i A', strtotime($parcel['picked_at'] ?? $parcel['date_picked'])); ?></div>
                                    <div class="step-detail" style="margin-top:4px;">
                                        <?php if ($parcel['phone_number']): ?><span class="badge badge-blue"><i class="fa-solid fa-phone"></i> <?php echo htmlspecialchars($parcel['phone_number']); ?></span><?php endif; ?>
                                        <?php if ($parcel['designation']): ?><span class="badge badge-gray"><?php echo htmlspecialchars($parcel['designation']); ?></span><?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php foreach (array_reverse($timeline_steps) as $idx => $step): ?>
                            <?php
                            $step_rank = count($timeline_steps) - 1 - $idx;
                            $state = 'pending';
                            if ($step_rank < $status_rank) $state = 'done';
                            elseif ($step_rank == $status_rank) $state = 'current';
                            ?>
                            <div class="step <?php echo $state !== 'pending' ? $state : ''; ?>">
                                <div class="step-icon"><i class="fa-solid <?php echo $state === 'done' ? 'fa-check' : $step[3]; ?>"></i></div>
                                <div>
                                    <div class="step-title"><?php echo $step[1]; ?></div>
                                    <div class="step-detail">
                                        <?php echo $state === 'done' ? 'Completed' : ($state === 'current' ? 'Current status' : 'Awaiting'); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <div class="step done">
                            <div class="step-icon"><i class="fa-solid fa-inbox"></i></div>
                            <div>
                                <div class="step-title">Received</div>
                                <div class="step-detail">Logged by Mailroom on <?php echo date('M j, Y', strtotime($parcel['date_received'])) . ($parcel['received_by'] ? ' — ' . htmlspecialchars($parcel['received_by']) : ''); ?></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>

</html>