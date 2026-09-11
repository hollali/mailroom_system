<?php
// available_documents.php
require_once './config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/audit.php';

// Start session for toast messages
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$documents_has_created_at = tableHasColumn($conn, 'documents', 'created_at');
$documents_has_serial_number = tableHasColumn($conn, 'documents', 'serial_number');
$document_received_expr = $documents_has_created_at
    ? "COALESCE(d.created_at, d.date_received) as received_timestamp"
    : "d.date_received as received_timestamp";

// Handle add document via AJAX
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] == 'add_document') {
    csrf_check_post();
    header('Content-Type: application/json');

    $document_name = trim($_POST['document_name']);
    $type_id = (int)$_POST['type_id'];
    $origin = trim($_POST['origin']);
    $copies_received = (int)$_POST['copies_received'];
    $date_received = $_POST['date_received'];
    $normalized_received_timestamp = normalizeDateTimeInput($date_received);
    $date_received_only = $normalized_received_timestamp ? date('Y-m-d', strtotime($normalized_received_timestamp)) : null;

    // Validate inputs
    if (empty($document_name)) {
        echo json_encode(['success' => false, 'message' => 'Document name is required']);
        exit();
    }

    if ($type_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Please select a document type']);
        exit();
    }

    if ($copies_received < 1) {
        echo json_encode(['success' => false, 'message' => 'Number of copies must be at least 1']);
        exit();
    }

    if (empty($normalized_received_timestamp)) {
        echo json_encode(['success' => false, 'message' => 'Date received is required']);
        exit();
    }

    $serial_number = null;

    if ($documents_has_serial_number) {
        // Auto-generate serial number only when the database supports it.
        $prefix = 'DOC';
        $year = date('Y');
        $random = str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
        $serial_number = $prefix . $year . $random;

        $check_stmt = $conn->prepare("SELECT id FROM documents WHERE serial_number = ?");
        $check_stmt->bind_param("s", $serial_number);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            $random = str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
            $serial_number = $prefix . $year . $random;
        }
        $check_stmt->close();
    }

    // Insert new document using the columns that actually exist in the table.
    if ($documents_has_serial_number && $documents_has_created_at) {
        $insert_stmt = $conn->prepare("INSERT INTO documents 
            (serial_number, document_name, type_id, origin, copies_received, date_received, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insert_stmt->bind_param("ssisiss", $serial_number, $document_name, $type_id, $origin, $copies_received, $date_received_only, $normalized_received_timestamp);
    } elseif ($documents_has_serial_number) {
        $insert_stmt = $conn->prepare("INSERT INTO documents 
            (serial_number, document_name, type_id, origin, copies_received, date_received) 
            VALUES (?, ?, ?, ?, ?, ?)");
        $insert_stmt->bind_param("ssisis", $serial_number, $document_name, $type_id, $origin, $copies_received, $date_received_only);
    } elseif ($documents_has_created_at) {
        $insert_stmt = $conn->prepare("INSERT INTO documents 
            (document_name, type_id, origin, copies_received, date_received, created_at) 
            VALUES (?, ?, ?, ?, ?, ?)");
        $insert_stmt->bind_param("sisiss", $document_name, $type_id, $origin, $copies_received, $date_received_only, $normalized_received_timestamp);
    } else {
        $insert_stmt = $conn->prepare("INSERT INTO documents 
            (document_name, type_id, origin, copies_received, date_received) 
            VALUES (?, ?, ?, ?, ?)");
        $insert_stmt->bind_param("sisis", $document_name, $type_id, $origin, $copies_received, $date_received_only);
    }

    if ($insert_stmt->execute()) {
        $new_id = $conn->insert_id;
        audit_log('create', 'document', $new_id, "Added document '" . addslashes($document_name) . "' - $copies_received copies from $origin", 'System');
        $success_message = 'Document added successfully';
        if ($serial_number !== null) {
            $success_message .= ' with serial number: ' . $serial_number;
        }

        echo json_encode([
            'success' => true,
            'message' => $success_message,
            'document_id' => $new_id,
            'serial_number' => $serial_number
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $insert_stmt->error]);
    }
    $insert_stmt->close();
    exit();
}

// Handle get document info for Edit Modal
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] == 'get_document') {
    csrf_check_post();
    header('Content-Type: application/json');
    $id = (int)$_POST['id'];

    $stmt = $conn->prepare("SELECT d.*, dt.type_name FROM documents d LEFT JOIN document_types dt ON d.type_id = dt.id WHERE d.id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($doc = $result->fetch_assoc()) {
        echo json_encode(['success' => true, 'document' => $doc]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Document not found']);
    }
    $stmt->close();
    exit();
}

// Handle edit document via AJAX
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] == 'edit_document') {
    csrf_check_post();
    header('Content-Type: application/json');

    $id = (int)$_POST['id'];
    $document_name = trim($_POST['document_name']);
    $type_id = (int)$_POST['type_id'];
    $origin = trim($_POST['origin']);
    $copies_received = (int)$_POST['copies_received'];
    $date_received = $_POST['date_received'];
    $normalized_received_timestamp = normalizeDateTimeInput($date_received);
    $date_received_only = $normalized_received_timestamp ? date('Y-m-d', strtotime($normalized_received_timestamp)) : null;

    if ($id <= 0 || empty($document_name) || $type_id <= 0 || $copies_received < 1 || empty($normalized_received_timestamp)) {
        echo json_encode(['success' => false, 'message' => 'Invalid input data']);
        exit();
    }

    // Check if new total copies is less than distributed copies
    $check_dist = $conn->prepare("SELECT COALESCE(SUM(number_distributed), 0) as distributed FROM document_distribution WHERE document_id = ?");
    $check_dist->bind_param("i", $id);
    $check_dist->execute();
    $distributed = $check_dist->get_result()->fetch_assoc()['distributed'];
    $check_dist->close();

    if ($copies_received < $distributed) {
        echo json_encode(['success' => false, 'message' => "Cannot reduce total copies to $copies_received. Total distributed is $distributed."]);
        exit();
    }

    if ($documents_has_created_at) {
        $stmt = $conn->prepare("UPDATE documents SET document_name = ?, type_id = ?, origin = ?, copies_received = ?, date_received = ?, created_at = ? WHERE id = ?");
        $stmt->bind_param("sisissi", $document_name, $type_id, $origin, $copies_received, $date_received_only, $normalized_received_timestamp, $id);
    } else {
        $stmt = $conn->prepare("UPDATE documents SET document_name = ?, type_id = ?, origin = ?, copies_received = ?, date_received = ? WHERE id = ?");
        $stmt->bind_param("sisis i", $document_name, $type_id, $origin, $copies_received, $date_received_only, $id);
    }

    if ($stmt->execute()) {
        audit_log('update', 'document', $id, "Updated document '" . addslashes($document_name) . "'", 'System');
        echo json_encode(['success' => true, 'message' => 'Document updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $stmt->error]);
    }
    $stmt->close();
    exit();
}

// Handle delete document via AJAX
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] == 'delete_document') {
    csrf_check_post();
    header('Content-Type: application/json');
    $id = (int)$_POST['id'];

    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid ID']);
        exit();
    }

    $name_stmt = $conn->prepare("SELECT document_name FROM documents WHERE id = ?");
    $name_stmt->bind_param("i", $id);
    $name_stmt->execute();
    $doc_delete_name = $name_stmt->get_result()->fetch_assoc()['document_name'] ?? '';
    $name_stmt->close();

    $stmt = $conn->prepare("DELETE FROM documents WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        audit_log('delete', 'document', $id, "Deleted document '" . addslashes($doc_delete_name) . "'");
        echo json_encode(['success' => true, 'message' => 'Document deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $stmt->error]);
    }
    $stmt->close();
    exit();
}

// Get all documents with their basic info (no distribution or availability tracking)
$sql = "SELECT 
            d.*, 
            dt.type_name as document_type,
            $document_received_expr,
            COALESCE((SELECT SUM(number_distributed) FROM document_distribution WHERE document_id = d.id), 0) as copies_distributed
        FROM documents d
        LEFT JOIN document_types dt ON d.type_id = dt.id
        ORDER BY d.date_received DESC";

$documents_result = $conn->query($sql);

if (!$documents_result) {
    $_SESSION['toast'] = ['type' => 'error', 'message' => "Database error: " . $conn->error];
}

// Calculate basic statistics
$stats = [
    'total_documents' => 0,
    'total_copies' => 0
];

$stats_sql = "SELECT 
                COUNT(DISTINCT d.id) as total_documents,
                SUM(d.copies_received) as total_copies
              FROM documents d";

$stats_result = $conn->query($stats_sql);
if ($stats_result) {
    $stats = $stats_result->fetch_assoc();
}

// Get document types for filter and add form
$types_result = $conn->query("SELECT id, type_name FROM document_types ORDER BY type_name");
$document_types = [];
if ($types_result) {
    while ($row = $types_result->fetch_assoc()) {
        $document_types[] = $row;
    }
}

// Get toast message from session
$toast = null;
if (isset($_SESSION['toast'])) {
    $toast = $_SESSION['toast'];
    unset($_SESSION['toast']);
}

// Compute counts for stat cards: awaiting distribution vs distributed
$doc_stats = ['pending' => 0, 'distributed' => 0];
if ($documents_result) {
    $documents_result->data_seek(0);
    while ($row = $documents_result->fetch_assoc()) {
        $dist = (int)($row['copies_distributed'] ?? 0);
        if ($dist < (int)$row['copies_received']) {
            $doc_stats['pending']++;
        } else {
            $doc_stats['distributed']++;
        }
    }
    $documents_result->data_seek(0);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documents — Mailroom Operations</title>
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
                        <span>Documents</span>
                    </div>
                    <h1 class="page-header-title">Documents</h1>
                    <p class="page-header-subtitle">Manage and track incoming documents.</p>
                </div>
                <div class="header-actions flex items-center gap-2 print-hide">
                    <div class="dropdown">
                        <button class="btn btn-soft" onclick="toggleDropdown(this)">
                            <i class="fa-solid fa-arrow-up-right-from-square"></i>
                            <span class="hidden sm:inline">Distribution</span>
                        </button>
                        <div class="dropdown-menu">
                            <a href="distribution.php" class="dropdown-item"><i class="fa-solid fa-share-from-square"></i> Distribute Documents</a>
                            <a href="documents_distribution_history.php" class="dropdown-item"><i class="fa-solid fa-clock-rotate-left"></i> Distribution History</a>
                        </div>
                    </div>
                    <button onclick="openAddDocumentModal()" class="btn btn-primary">
                        <i class="fa-solid fa-plus"></i>
                        <span class="hidden sm:inline">Receive Document</span>
                    </button>
                </div>
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
                        <div class="stat-icon blue"><i class="fa-solid fa-folder-open"></i></div>
                        <div class="stat-label">Total Documents</div>
                        <div class="stat-value"><?php echo number_format($stats['total_documents'] ?? 0); ?></div>
                        <div class="stat-hint">Across all types</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fa-solid fa-check-double"></i></div>
                        <div class="stat-label">Fully Distributed</div>
                        <div class="stat-value"><?php echo number_format($doc_stats['distributed']); ?></div>
                        <div class="stat-hint">All copies distributed</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon orange"><i class="fa-solid fa-hourglass-half"></i></div>
                        <div class="stat-label">Awaiting Distribution</div>
                        <div class="stat-value"><?php echo number_format($doc_stats['pending']); ?></div>
                        <div class="stat-hint">Copies still remaining</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon gray"><i class="fa-solid fa-copy"></i></div>
                        <div class="stat-label">Total Copies</div>
                        <div class="stat-value"><?php echo number_format($stats['total_copies'] ?? 0); ?></div>
                        <div class="stat-hint">All reception copies</div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card mb-6 print-hide">
                    <div class="card-body" style="padding:14px 16px;">
                        <div class="filter-bar" style="margin-bottom:0;">
                            <div class="search-wrap" style="flex:1;min-width:220px;">
                                <i class="fa-solid fa-magnifying-glass icon"></i>
                                <input type="text" id="searchInput" placeholder="Search by name, serial number, or type..."
                                    class="input" autocomplete="off">
                            </div>
                            <select id="typeFilter" class="select">
                                <option value="">All Document Types</option>
                                <?php foreach ($document_types as $type): ?>
                                    <option value="<?php echo htmlspecialchars($type['type_name']); ?>">
                                        <?php echo htmlspecialchars($type['type_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button onclick="applyFilters()" class="btn btn-primary">
                                <i class="fa-solid fa-filter"></i> Filter
                            </button>
                            <button onclick="resetFilters()" class="btn btn-soft">
                                <i class="fa-solid fa-rotate-left"></i> Reset
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Documents Table -->
                <div class="card">
                    <div class="table-wrap">
                        <table id="documentsTable" class="table">
                            <thead>
                                <tr>
                                    <th class="table-sortable" onclick="sortTable(0)">Serial # <i class="fa-solid fa-sort sort-ic"></i></th>
                                    <th class="table-sortable" onclick="sortTable(1)">Document Name <i class="fa-solid fa-sort sort-ic"></i></th>
                                    <th class="table-sortable hidden md:table-cell" onclick="sortTable(2)">Type <i class="fa-solid fa-sort sort-ic"></i></th>
                                    <th class="hidden lg:table-cell">Origin</th>
                                    <th class="table-sortable hidden md:table-cell" onclick="sortTable(4)">Received <i class="fa-solid fa-sort sort-ic"></i></th>
                                    <th>Status</th>
                                    <th style="width:90px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="tableBody">
                                <?php if ($documents_result && $documents_result->num_rows > 0): ?>
                                    <?php
                                    while ($doc = $documents_result->fetch_assoc()):
                                        $total = (int)$doc['copies_received'];
                                        $distributed = (int)($doc['copies_distributed'] ?? 0);
                                        $remaining = $total - $distributed;
                                        $isPending = $remaining > 0;
                                        $serialDisplay = getDocumentSerialDisplay($doc, $documents_has_serial_number);
                                    ?>
                                        <tr class="document-row"
                                            data-id="<?php echo $doc['id']; ?>"
                                            data-type="<?php echo strtolower(htmlspecialchars($doc['document_type'] ?? 'uncategorized')); ?>"
                                            data-name="<?php echo strtolower(htmlspecialchars($doc['document_name'])); ?>"
                                            data-serial="<?php echo strtolower(htmlspecialchars($serialDisplay)); ?>"
                                            data-origin="<?php echo strtolower(htmlspecialchars($doc['origin'] ?? '')); ?>"
                                            data-status="<?php echo $isPending ? 'pending' : 'distributed'; ?>"
                                            data-search="<?php echo strtolower(htmlspecialchars(trim(($doc['document_name'] ?? '') . ' ' . $serialDisplay . ' ' . ($doc['document_type'] ?? '') . ' ' . ($doc['origin'] ?? '') . ' ' . ($doc['received_timestamp'] ?? '') . ' ' . $total))); ?>">
                                            <td class="table-cell-mono font-medium"><?php echo htmlspecialchars($serialDisplay); ?></td>
                                            <td>
                                                <div class="table-cell-title"><?php echo htmlspecialchars($doc['document_name']); ?></div>
                                                <div class="table-cell-subtitle hidden sm:block"><?php echo $total; ?> copy<?php echo $total === 1 ? '' : 's'; ?><?php echo $distributed > 0 ? ' · ' . $distributed . ' distributed' : ''; ?></div>
                                            </td>
                                            <td class="hidden md:table-cell">
                                                <span class="pill"><?php echo htmlspecialchars($doc['document_type'] ?? 'Uncategorized'); ?></span>
                                            </td>
                                            <td class="hidden lg:table-cell" style="color:var(--text-secondary);"><?php echo htmlspecialchars($doc['origin'] ?? '—'); ?></td>
                                            <td class="hidden md:table-cell whitespace-nowrap" style="color:var(--text-secondary);">
                                                <?php echo formatTimestampDisplay($doc['received_timestamp'] ?? null); ?>
                                            </td>
                                            <td>
                                                <?php if ($isPending): ?>
                                                    <span class="badge badge-orange">Awaiting Distribution</span>
                                                <?php else: ?>
                                                    <span class="badge badge-green">Distributed</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="row-actions">
                                                    <button type="button" class="icon-btn primary" onclick="openViewModal(<?php echo $doc['id']; ?>)" title="View details">
                                                        <i class="fa-regular fa-eye"></i>
                                                    </button>
                                                    <?php if ($isPending): ?>
                                                        <button type="button" class="icon-btn green" onclick="openEditModal(<?php echo $doc['id']; ?>)" title="Distribute / edit">
                                                            <i class="fa-solid fa-share-from-square"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <div class="dropdown">
                                                        <button type="button" class="icon-btn" onclick="toggleDropdown(this)" title="More actions">
                                                            <i class="fa-solid fa-ellipsis-vertical"></i>
                                                        </button>
                                                        <div class="dropdown-menu">
                                                            <button class="dropdown-item" onclick="openEditModal(<?php echo $doc['id']; ?>)">
                                                                <i class="fa-regular fa-pen-to-square"></i> Edit Document
                                                            </button>
                                                            <a class="dropdown-item" href="distribution.php">
                                                                <i class="fa-solid fa-share-from-square"></i> Distribute
                                                            </a>
                                                            <a class="dropdown-item" href="documents_distribution_history.php">
                                                                <i class="fa-solid fa-clock-rotate-left"></i> View History
                                                            </a>
                                                            <div class="dropdown-divider"></div>
                                                            <button class="dropdown-item danger" onclick="openDeleteModal(<?php echo $doc['id']; ?>, '<?php echo htmlspecialchars(addslashes($doc['document_name'])); ?>')">
                                                                <i class="fa-regular fa-trash-can"></i> Delete
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7">
                                            <div class="empty-state">
                                                <div class="empty-state-icon"><i class="fa-regular fa-folder-open"></i></div>
                                                <div class="empty-state-title">No documents yet</div>
                                                <div class="empty-state-text">Receive your first document to start tracking.</div>
                                                <button onclick="openAddDocumentModal()" class="btn btn-primary mt-3">
                                                    <i class="fa-solid fa-plus"></i> Receive Document
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- No Results -->
                    <div id="noResultsMessage" class="hidden" style="padding:0;">
                        <div class="empty-state">
                            <div class="empty-state-icon"><i class="fa-regular fa-circle-xmark"></i></div>
                            <div class="empty-state-title">No matching documents</div>
                            <div class="empty-state-text">Try adjusting your search or filters.</div>
                            <button onclick="resetFilters()" class="btn btn-soft mt-3">Clear filters</button>
                        </div>
                    </div>

                    <!-- Pagination -->
                    <div id="documentsPagination" class="pagination-shell <?php echo (!$documents_result || $documents_result->num_rows === 0) ? 'hidden' : ''; ?>">
                        <div class="pagination-meta">
                            <div id="documentsPaginationTitle" class="pagination-title"></div>
                            <div id="documentsPaginationInfo" class="mt-1"></div>
                        </div>
                        <div class="pagination-controls">
                            <div id="documentsPaginationPage" class="pagination-page-indicator"></div>
                            <div class="pagination" id="documentsPaginationControls"></div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Document Modal -->
    <div id="addDocumentModal" class="modal-backdrop" style="display:none;">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title">Receive Document</h3>
                <button type="button" class="modal-close" onclick="closeAddDocumentModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form id="addDocumentForm" onsubmit="return false;">
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-field span-2">
                            <label class="label">Document Name <span class="req">*</span></label>
                            <input type="text" id="add_document_name" required class="input" autocomplete="off" placeholder="e.g. Senate Bill No. 1234">
                        </div>
                        <div class="form-field span-2">
                            <label class="label">Document Type <span class="req">*</span></label>
                            <select id="add_type_id" required class="select">
                                <option value="">Select Type</option>
                                <?php foreach ($document_types as $type): ?>
                                    <option value="<?php echo $type['id']; ?>"><?php echo htmlspecialchars($type['type_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-field">
                            <label class="label">Number of Copies <span class="req">*</span></label>
                            <input type="number" id="add_copies_received" required min="1" value="1" class="input" autocomplete="off">
                        </div>
                        <div class="form-field">
                            <label class="label">Received Timestamp <span class="req">*</span></label>
                            <input type="datetime-local" id="add_date_received" required class="input" autocomplete="off">
                        </div>
                        <div class="form-field span-2">
                            <label class="label">Origin / Source</label>
                            <input type="text" id="add_origin" class="input" autocomplete="off" placeholder="e.g. Office of the President">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeAddDocumentModal()" class="btn btn-soft">Cancel</button>
                    <button type="button" onclick="submitAddDocument()" id="addDocumentSubmitBtn" class="btn btn-primary">
                        <i class="fa-solid fa-plus"></i> Add Document
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Document Modal -->
    <div id="viewDocumentModal" class="modal-backdrop" style="display:none;">
        <div class="modal-dialog">
            <div class="modal-header">
                <div class="flex items-center gap-3">
                    <div class="stat-icon blue" style="margin:0;"><i class="fa-regular fa-file-lines"></i></div>
                    <div>
                        <h3 class="modal-title">Document Details</h3>
                        <div class="text-xs text-[#7d8398]" id="view_serial_display"></div>
                    </div>
                </div>
                <button type="button" class="modal-close" onclick="closeViewModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <div class="grid grid-cols-2 gap-5">
                    <div class="col-span-2">
                        <div class="label">Document Name</div>
                        <div class="text-sm font-medium text-[#1b2a4a]" id="view_document_name"></div>
                    </div>
                    <div>
                        <div class="label">Document Type</div>
                        <span id="view_type_badge" class="pill"></span>
                    </div>
                    <div>
                        <div class="label">Total Copies</div>
                        <div class="text-sm font-mono" id="view_copies"></div>
                    </div>
                    <div>
                        <div class="label">Origin / Source</div>
                        <div class="text-sm" id="view_origin"></div>
                    </div>
                    <div>
                        <div class="label">System ID</div>
                        <div class="text-sm font-mono text-[#7d8398]" id="view_id"></div>
                    </div>
                    <div class="col-span-2">
                        <div class="label">Received</div>
                        <div class="text-sm" id="view_date"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeViewModal()" class="btn btn-soft">Close</button>
                <button type="button" id="view_edit_btn" class="btn btn-primary">
                    <i class="fa-regular fa-pen-to-square"></i> Edit Document
                </button>
            </div>
        </div>
    </div>

    <!-- Edit Document Modal -->
    <div id="editDocumentModal" class="modal-backdrop" style="display:none;">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title">Edit Document</h3>
                <button type="button" class="modal-close" onclick="closeEditModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form id="editDocumentForm" onsubmit="return false;">
                <input type="hidden" id="edit_id">
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-field span-2">
                            <label class="label">Document Name <span class="req">*</span></label>
                            <input type="text" id="edit_document_name" required class="input" autocomplete="off">
                        </div>
                        <div class="form-field span-2">
                            <label class="label">Document Type <span class="req">*</span></label>
                            <select id="edit_type_id" required class="select">
                                <option value="">Select Type</option>
                                <?php foreach ($document_types as $type): ?>
                                    <option value="<?php echo $type['id']; ?>"><?php echo htmlspecialchars($type['type_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-field">
                            <label class="label">Number of Copies <span class="req">*</span></label>
                            <input type="number" id="edit_copies_received" required min="1" class="input" autocomplete="off">
                        </div>
                        <div class="form-field">
                            <label class="label">Received <span class="req">*</span></label>
                            <input type="datetime-local" id="edit_date_received" required class="input" autocomplete="off">
                        </div>
                        <div class="form-field span-2">
                            <label class="label">Origin</label>
                            <input type="text" id="edit_origin" class="input" autocomplete="off">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeEditModal()" class="btn btn-soft">Cancel</button>
                    <button type="button" onclick="submitEditDocument()" id="editDocumentSubmitBtn" class="btn btn-primary">
                        <i class="fa-regular fa-floppy-disk"></i> Update Document
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteDocumentModal" class="modal-backdrop" style="display:none;">
        <div class="modal-dialog sm">
            <div class="modal-header">
                <h3 class="modal-title">Delete Document</h3>
                <button type="button" class="modal-close" onclick="closeDeleteModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <p style="font-size:13px;color:var(--text-secondary);">
                    Are you sure you want to delete <strong style="color:var(--text);" id="deleteDocNameDisplay"></strong>?
                </p>
                <div class="alert alert-red mt-3" style="margin-bottom:0;">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span>This will also delete all distribution records associated with this document. This cannot be undone.</span>
                </div>
            </div>
            <input type="hidden" id="delete_id">
            <div class="modal-footer">
                <button type="button" onclick="closeDeleteModal()" class="btn btn-soft">Cancel</button>
                <button type="button" onclick="confirmDeleteDocument()" id="deleteDocumentBtn" class="btn btn-danger">
                    <i class="fa-regular fa-trash-can"></i> Delete Document
                </button>
            </div>
        </div>
    </div>

    <!-- Toast Container -->
    <div id="toastContainer"></div>

    <script>
        // Store document data
        let documents = [];

        // Add Document Modal Functions
        function openAddDocumentModal() {
            document.getElementById('add_document_name').value = '';
            document.getElementById('add_type_id').value = '';
            document.getElementById('add_origin').value = '';
            document.getElementById('add_copies_received').value = '1';
            document.getElementById('add_date_received').value = '<?php echo date('Y-m-d\TH:i'); ?>';
            MailroomModal.open('addDocumentModal');
        }

        function closeAddDocumentModal() { MailroomModal.close('addDocumentModal'); }

        function submitAddDocument() {
            const document_name = document.getElementById('add_document_name').value.trim();
            const type_id = document.getElementById('add_type_id').value;
            const origin = document.getElementById('add_origin').value.trim();
            const copies_received = parseInt(document.getElementById('add_copies_received').value);
            const date_received = document.getElementById('add_date_received').value;

            if (!document_name) { MailroomToast.warning('Please enter document name'); return; }
            if (!type_id) { MailroomToast.warning('Please select a document type'); return; }
            if (!copies_received || copies_received < 1) { MailroomToast.warning('Number of copies must be at least 1'); return; }
            if (!date_received) { MailroomToast.warning('Please select date received'); return; }

            const submitBtn = document.getElementById('addDocumentSubmitBtn');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<span class="spinner" style="border-top-color:#fff"></span> Saving...';
            submitBtn.disabled = true;

            fetch('<?php echo $_SERVER['PHP_SELF']; ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `ajax_action=add_document&document_name=${encodeURIComponent(document_name)}&type_id=${type_id}&origin=${encodeURIComponent(origin)}&copies_received=${copies_received}&date_received=${date_received}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        MailroomToast.show(data.message, 'success');
                        closeAddDocumentModal();
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        MailroomToast.show(data.message, 'error');
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }
                })
                .catch(error => {
                    MailroomToast.show('An error occurred. Please try again.', 'error');
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                });
        }

        // Filter Functions
        const documentsPageSize = 10;
        let documentsCurrentPage = 1;
        let filterDebounceTimer;

        function getVisibleDocumentRows() {
            return Array.from(document.querySelectorAll('.document-row')).filter(row => row.dataset.filtered !== 'false');
        }

        function renderDocumentsPagination() {
            const visibleRows = getVisibleDocumentRows();
            const totalRows = visibleRows.length;
            const totalPages = Math.max(1, Math.ceil(totalRows / documentsPageSize));
            const paginationTitle = document.getElementById('documentsPaginationTitle');
            const paginationInfo = document.getElementById('documentsPaginationInfo');
            const paginationPage = document.getElementById('documentsPaginationPage');
            const paginationControls = document.getElementById('documentsPaginationControls');
            const paginationWrapper = document.getElementById('documentsPagination');

            if (!paginationInfo || !paginationControls || !paginationWrapper) return;

            if (documentsCurrentPage > totalPages) documentsCurrentPage = totalPages;

            const startIndex = (documentsCurrentPage - 1) * documentsPageSize;
            const endIndex = startIndex + documentsPageSize;

            document.querySelectorAll('.document-row').forEach(row => { row.style.display = 'none'; });

            visibleRows.forEach((row, index) => {
                row.style.display = index >= startIndex && index < endIndex ? '' : 'none';
            });

            if (totalRows === 0) {
                if (paginationTitle) paginationTitle.textContent = '';
                paginationInfo.textContent = 'No matching documents';
                if (paginationPage) paginationPage.textContent = '';
                paginationControls.innerHTML = '';
                paginationWrapper.classList.add('hidden');
                return;
            }

            const from = startIndex + 1;
            const to = Math.min(endIndex, totalRows);
            const visibleCount = Math.max(0, to - startIndex);

            if (paginationTitle) paginationTitle.textContent = `Showing ${visibleCount} ${visibleCount === 1 ? 'document' : 'documents'}`;
            paginationInfo.textContent = `Records ${from}-${to} of ${totalRows} total`;
            if (paginationPage) paginationPage.textContent = `Page ${documentsCurrentPage} of ${totalPages}`;
            paginationWrapper.classList.toggle('hidden', totalRows <= documentsPageSize);

            const startPage = Math.max(1, documentsCurrentPage - 2);
            const endPage = Math.min(totalPages, documentsCurrentPage + 2);
            let controlsHtml = `
                <button class="pagination-item compact ${documentsCurrentPage === 1 ? 'disabled' : ''}" ${documentsCurrentPage === 1 ? 'disabled' : ''} onclick="changeDocumentsPage(1)" aria-label="First page">
                    <i class="fa-solid fa-chevrons-left"></i>
                </button>
                <button class="pagination-item compact ${documentsCurrentPage === 1 ? 'disabled' : ''}" ${documentsCurrentPage === 1 ? 'disabled' : ''} onclick="changeDocumentsPage(${documentsCurrentPage - 1})" aria-label="Previous page">
                    <i class="fa-solid fa-chevron-left"></i>
                </button>
            `;

            if (startPage > 1) {
                controlsHtml += `<button class="pagination-item" onclick="changeDocumentsPage(1)">1</button>`;
                if (startPage > 2) controlsHtml += `<span class="pagination-ellipsis">...</span>`;
            }

            for (let i = startPage; i <= endPage; i++) {
                controlsHtml += `<button class="pagination-item ${i === documentsCurrentPage ? 'active' : ''}" onclick="changeDocumentsPage(${i})">${i}</button>`;
            }

            if (endPage < totalPages) {
                if (endPage < totalPages - 1) controlsHtml += `<span class="pagination-ellipsis">...</span>`;
                controlsHtml += `<button class="pagination-item" onclick="changeDocumentsPage(${totalPages})">${totalPages}</button>`;
            }

            controlsHtml += `
                <button class="pagination-item compact ${documentsCurrentPage === totalPages ? 'disabled' : ''}" ${documentsCurrentPage === totalPages ? 'disabled' : ''} onclick="changeDocumentsPage(${documentsCurrentPage + 1})" aria-label="Next page">
                    <i class="fa-solid fa-chevron-right"></i>
                </button>
                <button class="pagination-item compact ${documentsCurrentPage === totalPages ? 'disabled' : ''}" ${documentsCurrentPage === totalPages ? 'disabled' : ''} onclick="changeDocumentsPage(${totalPages})" aria-label="Last page">
                    <i class="fa-solid fa-chevrons-right"></i>
                </button>
            `;
            paginationControls.innerHTML = controlsHtml;
        }

        function changeDocumentsPage(page) {
            documentsCurrentPage = Math.max(1, page);
            renderDocumentsPagination();
        }

        function getSearchTokens(value) {
            return value.toLowerCase().split(/\s+/).filter(Boolean);
        }

        function applyFilters(showFeedback = true) {
            const typeFilter = document.getElementById('typeFilter').value.toLowerCase();
            const searchTokens = getSearchTokens(document.getElementById('searchInput').value);

            const rows = document.querySelectorAll('.document-row');
            let visibleCount = 0;

            rows.forEach(row => {
                const docType = row.getAttribute('data-type');
                const searchText = row.getAttribute('data-search') || '';

                let typeMatch = !typeFilter || docType.includes(typeFilter);
                let searchMatch = searchTokens.length === 0 || searchTokens.every(token => searchText.includes(token));

                if (typeMatch && searchMatch) {
                    row.dataset.filtered = 'true';
                    visibleCount++;
                } else {
                    row.dataset.filtered = 'false';
                    row.style.display = 'none';
                }
            });

            const table = document.getElementById('documentsTable');
            const noResults = document.getElementById('noResultsMessage');

            if (visibleCount === 0) {
                table.classList.add('hidden');
                noResults.classList.remove('hidden');
            } else {
                table.classList.remove('hidden');
                noResults.classList.add('hidden');
            }

            documentsCurrentPage = 1;
            renderDocumentsPagination();
        }

        function resetFilters() {
            document.getElementById('typeFilter').value = '';
            document.getElementById('searchInput').value = '';

            const rows = document.querySelectorAll('.document-row');
            rows.forEach(row => { row.dataset.filtered = 'true'; row.style.display = ''; });

            document.getElementById('documentsTable').classList.remove('hidden');
            document.getElementById('noResultsMessage').classList.add('hidden');
            documentsCurrentPage = 1;
            renderDocumentsPagination();
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
                if (columnIndex === 5) {
                    const aNum = parseInt(aCol) || 0;
                    const bNum = parseInt(bCol) || 0;
                    return sortDirection === 'asc' ? aNum - bNum : bNum - aNum;
                }
                const comparison = aCol.localeCompare(bCol);
                return sortDirection === 'asc' ? comparison : -comparison;
            });

            tbody.innerHTML = '';
            rows.forEach(row => tbody.appendChild(row));
            renderDocumentsPagination();
        }

        document.getElementById('searchInput')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') applyFilters();
        });

        document.getElementById('searchInput')?.addEventListener('input', function() {
            clearTimeout(filterDebounceTimer);
            filterDebounceTimer = setTimeout(() => applyFilters(false), 180);
        });

        document.getElementById('typeFilter')?.addEventListener('change', function() {
            applyFilters(false);
        });

        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.document-row').forEach(row => { row.dataset.filtered = 'true'; });
            renderDocumentsPagination();
        });

        // View Modal Functions
        function openViewModal(id) {
            fetch('documents.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `ajax_action=get_document&id=${id}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const doc = data.document;
                        document.getElementById('view_id').textContent = '#' + doc.id;
                        document.getElementById('view_document_name').textContent = doc.document_name;
                        document.getElementById('view_type_badge').textContent = (doc.type_name || 'Uncategorized');
                        document.getElementById('view_origin').textContent = doc.origin || 'N/A';
                        document.getElementById('view_copies').textContent = doc.copies_received;

                        if (doc.created_at || doc.date_received) {
                            const dateStr = doc.created_at || doc.date_received;
                            const dt = new Date(dateStr);
                            document.getElementById('view_date').textContent = dt.toLocaleString('en-US', {
                                month: 'short', day: 'numeric', year: 'numeric',
                                hour: 'numeric', minute: '2-digit', hour12: true
                            });
                        } else {
                            document.getElementById('view_date').textContent = 'N/A';
                        }

                        const serial = doc.serial_number || ('DOC-' + doc.id.toString().padStart(6, '0'));
                        document.getElementById('view_serial_display').textContent = serial;

                        document.getElementById('view_edit_btn').onclick = function() {
                            closeViewModal();
                            openEditModal(doc.id);
                        };

                        MailroomModal.open('viewDocumentModal');
                    } else {
                        MailroomToast.show(data.message, 'error');
                    }
                })
                .catch(error => MailroomToast.show('Error fetching document details', 'error'));
        }

        function closeViewModal() { MailroomModal.close('viewDocumentModal'); }

        // Edit Modal Functions
        function openEditModal(id) {
            fetch('documents.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `ajax_action=get_document&id=${id}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const doc = data.document;
                        document.getElementById('edit_id').value = doc.id;
                        document.getElementById('edit_document_name').value = doc.document_name;
                        document.getElementById('edit_type_id').value = doc.type_id;
                        document.getElementById('edit_origin').value = doc.origin || '';
                        document.getElementById('edit_copies_received').value = doc.copies_received;

                        if (doc.created_at) {
                            const dt = new Date(doc.created_at);
                            const localDt = new Date(dt.getTime() - (dt.getTimezoneOffset() * 60000)).toISOString().slice(0, 16);
                            document.getElementById('edit_date_received').value = localDt;
                        } else if (doc.date_received) {
                            document.getElementById('edit_date_received').value = doc.date_received + 'T00:00';
                        }

                        MailroomModal.open('editDocumentModal');
                    } else {
                        MailroomToast.show(data.message, 'error');
                    }
                })
                .catch(error => MailroomToast.show('Error fetching document details', 'error'));
        }

        function closeEditModal() { MailroomModal.close('editDocumentModal'); }

        function submitEditDocument() {
            const id = document.getElementById('edit_id').value;
            const document_name = document.getElementById('edit_document_name').value.trim();
            const type_id = document.getElementById('edit_type_id').value;
            const origin = document.getElementById('edit_origin').value.trim();
            const copies_received = parseInt(document.getElementById('edit_copies_received').value);
            const date_received = document.getElementById('edit_date_received').value;

            if (!id || !document_name || !type_id || !copies_received || !date_received) {
                MailroomToast.show('Please fill all required fields', 'warning');
                return;
            }

            const submitBtn = document.getElementById('editDocumentSubmitBtn');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<span class="spinner" style="border-top-color:#fff"></span> Updating...';
            submitBtn.disabled = true;

            fetch('documents.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `ajax_action=edit_document&id=${id}&document_name=${encodeURIComponent(document_name)}&type_id=${type_id}&origin=${encodeURIComponent(origin)}&copies_received=${copies_received}&date_received=${date_received}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        MailroomToast.show(data.message, 'success');
                        closeEditModal();
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        MailroomToast.show(data.message, 'error');
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }
                })
                .catch(error => {
                    MailroomToast.show('An error occurred', 'error');
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                });
        }

        // Delete Modal Functions
        function openDeleteModal(id, name) {
            document.getElementById('delete_id').value = id;
            document.getElementById('deleteDocNameDisplay').textContent = name;
            MailroomModal.open('deleteDocumentModal');
        }

        function closeDeleteModal() { MailroomModal.close('deleteDocumentModal'); }

        function confirmDeleteDocument() {
            const id = document.getElementById('delete_id').value;
            if (!id) return;

            const btn = document.getElementById('deleteDocumentBtn');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<span class="spinner" style="border-top-color:#fff"></span> Deleting...';
            btn.disabled = true;

            fetch('documents.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `ajax_action=delete_document&id=${id}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        MailroomToast.show(data.message, 'success');
                        closeDeleteModal();
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        MailroomToast.show(data.message, 'error');
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                    }
                })
                .catch(error => {
                    MailroomToast.show('An error occurred', 'error');
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                });
        }
    </script>
    <script src="assets/app.js"></script>
</body>

</html>