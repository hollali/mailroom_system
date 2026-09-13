<?php
// receipt.php - Printable receipts/slips for parcel pickup, document distribution, newspaper distribution
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';

$type = $_GET['type'] ?? 'parcel';
$id = (int)($_GET['id'] ?? 0);
$error = '';
$data = null;

switch ($type) {
    case 'parcel':
        $stmt = $conn->prepare("
            SELECT pr.*, pp.picked_by, pp.phone_number, pp.designation, pp.date_picked, pp.picked_at
            FROM parcels_received pr
            LEFT JOIN parcels_pickup pp ON pr.id = pp.parcel_id
            WHERE pr.id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        break;

    case 'docdist':
        $stmt = $conn->prepare("
            SELECT dd.*, d.document_name, d.origin, dt.type_name
            FROM document_distribution dd
            JOIN documents d ON dd.document_id = d.id
            LEFT JOIN document_types dt ON d.type_id = dt.id
            WHERE dd.id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        break;

    case 'newsdist':
        $stmt = $conn->prepare("SELECT * FROM distribution WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        break;

    default:
        $error = 'Unknown receipt type.';
}

if ($data === null && $error === '') {
    $error = 'Record not found.';
}

$title = match ($type) {
    'parcel' => 'Parcel Receipt',
    'docdist' => 'Document Distribution Slip',
    'newsdist' => 'Newspaper Distribution Slip',
    default => 'Receipt'
};
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $title; ?> - Mailroom Ops</title>
    <link rel="icon" type="image/png" href="./images/logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/app.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; background: #f0f0f2; font-family: 'Segoe UI', Arial, sans-serif; }
        .print-bar {
            position: sticky; top: 0; z-index: 50;
            display: flex; align-items: center; justify-content: space-between;
            padding: 12px 20px; background: #1b2a4a; color: #fff;
        }
        .print-bar .brand { display: flex; align-items: center; gap: 10px; font-weight: 600; }
        .print-bar img { width: 28px; height: 28px; border-radius: 6px; }
        .print-bar .actions { display: flex; gap: 8px; }
        .print-bar button, .print-bar a.btn {
            padding: 8px 14px; border-radius: 6px; border: none; cursor: pointer;
            font-size: 13px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-print { background: #8b2635; color: #fff; }
        .btn-back { background: rgba(255,255,255,.12); color: #fff; }

        .receipt-wrap {
            max-width: 480px; margin: 24px auto; background: #fff;
            border: 1px solid #ddd; border-radius: 4px; overflow: hidden;
        }
        .receipt-header {
            text-align: center; padding: 28px 24px 18px;
            border-bottom: 2px dashed #ccc;
        }
        .receipt-header img { width: 52px; height: 52px; border-radius: 10px; }
        .receipt-header h1 { font-size: 20px; margin: 10px 0 2px; color: #1b2a4a; }
        .receipt-header .org { font-size: 12px; color: #777; margin: 0; }
        .receipt-header .doc-title { font-size: 13px; font-weight: 600; color: #8b2635; margin-top: 10px; letter-spacing: .05em; text-transform: uppercase; }

        .receipt-body { padding: 24px; }
        .receipt-meta { display: flex; justify-content: space-between; font-size: 11px; color: #888; margin-bottom: 18px; }
        .row {
            display: flex; justify-content: space-between; gap: 16px;
            padding: 9px 0; border-bottom: 1px dotted #ddd; font-size: 13px;
        }
        .row .k { color: #888; flex-shrink: 0; }
        .row .v { color: #1b2a4a; font-weight: 600; text-align: right; }
        .row.total { border-bottom: none; border-top: 2px solid #1b2a4a; margin-top: 6px; padding-top: 12px; }
        .row.total .v { font-size: 15px; }

        .receipt-stamp {
            border: 2px solid #8b2635; color: #8b2635; border-radius: 6px;
            padding: 6px 12px; font-weight: 700; font-size: 13px;
            text-transform: uppercase; letter-spacing: .08em; text-align: center;
            margin-top: 18px; display: inline-block;
        }

        .receipt-footer {
            padding: 18px 24px 28px; text-align: center;
            border-top: 2px dashed #ccc; font-size: 11px; color: #999; line-height: 1.7;
        }
        .receipt-footer .sig-line {
            display: flex; justify-content: space-between; margin-top: 34px; font-size: 12px; color: #555;
        }
        .receipt-footer .sig-line .sig { border-top: 1px solid #888; padding-top: 5px; width: 45%; text-align: center; }

        .no-print { -webkit-print-color-adjust: exact; }
        .hidden { display: none; }
        @media print {
            body { background: #fff; }
            .print-bar { display: none; }
            .receipt-wrap { margin: 0 auto; border: none; max-width: 100%; }
            .receipt-header, .receipt-footer { border-color: #999; }
        }
    </style>
</head>

<body>
    <div class="print-bar no-print">
        <div class="brand">
            <img src="images/logo.png" alt="Mailroom">
            Mailroom Ops — <?php echo $title; ?>
        </div>
        <div class="actions">
            <a href="javascript:history.back()" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Back</a>
            <button class="btn-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
        </div>
    </div>

    <?php if ($error && !$data): ?>
        <div class="receipt-wrap" style="padding:40px;text-align:center;color:#555;">
            <i class="fa-regular fa-square-xmark" style="font-size:36px;color:#dc2626;"></i>
            <p style="margin-top:12px;"><?php echo htmlspecialchars($error); ?></p>
            <a href="index.php" class="btn-print" style="background:#8b2635;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;display:inline-block;margin-top:10px;">Back to Dashboard</a>
        </div>
    <?php else: ?>

    <div class="receipt-wrap">
        <div class="receipt-header">
            <img src="images/logo.png" alt="Logo">
            <h1>Mailroom Operations</h1>
            <p class="org">Library &amp; Office Mail Management</p>
            <div class="doc-title"><?php echo $title; ?></div>
        </div>

        <div class="receipt-body">
            <div class="receipt-meta">
                <span>Date: <?php echo date('M j, Y'); ?></span>
                <span>Ref: <?php echo strtoupper($type) . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT); ?></span>
            </div>

            <?php if ($type === 'parcel'): ?>
                <?php $is_picked = !empty($data['picked_by']); ?>
                <div class="row"><span class="k">Tracking ID</span><span class="v" style="font-family:ui-monospace,monospace;"><?php echo htmlspecialchars($data['tracking_id']); ?></span></div>
                <div class="row"><span class="k">Status</span>
                    <span class="v">
                        <?php echo strip_tags(parcelStatusBadge($data['delivery_status'] ?? 'received', $is_picked)); ?>
                    </span>
                </div>
                <div class="row"><span class="k">Description</span><span class="v"><?php echo htmlspecialchars($data['description'] ?: '—'); ?></span></div>
                <div class="row"><span class="k">Sender</span><span class="v"><?php echo htmlspecialchars($data['sender'] ?: '—'); ?></span></div>
                <div class="row"><span class="k">Addressed To</span><span class="v"><?php echo htmlspecialchars($data['addressed_to'] ?: '—'); ?></span></div>
                <div class="row"><span class="k">Date Received</span><span class="v"><?php echo date('M j, Y', strtotime($data['date_received'])); ?></span></div>
                <div class="row"><span class="k">Received By</span><span class="v"><?php echo htmlspecialchars($data['received_by'] ?: '—'); ?></span></div>
                <?php if ($is_picked): ?>
                    <div class="row"><span class="k">Picked By</span><span class="v"><?php echo htmlspecialchars($data['picked_by']); ?></span></div>
                    <div class="row"><span class="k">Phone</span><span class="v"><?php echo htmlspecialchars($data['phone_number'] ?: '—'); ?></span></div>
                    <div class="row"><span class="k">Designation</span><span class="v"><?php echo htmlspecialchars($data['designation'] ?: '—'); ?></span></div>
                    <div class="row"><span class="k">Date Picked</span><span class="v"><?php echo formatTimestampDisplay($data['picked_at'] ?? $data['date_picked']); ?></span></div>
                <?php endif; ?>

            <?php elseif ($type === 'docdist'): ?>
                <div class="row"><span class="k">Reference</span><span class="v" style="font-family:ui-monospace,monospace;"><?php echo generateDocDistRef($data['id'], $data['date_distributed']); ?></span></div>
                <div class="row"><span class="k">Document</span><span class="v"><?php echo htmlspecialchars($data['document_name']); ?></span></div>
                <div class="row"><span class="k">Type</span><span class="v"><?php echo htmlspecialchars($data['type_name'] ?: '—'); ?></span></div>
                <div class="row"><span class="k">Origin</span><span class="v"><?php echo htmlspecialchars($data['origin'] ?: '—'); ?></span></div>
                <div class="row"><span class="k">Date Distributed</span><span class="v"><?php echo date('M j, Y', strtotime($data['date_distributed'])); ?></span></div>
                <div class="row"><span class="k">Copies on Hand</span><span class="v"><?php echo htmlspecialchars($data['number_received']); ?></span></div>
                <div class="row total"><span class="k">Copies Distributed</span><span class="v"><?php echo htmlspecialchars($data['number_distributed']); ?></span></div>

            <?php elseif ($type === 'newsdist'): ?>
                <div class="row"><span class="k">Reference</span><span class="v" style="font-family:ui-monospace,monospace;"><?php echo generateDistributionReference($data['id'], $data['date_distributed']); ?></span></div>
                <div class="row"><span class="k">Distributed To</span><span class="v"><?php echo htmlspecialchars($data['distributed_to']); ?></span></div>
                <div class="row"><span class="k">Department</span><span class="v"><?php echo htmlspecialchars($data['department'] ?: '—'); ?></span></div>
                <div class="row"><span class="k">Date</span><span class="v"><?php echo date('M j, Y', strtotime($data['date_distributed'])); ?></span></div>
                <?php if (!empty($data['newspapers_list'])): ?>
                    <div class="row"><span class="k">Newspapers</span><span class="v" style="font-size:12px;text-align:right;"><?php echo nl2br(htmlspecialchars(str_replace('|', "\n", $data['newspapers_list']))); ?></span></div>
                <?php elseif (!empty($data['categories_list'])): ?>
                    <div class="row"><span class="k">Categories</span><span class="v"><?php echo htmlspecialchars($data['categories_list']); ?></span></div>
                <?php endif; ?>
                <div class="row total"><span class="k">Copies</span><span class="v"><?php echo htmlspecialchars($data['copies']); ?></span></div>
            <?php endif; ?>

            <div style="text-align:center;">
                <span class="receipt-stamp"><i class="fa-solid fa-stamp"></i> Official Receipt</span>
            </div>
        </div>

        <div class="receipt-footer">
            <p style="margin:0;">This is a system-generated receipt. For verification, contact the Mailroom Operations office.</p>
            <div class="sig-line">
                <div class="sig">Mailroom Staff Signature</div>
                <div class="sig">Authorized Signature</div>
            </div>
        </div>
    </div>

    <?php endif; ?>
</body>

</html>