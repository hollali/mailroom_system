<?php
require_once './config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/audit.php';

// Start session for toast messages
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Handle form submission
if (isset($_POST['submit'])) {
    csrf_check_post();
    $document_id = $_POST['document_id'];
    $date_distributed = $_POST['date_distributed'];
    $numbers = $_POST['number_distributed'] ?? [];

    $success_count = 0;
    $error_messages = [];

    // Begin transaction
    $conn->begin_transaction();

    try {
        // First, check if document has enough copies for all distributions
        $total_requested = array_sum($numbers);

        $check_stmt = $conn->prepare("SELECT copies_received, document_name FROM documents WHERE id = ?");
        $check_stmt->bind_param("i", $document_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $document = $check_result->fetch_assoc();
        $check_stmt->close();

        if (!$document) {
            throw new Exception("Document not found");
        }

        if ($document['copies_received'] < $total_requested) {
            throw new Exception("Insufficient copies. Available: " . $document['copies_received'] . ", Requested: " . $total_requested);
        }

        // Process each distribution entry
        for ($i = 0; $i < count($numbers); $i++) {
            $number = (int)$numbers[$i];

            if ($number > 0) {
                // Insert distribution record (without department and recipient name)
                $sql = "INSERT INTO document_distribution 
                        (document_id, number_received, number_distributed, date_distributed, status)
                        VALUES 
                        (?, ?, ?, ?, 'distributed')";

                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }

                $stmt->bind_param("iiis", $document_id, $number, $number, $date_distributed);

                if (!$stmt->execute()) {
                    throw new Exception("Error for entry " . ($i + 1) . ": " . $stmt->error);
                }
                $stmt->close();

                $success_count++;
            }
        }

        // Update document copies after successful distributions
        if ($success_count > 0) {
            $new_copies = $document['copies_received'] - $total_requested;
            $update_stmt = $conn->prepare("UPDATE documents SET copies_received = ? WHERE id = ?");
            $update_stmt->bind_param("ii", $new_copies, $document_id);

            if (!$update_stmt->execute()) {
                throw new Exception("Failed to update document copies: " . $update_stmt->error);
            }
            $update_stmt->close();
        }

        // Commit transaction
        $conn->commit();

        audit_log('distribute', 'document', $document_id, "Distributed $total_requested copies of '" . addslashes($document['document_name']) . "' on $date_distributed", 'System');

        $_SESSION['toast'] = [
            'type' => 'success',
            'message' => "$success_count distribution record(s) saved successfully. " . $total_requested . " copies of \"" . $document['document_name'] . "\" distributed."
        ];
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => "Error: " . $e->getMessage()
        ];
    }

    if ($success_count == 0 && empty($error_messages)) {
        $_SESSION['toast'] = [
            'type' => 'warning',
            'message' => "No valid records to save"
        ];
    }

    header('Location: distribution.php');
    exit();
}

// Handle Withdraw Distribution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['withdraw_distribution'])) {
    csrf_check_post();
    $id = (int)$_POST['withdraw_distribution'];

    // Begin transaction
    $conn->begin_transaction();

    try {
        // Get distribution details before withdrawing
        $get_stmt = $conn->prepare("SELECT document_id, number_distributed FROM document_distribution WHERE id = ?");
        $get_stmt->bind_param("i", $id);
        $get_stmt->execute();
        $result = $get_stmt->get_result();
        $distribution = $result->fetch_assoc();
        $get_stmt->close();

        if ($distribution) {
            // Mark the distribution record as withdrawn instead of deleting it
            $withdraw_stmt = $conn->prepare("UPDATE document_distribution SET status = 'withdrawn' WHERE id = ?");
            $withdraw_stmt->bind_param("i", $id);

            if (!$withdraw_stmt->execute()) {
                throw new Exception("Error withdrawing record: " . $conn->error);
            }
            $withdraw_stmt->close();

            // Restore copies to document
            $update_stmt = $conn->prepare("UPDATE documents SET copies_received = copies_received + ? WHERE id = ?");
            $update_stmt->bind_param("ii", $distribution['number_distributed'], $distribution['document_id']);

            if (!$update_stmt->execute()) {
                throw new Exception("Error restoring copies: " . $conn->error);
            }
            $update_stmt->close();

            $conn->commit();
            $_SESSION['toast'] = [
                'type' => 'success',
                'message' => "Distribution withdrawn and copies restored successfully!"
            ];
        } else {
            throw new Exception("Distribution record not found");
        }
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => "Error: " . $e->getMessage()
        ];
    }

    // Preserve any query parameters
    $query_params = $_GET;
    unset($query_params['withdraw_distribution']);
    $redirect_url = 'distribution.php' . (!empty($query_params) ? '?' . http_build_query($query_params) : '');
    header('Location: ' . $redirect_url);
    exit();
}

// Handle Delete Distribution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_distribution'])) {
    csrf_check_post();
    $id = (int)$_POST['delete_distribution'];

    // Begin transaction
    $conn->begin_transaction();

    try {
        // Get distribution details before deleting
        $get_stmt = $conn->prepare("SELECT document_id, number_distributed FROM document_distribution WHERE id = ?");
        $get_stmt->bind_param("i", $id);
        $get_stmt->execute();
        $result = $get_stmt->get_result();
        $distribution = $result->fetch_assoc();
        $get_stmt->close();

        if ($distribution) {
            // Delete the distribution record
            $delete_stmt = $conn->prepare("DELETE FROM document_distribution WHERE id = ?");
            $delete_stmt->bind_param("i", $id);

            if (!$delete_stmt->execute()) {
                throw new Exception("Error deleting record: " . $conn->error);
            }
            $delete_stmt->close();

            // Restore copies to document
            $update_stmt = $conn->prepare("UPDATE documents SET copies_received = copies_received + ? WHERE id = ?");
            $update_stmt->bind_param("ii", $distribution['number_distributed'], $distribution['document_id']);

            if (!$update_stmt->execute()) {
                throw new Exception("Error restoring copies: " . $conn->error);
            }
            $update_stmt->close();

            $conn->commit();
            $_SESSION['toast'] = [
                'type' => 'success',
                'message' => "Distribution record deleted and copies restored successfully!"
            ];
        } else {
            throw new Exception("Distribution record not found");
        }
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => "Error: " . $e->getMessage()
        ];
    }

    // Preserve any query parameters
    $query_params = $_GET;
    unset($query_params['delete_distribution']);
    $redirect_url = 'distribution.php' . (!empty($query_params) ? '?' . http_build_query($query_params) : '');
    header('Location: ' . $redirect_url);
    exit();
}

// Get all documents with their current available copies
$documents = $conn->query("
    SELECT d.*, dt.type_name as document_type,
           d.copies_received as available_copies
    FROM documents d 
    LEFT JOIN document_types dt ON d.type_id = dt.id 
    ORDER BY 
        CASE 
            WHEN d.copies_received > 0 THEN 0 
            ELSE 1 
        END,
        d.document_name ASC
");

// Get all document types for option grouping
$document_types = $conn->query("
    SELECT * FROM document_types 
    ORDER BY type_name ASC
");

// Get distribution records with document information
$result = $conn->query("
    SELECT dd.*, dd.status as distribution_status, d.document_name, d.type_id, dt.type_name as document_type,
           d.copies_received as available_copies
    FROM document_distribution dd
    JOIN documents d ON dd.document_id = d.id
    LEFT JOIN document_types dt ON d.type_id = dt.id
    ORDER BY dd.date_distributed DESC, dd.id DESC
");

// Check if query failed
if (!$result) {
    $_SESSION['toast'] = ['type' => 'error', 'message' => "Database error: " . $conn->error];
}

// Get comprehensive statistics
$stats = $conn->query("
    SELECT 
        COUNT(DISTINCT dd.id) as total_distributions,
        COALESCE(SUM(dd.number_distributed), 0) as total_copies_distributed,
        COUNT(DISTINCT d.id) as total_documents,
        COALESCE(SUM(d.copies_received), 0) as total_copies_received,
        COALESCE(SUM(d.copies_received - COALESCE(dd_dist.total_distributed, 0)), 0) as available_copies,
        COUNT(DISTINCT CASE 
            WHEN (d.copies_received - COALESCE(dd_dist.total_distributed, 0)) > 0 
            THEN d.id END) as documents_in_stock,
        COUNT(DISTINCT CASE 
            WHEN (d.copies_received - COALESCE(dd_dist.total_distributed, 0)) <= 0 
            THEN d.id END) as documents_out_of_stock
    FROM documents d
    LEFT JOIN (
        SELECT document_id, SUM(number_distributed) as total_distributed
        FROM document_distribution
        GROUP BY document_id
    ) dd_dist ON d.id = dd_dist.document_id
    LEFT JOIN document_distribution dd ON d.id = dd.document_id
")->fetch_assoc();

$total_distributions = $stats['total_distributions'] ?? 0;
$total_copies_distributed = $stats['total_copies_distributed'] ?? 0;
$available_copies = $stats['available_copies'] ?? 0;
$documents_in_stock = $stats['documents_in_stock'] ?? 0;
$documents_out_of_stock = $stats['documents_out_of_stock'] ?? 0;

$today = date('Y-m-d');
$today_distributions = 0;
$today_copies = 0;
$today_result = $conn->query("SELECT COUNT(*) as count, COALESCE(SUM(number_distributed), 0) as total FROM document_distribution WHERE date_distributed = '$today'");
if ($today_result) {
    $today_data = $today_result->fetch_assoc();
    $today_distributions = $today_data['count'];
    $today_copies = $today_data['total'];
}

// Get document types count
$type_count = 0;
$type_result = $conn->query("SELECT COUNT(*) as count FROM document_types");
if ($type_result) {
    $type_count = $type_result->fetch_assoc()['count'];
}

// Get toast message from session
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
    <title>Document Distribution - Mailroom Ops</title>
    <meta name="csrf-token" content="<?php echo csrf_token(); ?>">
    <link rel="icon" type="image/png" href="./images/logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/app.css">
</head>

<body>
    <div class="flex">
        <?php include 'sidebar.php'; ?>

        <main class="main-content">
            <!-- Header -->
            <div class="page-header flex items-center justify-between gap-4 flex-wrap">
                <div>
                    <div class="breadcrumb">
                        <a href="index.php">Mail Operations</a>
                        <span class="sep">/</span>
                        <span>Document Distribution</span>
                    </div>
                    <h1 class="page-header-title">Document Distribution</h1>
                    <p class="page-header-subtitle">Track document distribution across the organization.</p>
                </div>
                <div class="header-actions flex items-center gap-2 no-print">
                    <button type="button" onclick="printDistributionStatement()" class="btn btn-soft">
                        <i class="fa-solid fa-print"></i>
                        <span class="hidden sm:inline">Print Statement</span>
                    </button>
                    <button onclick="openDistributionModal()" class="btn btn-primary">
                        <i class="fa-regular fa-plus"></i>
                        <span class="hidden sm:inline">New Distribution</span>
                    </button>
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

                <!-- Stat Cards -->
                <div class="stat-grid mb-6">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fa-regular fa-file-lines"></i></div>
                        <div class="stat-label">Total Distributions</div>
                        <div class="stat-value"><?php echo number_format($total_distributions); ?></div>
                        <div class="stat-hint">Across all documents</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon gold"><i class="fa-solid fa-copy"></i></div>
                        <div class="stat-label">Copies Distributed</div>
                        <div class="stat-value"><?php echo number_format($total_copies_distributed); ?></div>
                        <div class="stat-hint">Total copies issued</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fa-solid fa-boxes-stacked"></i></div>
                        <div class="stat-label">Available Copies</div>
                        <div class="stat-value"><?php echo number_format($available_copies); ?></div>
                        <div class="stat-hint"><?php echo $documents_in_stock; ?> in stock &middot; <?php echo $documents_out_of_stock; ?> out</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon orange"><i class="fa-solid fa-calendar-day"></i></div>
                        <div class="stat-label">Today's Distributions</div>
                        <div class="stat-value"><?php echo number_format($today_distributions); ?></div>
                        <div class="stat-hint"><?php echo number_format($today_copies); ?> copies today</div>
                    </div>
                </div>

                <div class="print-only mb-5">
                    <h1 class="text-xl font-semibold text-[#1b2a4a]">Document Distribution Statement</h1>
                    <p class="text-sm text-[#4b5570] mt-1">Generated on <?php echo date('F j, Y g:i A'); ?></p>
                    <p class="text-sm text-[#4b5570]">Total records: <?php echo number_format($total_distributions); ?> | Total copies distributed: <?php echo number_format($total_copies_distributed); ?></p>
                </div>

                <!-- DISTRIBUTION TABLE -->
                <div class="card">
                    <div class="card-header" style="padding:12px 16px;">
                        <div>
                            <div class="card-title">Distribution History</div>
                            <div class="card-subtitle">Records of all document distributions.</div>
                        </div>
                        <div class="flex items-center gap-3 no-print flex-wrap">
                            <div class="search-wrap">
                                <i class="fa-solid fa-magnifying-glass icon"></i>
                                <input type="text" id="tableSearch" placeholder="Search records..."
                                    class="input" style="width:240px;" autocomplete="off">
                            </div>
                            <button onclick="filterDistributionTable(true)" class="btn btn-soft">
                                Search
                            </button>
                        </div>
                    </div>

                    <div class="table-wrap">
                        <table class="table" id="distributionTable">
                            <thead>
                                <tr>
                                    <th class="table-sortable" onclick="sortTable(0)">Document <i class="fa-solid fa-sort sort-ic"></i></th>
                                    <th class="table-sortable hidden md:table-cell" onclick="sortTable(1)">Type <i class="fa-solid fa-sort sort-ic"></i></th>
                                    <th class="table-sortable" onclick="sortTable(2)">Copies Distributed <i class="fa-solid fa-sort sort-ic"></i></th>
                                    <th class="table-sortable" onclick="sortTable(3)">Date <i class="fa-solid fa-sort sort-ic"></i></th>
                                    <th class="table-sortable hidden md:table-cell" onclick="sortTable(4)">Timestamp <i class="fa-solid fa-sort sort-ic"></i></th>
                                    <th>Status</th>
                                    <th class="no-print text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="tableBody">
                                <?php
                                if ($result && $result->num_rows > 0):
                                    while ($row = $result->fetch_assoc()):
                                ?>
                                        <tr class="distribution-row" id="row-<?php echo $row['id']; ?>"
                                            data-search="<?php echo strtolower(htmlspecialchars(trim(($row['document_name'] ?? '') . ' ' . ($row['document_type'] ?? '') . ' ' . ($row['number_distributed'] ?? 0) . ' ' . ($row['date_distributed'] ?? '') . ' ' . ($row['created_at'] ?? '')))); ?>">
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
                                            <td><span class="table-cell-mono"><?php echo $row['number_distributed'] ?? 0; ?></span></td>
                                            <td><?php echo date('M j, Y', strtotime($row['date_distributed'])); ?></td>
                                            <td class="whitespace-nowrap hidden md:table-cell table-cell-subtitle"><?php echo formatTimestampDisplay($row['created_at'] ?? null); ?></td>
                                            <td>
                                                <?php $status = $row['distribution_status'] ?? 'distributed'; ?>
                                                <span class="badge <?php echo $status === 'withdrawn' ? 'badge-red' : 'badge-green'; ?>">
                                                    <?php echo ucfirst($status); ?>
                                                </span>
                                            </td>
                                            <td class="no-print">
                                                <div class="row-actions justify-content-end" style="justify-content:flex-end;">
                                                    <button class="icon-btn primary" onclick="viewDistribution(<?php echo htmlspecialchars(json_encode($row)); ?>)" title="View Details">
                                                        <i class="fa-regular fa-eye"></i>
                                                    </button>
                                                    <?php if ($status === 'withdrawn'): ?>
                                                        <button type="button" onclick="continueDistribution(<?php echo $row['document_id']; ?>)"
                                                            class="icon-btn green" title="Redistribute document">
                                                            <i class="fa-solid fa-arrow-rotate-right"></i>
                                                        </button>
                                                    <?php else: ?>
                                                        <button type="button" onclick="openWithdrawModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars(addslashes($row['document_name'])); ?>', <?php echo $row['number_distributed']; ?>)"
                                                            class="icon-btn primary" title="Withdraw distribution">
                                                            <i class="fa-solid fa-rotate-left"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <button onclick="openDeleteModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars(addslashes($row['document_name'])); ?>', <?php echo $row['number_distributed']; ?>)"
                                                        class="icon-btn danger" title="Delete">
                                                        <i class="fa-regular fa-trash-can"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php
                                    endwhile;
                                else:
                                    ?>
                                    <tr>
                                        <td colspan="7">
                                            <div class="empty-state">
                                                <div class="empty-state-icon"><i class="fa-regular fa-file-lines"></i></div>
                                                <div class="empty-state-title">No distribution records</div>
                                                <div class="empty-state-text">Click "New Distribution" to get started.</div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Table Footer with Record Count -->
                    <div class="pagination-shell print-hide" style="justify-content:space-between;border-top:1px solid var(--border);">
                        <div class="pagination-meta">Showing <span id="visibleCount"><?php echo $total_distributions; ?></span> records</div>
                        <div class="pagination-meta">Total Copies Distributed: <?php echo $total_copies_distributed; ?></div>
                    </div>
                </div>
                <div id="distributionPagination" class="pagination-shell mt-4 <?php echo (!$result || $result->num_rows === 0) ? 'hidden' : ''; ?> print-hide">
                    <div class="pagination-meta">
                        <div id="distributionPaginationTitle" class="pagination-title"></div>
                        <div id="distributionPaginationInfo"></div>
                    </div>
                    <div class="pagination-controls">
                        <div id="distributionPaginationPage" class="pagination-page-indicator"></div>
                        <div class="pagination" id="distributionPaginationControls"></div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Distribution Modal -->
    <div id="distributionModal" class="modal-backdrop" style="display:none;">
        <div class="modal-dialog lg">
            <div class="modal-header">
                <h2 class="modal-title">New Distribution</h2>
                <button type="button" class="modal-close" onclick="closeDistributionModal()"><i class="fa-solid fa-xmark text-xl"></i></button>
            </div>

            <div id="stockWarning" class="alert alert-orange hidden" style="margin:0 22px;margin-top:18px;">
                <i class="fa-regular fa-triangle-exclamation"></i>
                <span id="warningMessage"></span>
            </div>

            <form method="POST" action="distribution.php" id="distributionForm" onsubmit="return validateForm()">
                <?php csrf_field(); ?>
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-field">
                            <label class="label">Document <span class="req">*</span></label>
                            <select name="document_id" id="modalDocumentSelect" required onchange="updateAvailableCopies()" class="select">
                                <option value="">-- Select Document --</option>
                                <?php
                                // Group documents by type and show available copies
                                $documents->data_seek(0);
                                $grouped_documents = [];
                                while ($doc = $documents->fetch_assoc()) {
                                    $type_name = $doc['document_type'] ?? 'Uncategorized';
                                    if (!isset($grouped_documents[$type_name])) {
                                        $grouped_documents[$type_name] = [];
                                    }
                                    $grouped_documents[$type_name][] = $doc;
                                }

                                // Display documents grouped by type
                                foreach ($grouped_documents as $type_name => $docs):
                                    $has_available = false;
                                    foreach ($docs as $doc) {
                                        if ($doc['available_copies'] > 0) {
                                            $has_available = true;
                                            break;
                                        }
                                    }
                                ?>
                                    <optgroup label="<?php echo htmlspecialchars($type_name); ?>">
                                        <?php foreach ($docs as $doc):
                                            $available = $doc['available_copies'];
                                        ?>
                                            <option value="<?php echo $doc['id']; ?>"
                                                data-available="<?php echo $available; ?>"
                                                data-total="<?php echo $doc['copies_received']; ?>"
                                                <?php echo $available <= 0 ? 'disabled class="text-[#9aa0b5]"' : ''; ?>>
                                                <?php echo htmlspecialchars($doc['document_name']); ?>
                                                (<?php echo $available; ?> of <?php echo $doc['copies_received']; ?> available)
                                                <?php echo $available <= 0 ? ' - OUT OF STOCK' : ''; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-field">
                            <label class="label">Date Distributed <span class="req">*</span></label>
                            <input type="date" name="date_distributed" id="modalDateDistributed" required value="<?php echo date('Y-m-d'); ?>"
                                class="input" autocomplete="off">
                        </div>
                    </div>

                    <!-- Distribution Summary -->
                    <div id="distributionSummary" class="hidden" style="margin-top:16px;padding:12px 14px;background:var(--bg-subtle);border:1px solid var(--border);border-radius:8px;font-size:13px;">
                        <div class="flex justify-between items-center">
                            <span style="color:var(--text-secondary);">Total copies to distribute:</span>
                            <span class="font-medium" id="totalCopiesToDistribute">0</span>
                        </div>
                        <div class="flex justify-between items-center mt-1">
                            <span style="color:var(--text-secondary);">Available copies:</span>
                            <span class="font-medium" id="availableCopiesDisplay">0</span>
                        </div>
                        <div class="flex justify-between items-center mt-1 text-xs" id="balanceWarning"></div>
                    </div>

                    <!-- MULTIPLE DISTRIBUTION ROWS -->
                    <div style="margin-top:20px;">
                        <label class="label">Distribution Entries</label>
                        <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px;">Add multiple distribution entries if distributing to multiple recipients</p>
                    </div>

                    <div id="modalDistributionRows">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
                            <div class="flex gap-2">
                                <input type="number" name="number_distributed[]" placeholder="Number of Copies" min="1" value="1"
                                    class="input flex-1 distribution-copies"
                                    onchange="updateDistributionSummary()" onkeyup="updateDistributionSummary()" autocomplete="off">
                                <button type="button" onclick="removeModalRow(this)" class="icon-btn danger">
                                    <i class="fa-regular fa-trash-can"></i>
                                </button>
                            </div>
                            <div class="flex items-center text-xs" style="color:var(--text-muted);">
                                <i class="fa-regular fa-circle-info mr-1"></i> Enter number of copies to distribute
                            </div>
                        </div>
                    </div>

                    <div class="flex gap-2 flex-wrap" style="margin-top:4px;">
                        <button type="button" onclick="addModalRow()" class="btn btn-soft">
                            <i class="fa-regular fa-plus"></i> Add Another Entry
                        </button>
                        <button type="button" onclick="addModalBulkRows()" class="btn btn-soft">
                            <i class="fa-solid fa-layer-group"></i> Add 5 Entries
                        </button>
                        <button type="button" onclick="setMaxDistribution()" class="btn btn-soft">
                            <i class="fa-solid fa-gauge-high"></i> Use All Available Copies
                        </button>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" onclick="closeDistributionModal()" class="btn btn-soft">Cancel</button>
                    <button type="submit" name="submit" id="submitBtn" class="btn btn-primary">
                        <i class="fa-regular fa-floppy-disk"></i> Save Distribution
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Distribution Modal -->
    <div id="viewModal" class="modal-backdrop" style="display:none;">
        <div class="modal-dialog sm">
            <div class="modal-header">
                <h3 class="modal-title">Distribution Details</h3>
                <button class="modal-close" onclick="closeViewModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <div id="viewContent" class="space-y-3">
                    <!-- Filled by JavaScript -->
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
                <h2 class="modal-title">Confirm Delete</h2>
                <button type="button" class="modal-close" onclick="closeDeleteModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <p style="font-size:13px;color:var(--text-secondary);line-height:1.5;">Are you sure you want to delete this distribution record?</p>
                <div style="margin-top:14px;padding:12px 14px;background:var(--red-soft);border:1px solid var(--red-border);border-radius:8px;">
                    <p class="text-sm font-medium" style="color:var(--red);" id="deleteDocumentName"></p>
                    <p class="text-xs mt-1" style="color:var(--red);opacity:.8;" id="deleteCopiesCount"></p>
                </div>
                <p class="text-xs mt-3" style="color:var(--text-muted);">
                    <i class="fa-solid fa-circle-info mr-1"></i>
                    This will restore the copies back to the document inventory.
                </p>
            </div>
            <div class="modal-footer">
                <button onclick="closeDeleteModal()" class="btn btn-soft">Cancel</button>
                <a href="#" id="confirmDeleteBtn" class="btn btn-danger">Delete &amp; Restore Copies</a>
            </div>
        </div>
    </div>

    <!-- Withdraw Confirmation Modal -->
    <div id="withdrawModal" class="modal-backdrop" style="display:none;">
        <div class="modal-dialog sm">
            <div class="modal-header">
                <h2 class="modal-title">Confirm Withdraw</h2>
                <button type="button" class="modal-close" onclick="closeWithdrawModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <p style="font-size:13px;color:var(--text-secondary);line-height:1.5;">Do you want to withdraw this distribution and restore the copies to inventory?</p>
                <div style="margin-top:14px;padding:12px 14px;background:var(--orange-soft);border:1px solid var(--orange-border);border-radius:8px;">
                    <p class="text-sm font-medium" style="color:var(--orange);" id="withdrawDocumentName"></p>
                    <p class="text-xs mt-1" style="color:var(--orange);opacity:.85;" id="withdrawCopiesCount"></p>
                </div>
                <p class="text-xs mt-3" style="color:var(--text-muted);">
                    <i class="fa-solid fa-circle-info mr-1"></i>
                    This will mark the distribution as withdrawn and restore the copies back to the document inventory.
                </p>
            </div>
            <div class="modal-footer">
                <button onclick="closeWithdrawModal()" class="btn btn-soft">Cancel</button>
                <a href="#" id="confirmWithdrawBtn" class="btn btn-danger"><i class="fa-solid fa-rotate-left"></i> Withdraw Distribution</a>
            </div>
        </div>
    </div>

    <!-- Confirm Distribution Modal -->
    <div id="confirmDistributionModal" class="modal-backdrop" style="display:none;">
        <div class="modal-dialog sm">
            <div class="modal-header">
                <h2 class="modal-title">Confirm Distribution</h2>
                <button type="button" class="modal-close" onclick="closeConfirmDistributionModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <p style="font-size:13px;color:var(--text-secondary);line-height:1.5;">Please confirm the following distribution details:</p>
                <div style="margin-top:14px;padding:8px 14px;background:var(--bg-subtle);border:1px solid var(--border);border-radius:8px;">
                    <div class="flex justify-between">
                        <span class="text-xs" style="color:var(--text-muted);">Document</span>
                        <span class="text-sm font-medium text-right" id="confirmDocName">-</span>
                    </div>
                    <div class="flex justify-between mt-2">
                        <span class="text-xs" style="color:var(--text-muted);">Date Distributed</span>
                        <span class="text-sm font-medium" id="confirmDate">-</span>
                    </div>
                    <div class="flex justify-between border-t pt-2 mt-2" style="border-color:var(--border);">
                        <span class="text-xs" style="color:var(--text-muted);">Total Copies</span>
                        <span class="text-sm font-bold" style="color:var(--accent);" id="confirmTotalCopies">-</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button onclick="closeConfirmDistributionModal()" class="btn btn-soft">Edit Details</button>
                <button onclick="finalSubmitDistribution()" id="finalConfirmBtn" class="btn btn-primary">
                    <i class="fa-regular fa-circle-check"></i> Confirm &amp; Save
                </button>
            </div>
        </div>
    </div>

    <script>
        // Toast notification function - delegates to shared MailroomToast
        function showToast(message, type = 'info', duration = 5000) {
            MailroomToast.show(message, type, duration);
        }

        // ========== MODAL FUNCTIONS ==========
        function openDistributionModal() {
            resetModalForm();
            MailroomModal.open('distributionModal');
            updateAvailableCopies();
        }

        function closeDistributionModal() {
            MailroomModal.close('distributionModal');
        }

        function resetModalForm() {
            const container = document.getElementById('modalDistributionRows');
            const rows = container.querySelectorAll('.grid');
            for (let i = 1; i < rows.length; i++) {
                rows[i].remove();
            }

            const firstRow = rows[0];
            if (firstRow) {
                const copiesInput = firstRow.querySelector('input[name="number_distributed[]"]');
                if (copiesInput) copiesInput.value = '1';
            }

            document.getElementById('modalDocumentSelect').value = '';
            document.getElementById('modalDateDistributed').value = '<?php echo date('Y-m-d'); ?>';
            document.getElementById('distributionSummary').classList.add('hidden');
            document.getElementById('stockWarning').classList.add('hidden');
        }

        function updateAvailableCopies() {
            const select = document.getElementById('modalDocumentSelect');
            const selectedOption = select.options[select.selectedIndex];

            if (selectedOption && selectedOption.value) {
                const available = selectedOption.dataset.available || 0;
                document.getElementById('availableCopiesDisplay').textContent = available;
                document.getElementById('distributionSummary').classList.remove('hidden');
                updateDistributionSummary();
            } else {
                document.getElementById('distributionSummary').classList.add('hidden');
            }
        }

        function updateDistributionSummary() {
            const select = document.getElementById('modalDocumentSelect');
            const selectedOption = select.options[select.selectedIndex];

            if (!selectedOption || !selectedOption.value) {
                return;
            }

            const available = parseInt(selectedOption.dataset.available || 0);
            const copies = document.querySelectorAll('.distribution-copies');
            let total = 0;

            copies.forEach(input => {
                const val = parseInt(input.value);
                if (!isNaN(val) && val > 0) {
                    total += val;
                }
            });

            document.getElementById('totalCopiesToDistribute').textContent = total;

            const balanceWarning = document.getElementById('balanceWarning');
            const submitBtn = document.getElementById('submitBtn');
            const stockWarning = document.getElementById('stockWarning');
            const warningMessage = document.getElementById('warningMessage');

            if (total > available) {
                balanceWarning.innerHTML = `<span style="color:var(--red);"><i class="fa-regular fa-circle-exclamation mr-1"></i>Exceeds available by ${total - available} copies</span>`;
                submitBtn.disabled = true;
                submitBtn.classList.add('disabled');
                stockWarning.classList.remove('hidden');
                warningMessage.textContent = `Warning: You are trying to distribute ${total} copies but only ${available} are available.`;
            } else {
                balanceWarning.innerHTML = '';
                submitBtn.disabled = false;
                submitBtn.classList.remove('disabled');
                stockWarning.classList.add('hidden');
            }
        }

        function setMaxDistribution() {
            const select = document.getElementById('modalDocumentSelect');
            const selectedOption = select.options[select.selectedIndex];

            if (!selectedOption || !selectedOption.value) {
                showToast('Please select a document first', 'warning');
                return;
            }

            const available = parseInt(selectedOption.dataset.available || 0);
            const rows = document.querySelectorAll('.distribution-copies');

            if (rows.length === 0) return;

            const perRow = Math.floor(available / rows.length);
            const remainder = available % rows.length;

            rows.forEach((input, index) => {
                if (index < remainder) {
                    input.value = perRow + 1;
                } else {
                    input.value = perRow;
                }
            });

            updateDistributionSummary();
            showToast(`Set to distribute all ${available} available copies`, 'info', 2000);
        }

        function addModalRow() {
            const container = document.getElementById('modalDistributionRows');
            const row = `
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
                    <div class="flex gap-2">
                        <input type="number" name="number_distributed[]" placeholder="Number of Copies" min="1" value="1"
                            class="input flex-1 distribution-copies"
                            onchange="updateDistributionSummary()" onkeyup="updateDistributionSummary()" autocomplete="off">
                        <button type="button" onclick="removeModalRow(this)" class="icon-btn danger">
                            <i class="fa-regular fa-trash-can"></i>
                        </button>
                    </div>
                    <div class="flex items-center text-xs" style="color:var(--text-muted);">
                        <i class="fa-regular fa-circle-info mr-1"></i> Enter number of copies to distribute
                    </div>
                </div>
            `;
            container.insertAdjacentHTML("beforeend", row);
            updateDistributionSummary();
            showToast('New distribution entry added', 'info', 2000);
        }

        function addModalBulkRows() {
            for (let i = 0; i < 5; i++) {
                addModalRow();
            }
            showToast('5 distribution entries added', 'success', 2000);
        }

        function removeModalRow(button) {
            const row = button.closest('.grid');
            if (row && document.querySelectorAll('#modalDistributionRows .grid').length > 1) {
                row.remove();
                updateDistributionSummary();
                showToast('Entry removed', 'info', 2000);
            } else {
                showToast('You must keep at least one distribution entry', 'warning', 3000);
            }
        }

        function validateForm() {
            const documentId = document.getElementById('modalDocumentSelect').value;
            if (!documentId) {
                showToast('Please select a document', 'warning');
                return false;
            }

            const select = document.getElementById('modalDocumentSelect');
            const selectedOption = select.options[select.selectedIndex];
            const available = parseInt(selectedOption.dataset.available || 0);

            const copies = document.querySelectorAll('.distribution-copies');
            let totalCopies = 0;
            let hasValidEntry = false;

            copies.forEach(input => {
                const val = parseInt(input.value);
                if (!isNaN(val) && val > 0) {
                    hasValidEntry = true;
                    totalCopies += val;
                }
            });

            if (!hasValidEntry) {
                showToast('Please enter at least one valid number of copies', 'warning');
                return false;
            }

            if (totalCopies > available) {
                showToast(`Cannot distribute ${totalCopies} copies. Only ${available} available.`, 'warning');
                return false;
            }

            // Instead of confirm(), show the custom modal
            document.getElementById('confirmDocName').textContent = selectedOption.text.split('(')[0].trim();
            document.getElementById('confirmDate').textContent = document.getElementById('modalDateDistributed').value;
            document.getElementById('confirmTotalCopies').textContent = totalCopies;

            MailroomModal.open('confirmDistributionModal');
            return false; // Prevent immediate submission
        }

        function finalSubmitDistribution() {
            const btn = document.getElementById('finalConfirmBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Saving...';

            document.getElementById('distributionForm').submit();
        }

        function closeConfirmDistributionModal() {
            MailroomModal.close('confirmDistributionModal');
        }

        // ========== DELETE / WITHDRAW MODAL FUNCTIONS ==========
        let currentDeleteId = null;

        function openDeleteModal(id, documentName, copies) {
            currentDeleteId = id;
            document.getElementById('deleteDocumentName').textContent = documentName;
            document.getElementById('deleteCopiesCount').textContent = `Copies: ${copies}`;

            document.getElementById('confirmDeleteBtn').onclick = function(e) {
                e.preventDefault();
                submitPostForm('distribution.php', { delete_distribution: id });
            };

            MailroomModal.open('deleteModal');
        }

        let currentWithdrawId = null;

        function openWithdrawModal(id, documentName, copies) {
            currentWithdrawId = id;
            document.getElementById('withdrawDocumentName').textContent = documentName;
            document.getElementById('withdrawCopiesCount').textContent = `Copies: ${copies}`;

            document.getElementById('confirmWithdrawBtn').onclick = function(e) {
                e.preventDefault();
                submitPostForm('distribution.php', { withdraw_distribution: id });
            };

            MailroomModal.open('withdrawModal');
        }

        function continueDistribution(documentId) {
            const select = document.getElementById('modalDocumentSelect');
            if (!select) {
                showToast('Distribution modal is not available.', 'error');
                return;
            }

            const option = select.querySelector(`option[value="${documentId}"]`);
            if (!option) {
                showToast('This document cannot be continued because it is no longer available for distribution.', 'warning');
                return;
            }

            openDistributionModal();
            select.value = documentId;
            updateAvailableCopies();

            const copiesInput = document.querySelector('.distribution-copies');
            if (copiesInput) {
                copiesInput.value = '1';
            }
            updateDistributionSummary();
            showToast('Ready to continue distribution for the selected document.', 'info', 2000);
        }

        function closeDeleteModal() {
            MailroomModal.close('deleteModal');
            currentDeleteId = null;
        }

        function closeWithdrawModal() {
            MailroomModal.close('withdrawModal');
            currentWithdrawId = null;
        }

        // View distribution details
        function viewDistribution(data) {
            const content = document.getElementById('viewContent');
            content.innerHTML = `
                <div class="grid grid-cols-2 gap-3">
                    <div class="col-span-2">
                        <p style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:4px;">Document</p>
                        <p class="text-sm font-medium" style="color:var(--text);">${esc(data.document_name || '')}</p>
                    </div>
                    <div>
                        <p style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:4px;">Document Type</p>
                        <p class="text-sm" style="color:var(--text-secondary);">${esc(data.document_type || 'Not specified')}</p>
                    </div>
                    <div>
                        <p style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:4px;">Copies Distributed</p>
                        <p class="text-sm font-mono" style="color:var(--text);">${data.number_distributed || 0}</p>
                    </div>
                    <div>
                        <p style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:4px;">Date Distributed</p>
                        <p class="text-sm" style="color:var(--text-secondary);">${data.date_distributed ? new Date(data.date_distributed).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : ''}</p>
                    </div>
                    <div>
                        <p style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:4px;">Timestamp</p>
                        <p class="text-sm" style="color:var(--text-secondary);">${data.created_at ? new Date(data.created_at.replace(' ', 'T')).toLocaleString() : 'N/A'}</p>
                    </div>
                    <div class="col-span-2">
                        <p style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:4px;">Available Copies of Document</p>
                        <p class="text-sm" style="color:var(--text-secondary);">${data.available_copies || 0}</p>
                    </div>
                </div>
            `;
            MailroomModal.open('viewModal');
        }

        function closeViewModal() {
            MailroomModal.close('viewModal');
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        // app.js provides a global esc() helper; do not redeclare it here.

        // Table search functionality
        const distributionPageSize = 10;
        let distributionCurrentPage = 1;
        let distributionSearchTimer;

        function getVisibleDistributionRows() {
            return Array.from(document.querySelectorAll('.distribution-row')).filter(row => row.dataset.filtered !== 'false');
        }

        function renderDistributionPagination() {
            const visibleRows = getVisibleDistributionRows();
            const totalRows = visibleRows.length;
            const totalPages = Math.max(1, Math.ceil(totalRows / distributionPageSize));
            const wrapper = document.getElementById('distributionPagination');
            const title = document.getElementById('distributionPaginationTitle');
            const info = document.getElementById('distributionPaginationInfo');
            const pageIndicator = document.getElementById('distributionPaginationPage');
            const controls = document.getElementById('distributionPaginationControls');

            if (!wrapper || !info || !controls) return;

            if (distributionCurrentPage > totalPages) distributionCurrentPage = totalPages;

            const startIndex = (distributionCurrentPage - 1) * distributionPageSize;
            const endIndex = startIndex + distributionPageSize;

            document.querySelectorAll('.distribution-row').forEach(row => {
                row.style.display = 'none';
            });

            visibleRows.forEach((row, index) => {
                row.style.display = index >= startIndex && index < endIndex ? '' : 'none';
            });

            const visibleCountEl = document.getElementById('visibleCount');
            if (visibleCountEl) visibleCountEl.textContent = totalRows;

            if (totalRows === 0) {
                if (title) title.textContent = '';
                info.textContent = 'No matching records';
                if (pageIndicator) pageIndicator.textContent = '';
                controls.innerHTML = '';
                wrapper.classList.add('hidden');
                return;
            }

            const from = startIndex + 1;
            const to = Math.min(endIndex, totalRows);
            const visibleCount = Math.max(0, to - startIndex);
            if (title) title.textContent = `Showing ${visibleCount} ${visibleCount === 1 ? 'record' : 'records'} on this page`;
            info.textContent = `Records ${from}-${to} of ${totalRows} total`;
            if (pageIndicator) pageIndicator.textContent = `Page ${distributionCurrentPage} of ${totalPages}`;
            wrapper.classList.toggle('hidden', totalRows <= distributionPageSize);

            const startPage = Math.max(1, distributionCurrentPage - 2);
            const endPage = Math.min(totalPages, distributionCurrentPage + 2);
            let controlsHtml = `
                <button class="pagination-item compact ${distributionCurrentPage === 1 ? 'disabled' : ''}" ${distributionCurrentPage === 1 ? 'disabled' : ''} onclick="changeDistributionPage(1)">
                    <i class="fa-solid fa-chevrons-left"></i>
                </button>
                <button class="pagination-item compact ${distributionCurrentPage === 1 ? 'disabled' : ''}" ${distributionCurrentPage === 1 ? 'disabled' : ''} onclick="changeDistributionPage(${distributionCurrentPage - 1})">
                    <i class="fa-solid fa-chevron-left"></i>
                </button>
            `;

            if (startPage > 1) {
                controlsHtml += `<button class="pagination-item" onclick="changeDistributionPage(1)">1</button>`;
                if (startPage > 2) controlsHtml += `<span class="pagination-ellipsis">...</span>`;
            }

            for (let i = startPage; i <= endPage; i++) {
                controlsHtml += `<button class="pagination-item ${i === distributionCurrentPage ? 'active' : ''}" onclick="changeDistributionPage(${i})">${i}</button>`;
            }

            if (endPage < totalPages) {
                if (endPage < totalPages - 1) controlsHtml += `<span class="pagination-ellipsis">...</span>`;
                controlsHtml += `<button class="pagination-item" onclick="changeDistributionPage(${totalPages})">${totalPages}</button>`;
            }

            controlsHtml += `
                <button class="pagination-item compact ${distributionCurrentPage === totalPages ? 'disabled' : ''}" ${distributionCurrentPage === totalPages ? 'disabled' : ''} onclick="changeDistributionPage(${distributionCurrentPage + 1})">
                    <i class="fa-solid fa-chevron-right"></i>
                </button>
                <button class="pagination-item compact ${distributionCurrentPage === totalPages ? 'disabled' : ''}" ${distributionCurrentPage === totalPages ? 'disabled' : ''} onclick="changeDistributionPage(${totalPages})">
                    <i class="fa-solid fa-chevrons-right"></i>
                </button>
            `;
            controls.innerHTML = controlsHtml;
        }

        function changeDistributionPage(page) {
            distributionCurrentPage = Math.max(1, page);
            renderDistributionPagination();
        }

        function filterDistributionTable(showFeedback = false) {
            const searchTokens = (document.getElementById('tableSearch')?.value || '')
                .toLowerCase()
                .split(/\s+/)
                .filter(Boolean);
            const rows = document.querySelectorAll('.distribution-row');
            let visibleCount = 0;

            rows.forEach(row => {
                const text = row.getAttribute('data-search') || row.textContent.toLowerCase();
                const matches = searchTokens.length === 0 || searchTokens.every(token => text.includes(token));
                row.dataset.filtered = matches ? 'true' : 'false';
                row.style.display = matches ? '' : 'none';
                if (matches) visibleCount++;
            });

            distributionCurrentPage = 1;
            renderDistributionPagination();

            if (showFeedback && visibleCount === 0) showToast('No matching records found', 'info', 2000);
        }

        document.getElementById('tableSearch')?.addEventListener('input', function() {
            clearTimeout(distributionSearchTimer);
            distributionSearchTimer = setTimeout(() => filterDistributionTable(false), 150);
        });

        function printDistributionStatement() {
            const rows = Array.from(document.querySelectorAll('.distribution-row'));
            const previousDisplay = rows.map(row => row.style.display);

            rows.forEach(row => {
                row.style.display = row.dataset.filtered === 'false' ? 'none' : '';
            });

            window.print();

            setTimeout(() => {
                rows.forEach((row, index) => {
                    row.style.display = previousDisplay[index];
                });
                renderDistributionPagination();
            }, 250);
        }

        // Table sorting
        let sortDirection = 'asc';
        let lastSortedColumn = -1;

        function sortTable(columnIndex) {
            const tbody = document.getElementById('tableBody');
            const rows = Array.from(tbody.querySelectorAll('tr'));

            if (lastSortedColumn === columnIndex) {
                sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                sortDirection = 'asc';
                lastSortedColumn = columnIndex;
            }

            rows.sort((a, b) => {
                const aCol = a.querySelectorAll('td')[columnIndex]?.textContent.trim() || '';
                const bCol = b.querySelectorAll('td')[columnIndex]?.textContent.trim() || '';

                if (!isNaN(aCol) && !isNaN(bCol)) {
                    return sortDirection === 'asc' ? parseFloat(aCol) - parseFloat(bCol) : parseFloat(bCol) - parseFloat(aCol);
                }

                const comparison = aCol.localeCompare(bCol);
                return sortDirection === 'asc' ? comparison : -comparison;
            });

            tbody.innerHTML = '';
            rows.forEach(row => tbody.appendChild(row));
            renderDistributionPagination();
            showToast(`Sorted by column ${columnIndex + 1} (${sortDirection})`, 'info', 1500);
        }

        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.distribution-row').forEach(row => {
                row.dataset.filtered = 'true';
            });
            renderDistributionPagination();
        });
    </script>
    <script src="assets/app.js"></script>
</body>

</html>