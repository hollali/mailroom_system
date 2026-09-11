<?php
// newspaper_distribution.php - Distribute newspapers by category (Once per day per recipient)

require_once './config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/audit.php';
session_start();

// Handle Distribution Form Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['distribute_submit'])) {
    csrf_check_post();
    $distributed_by = trim($_POST['distributed_by']);

    // Handle recipient_ids
    $recipient_ids = [];
    if (isset($_POST['recipient_ids'])) {
        if (is_array($_POST['recipient_ids'])) {
            $recipient_ids = $_POST['recipient_ids'];
        } elseif (is_string($_POST['recipient_ids']) && !empty($_POST['recipient_ids'])) {
            $recipient_ids = explode(',', $_POST['recipient_ids']);
        }
    }

    // Handle selected_categories - it might be a string or array
    $selected_categories = [];
    if (isset($_POST['selected_categories'])) {
        if (is_array($_POST['selected_categories'])) {
            $selected_categories = $_POST['selected_categories'];
        } elseif (is_string($_POST['selected_categories']) && !empty($_POST['selected_categories'])) {
            $selected_categories = explode(',', $_POST['selected_categories']);
        }
    }

    $date_distributed = date('Y-m-d');

    if (empty($recipient_ids)) {
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => "Please select at least one recipient"
        ];
        header('Location: newspaper_distribution.php');
        exit();
    }

    if (empty($selected_categories)) {
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => "No categories selected for distribution"
        ];
        header('Location: newspaper_distribution.php');
        exit();
    }

    $conn->begin_transaction();

    try {
        $paper_names = [];
        $newspaper_ids_str = implode(',', $selected_categories);
        foreach ($selected_categories as $paper_id) {
            $paper_id = (int)$paper_id;
            $paper_result = $conn->query("SELECT newspaper_name, newspaper_number FROM newspapers WHERE id = $paper_id");
            $paper = $paper_result->fetch_assoc();
            if ($paper) {
                $paper_names[] = $paper['newspaper_name'] . ($paper['newspaper_number'] ? ' (Issue: ' . $paper['newspaper_number'] . ')' : '');
            }
        }

        $success_count = count($selected_categories);
        $newspapers_str = implode(', ', $paper_names);

        $success_recipients = [];
        $failed_recipients = [];
        $already_received = [];
        $skipped_no_stock = [];

        foreach ($recipient_ids as $recipient_id) {
            $recipient_id = (int)$recipient_id;

            // Get recipient details
            $recipient_query = $conn->query("SELECT name FROM recipients WHERE id = $recipient_id AND COALESCE(is_active, 1) = 1");
            if (!$recipient = $recipient_query->fetch_assoc()) {
                continue;
            }

            $full_name = $recipient['name'];
            $individual_name = $full_name;
            $department = '';
            if (strpos($full_name, ' - ') !== false) {
                $parts = explode(' - ', $full_name, 2);
                $individual_name = $parts[0];
                $department = $parts[1];
            }

            // CHECK IF RECIPIENT ALREADY RECEIVED DISTRIBUTION TODAY
            $check_today_stmt = $conn->prepare("SELECT id FROM distribution WHERE distributed_to = ? AND department = ? AND date_distributed = ? AND (categories_list IS NOT NULL OR newspapers_list IS NOT NULL)");
            $check_today_stmt->bind_param("sss", $individual_name, $department, $date_distributed);
            $check_today_stmt->execute();
            $today_distribution = $check_today_stmt->get_result()->fetch_assoc();
            $check_today_stmt->close();

            if ($today_distribution) {
                $already_received[] = $individual_name;
                continue;
            }

            // Determine which selected papers are actually available for this specific recipient
            $actual_distributed_ids = [];
            $actual_distributed_names = [];

            foreach ($selected_categories as $paper_id) {
                $pid = (int)$paper_id;
                $stock_query = $conn->query("
                    SELECT available_copies, total_copies, newspaper_name, newspaper_number
                    FROM newspapers
                    WHERE id = $pid
                      AND COALESCE(available_copies, 0) > 0
                      AND COALESCE(status, 'available') <> 'archived'
                ");
                if ($paper_info = $stock_query->fetch_assoc()) {
                    $actual_distributed_ids[] = $pid;
                    $actual_distributed_names[] = $paper_info['newspaper_name'] . ($paper_info['newspaper_number'] ? ' (Issue: ' . $paper_info['newspaper_number'] . ')' : '');
                }
            }

            if (empty($actual_distributed_ids)) {
                $skipped_no_stock[] = $individual_name;
                continue;
            }

            $current_newspaper_ids_str = implode(',', $actual_distributed_ids);
            $current_newspapers_str = implode(', ', $actual_distributed_names);
            $current_success_count = count($actual_distributed_ids);

            // Insert new distribution record
            $stmt = $conn->prepare("INSERT INTO distribution (distributed_to, department, copies, date_distributed, distributed_by, newspapers_list, newspaper_ids) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssissss", $individual_name, $department, $current_success_count, $date_distributed, $distributed_by, $current_newspapers_str, $current_newspaper_ids_str);

            if ($stmt->execute()) {
                $dist_id = (int)$stmt->insert_id;
                audit_log('distribute', 'distribution', $dist_id, "Distributed $current_success_count newspaper copies to '$individual_name' ($department) on $date_distributed", $distributed_by);
                $success_recipients[] = $individual_name;
                // Deduct copies and update status for each distributed paper
                foreach ($actual_distributed_ids as $pid) {
                    $conn->query("UPDATE newspapers SET 
                        available_copies = GREATEST(0, available_copies - 1),
                        status = CASE 
                            WHEN (available_copies - 1) <= 0 THEN 'distributed' 
                            WHEN (available_copies - 1) < total_copies THEN 'partial'
                            ELSE 'available'
                        END
                        WHERE id = $pid");
                }
            } else {
                $failed_recipients[] = $individual_name;
            }
            $stmt->close();
        }

        $conn->commit();

        $message = "Distributed to " . count($success_recipients) . " recipient(s).";
        if (!empty($already_received)) {
            $message .= " (" . count($already_received) . " skipped as already received).";
        }
        if (!empty($skipped_no_stock)) {
            $message .= " (" . count($skipped_no_stock) . " skipped due to no stock).";
        }

        if (count($success_recipients) == 0 && (!empty($already_received) || !empty($skipped_no_stock))) {
            $_SESSION['toast'] = ['type' => 'error', 'message' => "Skipped distributions. Recipients already received or out of stock."];
            header('Location: newspaper_distribution.php');
            exit();
        }

        if (count($success_recipients) > 0) {
            $_SESSION['toast'] = [
                'type' => 'success',
                'message' => $message
            ];
        } else {
            $_SESSION['toast'] = [
                'type' => 'error',
                'message' => "No distributions were made."
            ];
        }
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => "Distribution failed: " . $e->getMessage()
        ];
    }

    header('Location: newspaper_distribution.php');
    exit();
}

// Get all active recipients from recipients.php.
$recipients = $conn->query("
    SELECT id, name
    FROM recipients
    WHERE COALESCE(is_active, 1) = 1
    ORDER BY name
");
$recipients_error = $recipients ? '' : $conn->error;

// Get all available newspapers from list.php.
$categories_for_distribution = $conn->query("
    SELECT id, newspaper_name, newspaper_number, COALESCE(available_copies, 0) AS available_copies
    FROM newspapers
    WHERE COALESCE(available_copies, 0) > 0
      AND COALESCE(status, 'available') <> 'archived'
    ORDER BY newspaper_name, newspaper_number
");
$newspapers_error = $categories_for_distribution ? '' : $conn->error;

// Get recipients who already received distribution today
$already_received_today = [];
$today_recipients_query = $conn->query("
    SELECT DISTINCT distributed_to, department 
    FROM distribution 
    WHERE date_distributed = CURDATE() 
    AND (categories_list IS NOT NULL OR newspapers_list IS NOT NULL)
    ORDER BY distributed_to
");
while ($row = $today_recipients_query->fetch_assoc()) {
    $name = $row['distributed_to'];
    if ($row['department']) {
        $name .= ' (' . $row['department'] . ')';
    }
    $already_received_today[] = $name;
}

$already_received_lookup = array_fill_keys($already_received_today, true);

// Gather today's distribution status for CSV export
$today_distribution_export = [];
$recipients_export_query = $conn->query("
    SELECT id, name
    FROM recipients
    WHERE COALESCE(is_active, 1) = 1
    ORDER BY name
");
$today_dist_query = $conn->query("
    SELECT distributed_to, department, copies, newspapers_list
    FROM distribution
    WHERE date_distributed = CURDATE()
      AND (categories_list IS NOT NULL OR newspapers_list IS NOT NULL)
");
$today_dists = [];
if ($today_dist_query) {
    while ($row = $today_dist_query->fetch_assoc()) {
        $key = $row['distributed_to'];
        if ($row['department']) {
            $key .= ' - ' . $row['department'];
        }
        $today_dists[$key] = [
            'copies' => $row['copies'],
            'list' => $row['newspapers_list']
        ];
    }
}
if ($recipients_export_query) {
    while ($row = $recipients_export_query->fetch_assoc()) {
        $full_name = $row['name'];
        $individual_name = $full_name;
        $department = '';
        if (strpos($full_name, ' - ') !== false) {
            $parts = explode(' - ', $full_name, 2);
            $individual_name = $parts[0];
            $department = $parts[1];
        }
        
        $received = 'No';
        $copies = 0;
        $list = '';
        
        if (isset($today_dists[$full_name])) {
            $received = 'Yes';
            $copies = $today_dists[$full_name]['copies'];
            $list = $today_dists[$full_name]['list'] ?? '';
        } elseif (isset($today_dists[$individual_name])) {
            $received = 'Yes';
            $copies = $today_dists[$individual_name]['copies'];
            $list = $today_dists[$individual_name]['list'] ?? '';
        }
        
        $today_distribution_export[] = [
            'name' => $individual_name,
            'department' => $department,
            'received_today' => $received,
            'copies' => $copies,
            'newspapers' => $list
        ];
    }
}

// Get toast message from session
$toast = null;
if (isset($_SESSION['toast'])) {
    $toast = $_SESSION['toast'];
    unset($_SESSION['toast']);
}

$available_papers_count = $categories_for_distribution ? $categories_for_distribution->num_rows : 0;
$active_recipients_count = $recipients ? $recipients->num_rows : 0;
$already_received_count = count($already_received_today);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Newspaper Distribution - Mailroom Ops</title>
    <link rel="icon" type="image/png" href="./images/logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/app.css">
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
                        <span>Newspaper Distribution</span>
                    </div>
                    <h1 class="page-header-title">Newspaper Distribution</h1>
                    <p class="page-header-subtitle">Select subscriptions and recipients to distribute.</p>
                </div>
                <div class="header-actions flex items-center gap-2">
                    <button type="button" onclick="exportTodayStatus()" class="btn btn-soft">
                        <i class="fa-regular fa-file-excel"></i>
                        <span class="hidden sm:inline">Export CSV</span>
                    </button>
                    <button id="distributeBtn" class="btn btn-primary" onclick="openDistributeModal()" disabled>
                        <i class="fa-solid fa-hand-holding-hand"></i>
                        <span class="hidden sm:inline">Distribute</span>
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

                <div class="flex flex-wrap gap-2 mb-5">
                    <span class="filter-chip"><i class="fa-solid fa-newspaper"></i> <?php echo $available_papers_count; ?> papers available</span>
                    <span class="filter-chip"><i class="fa-regular fa-user"></i> <?php echo $active_recipients_count; ?> recipients active</span>
                    <?php if ($already_received_count > 0): ?>
                        <span class="filter-chip"><i class="fa-solid fa-clock-rotate-left" style="color:var(--orange);"></i> <?php echo $already_received_count; ?> already received today</span>
                    <?php endif; ?>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="card card-unclip">
                        <div class="card-header">
                            <div>
                                <div class="card-title">1. Select Subscriptions</div>
                                <div class="card-subtitle">Choose the papers to distribute.</div>
                            </div>
                            <span class="filter-chip"><span id="selectedCountBadge">0</span> selected</span>
                        </div>
                        <div class="card-body">
                            <div class="dropdown w-full">
                                <button type="button" class="input w-full" style="text-align:left;display:flex;align-items:center;justify-content:space-between;cursor:pointer;"
                                    onclick="toggleDropdown(this)">
                                    <span class="block truncate" id="subscriptionsDropdownText">Select subscriptions...</span>
                                    <i class="fa-solid fa-chevron-down" style="color:var(--text-faint);font-size:11px;"></i>
                                </button>
                                <div class="dropdown-menu" style="left:0;right:auto;width:100%;top:calc(100% + 6px);padding:0;">
                                    <div style="padding:10px 12px;border-bottom:1px solid var(--border);">
                                        <input type="text" class="input" placeholder="Search papers..." oninput="filterSelectOptions(this, 'subOption')" autocomplete="off">
                                    </div>
                                    <div class="flex gap-2" style="padding:10px 12px;border-bottom:1px solid var(--border);">
                                        <button type="button" class="btn btn-soft btn-sm" onclick="selectAllOptions('selected_categories_chk[]')">
                                            <i class="fa-regular fa-square-check"></i> Select all
                                        </button>
                                        <button type="button" class="btn btn-ghost btn-sm" onclick="clearOptions('selected_categories_chk[]')">
                                            <i class="fa-regular fa-square"></i> Clear
                                        </button>
                                    </div>
                                    <div class="max-h-60 overflow-auto">
                                        <?php
                                        if ($newspapers_error) {
                                            echo "<div class='px-4 py-2' style='color:var(--red);'>Could not load newspapers: " . htmlspecialchars($newspapers_error) . "</div>";
                                        } elseif ($categories_for_distribution && $categories_for_distribution->num_rows > 0) {
                                            $categories_for_distribution->data_seek(0);
                                            while ($paper = $categories_for_distribution->fetch_assoc()) {
                                                $clean_label = htmlspecialchars($paper['newspaper_name']);
                                                if ($paper['newspaper_number']) $clean_label .= ' (Issue: ' . htmlspecialchars($paper['newspaper_number']) . ')';
                                                $display_label = $clean_label . ' - ' . $paper['available_copies'] . ' left';

                                                echo "<label class='subOption search-item flex items-center px-4 py-2 cursor-pointer' style='color:var(--text-secondary);' data-label='" . htmlspecialchars($clean_label, ENT_QUOTES) . "' data-search='" . htmlspecialchars($display_label, ENT_QUOTES) . "'>";
                                                echo "<input type='checkbox' name='selected_categories_chk[]' value='{$paper['id']}' class='mr-3 h-4 w-4' style='accent-color:var(--accent);' onchange='updateSelectionCount()'>";
                                                echo "<span class='search-text'>{$display_label}</span>";
                                                echo "</label>";
                                            }
                                        } else {
                                            echo "<div class='px-4 py-2' style='color:var(--text-muted);'>No available newspapers found</div>";
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                            <div id="subscriptionsChips" class="flex flex-wrap gap-2 mt-3"></div>
                        </div>
                    </div>

                    <div class="card card-unclip">
                        <div class="card-header">
                            <div>
                                <div class="card-title">2. Select Recipients</div>
                                <div class="card-subtitle">Choose who should receive today's papers.</div>
                            </div>
                            <span class="filter-chip"><span id="selectedRecipientCountBadge">0</span> selected</span>
                        </div>
                        <div class="card-body">
                            <div class="dropdown w-full">
                                <button type="button" class="input w-full" style="text-align:left;display:flex;align-items:center;justify-content:space-between;cursor:pointer;"
                                    onclick="toggleDropdown(this)">
                                    <span class="block truncate" id="recipientsDropdownText">Select recipients...</span>
                                    <i class="fa-solid fa-chevron-down" style="color:var(--text-faint);font-size:11px;"></i>
                                </button>
                                <div class="dropdown-menu" style="left:0;right:auto;width:100%;top:calc(100% + 6px);padding:0;">
                                    <div style="padding:10px 12px;border-bottom:1px solid var(--border);">
                                        <input type="text" class="input" placeholder="Search recipients..." oninput="filterSelectOptions(this, 'recOption')" autocomplete="off">
                                    </div>
                                    <div class="flex gap-2" style="padding:10px 12px;border-bottom:1px solid var(--border);">
                                        <button type="button" class="btn btn-soft btn-sm" onclick="selectAllOptions('recipient_ids_chk[]')">
                                            <i class="fa-regular fa-square-check"></i> Select all
                                        </button>
                                        <button type="button" class="btn btn-ghost btn-sm" onclick="clearOptions('recipient_ids_chk[]')">
                                            <i class="fa-regular fa-square"></i> Clear
                                        </button>
                                    </div>
                                    <div class="max-h-60 overflow-auto">
                                        <?php
                                        if ($recipients_error) {
                                            echo "<div class='px-4 py-2' style='color:var(--red);'>Could not load recipients: " . htmlspecialchars($recipients_error) . "</div>";
                                        } elseif ($recipients && $recipients->num_rows > 0) {
                                            $recipients->data_seek(0);
                                            while ($recipient = $recipients->fetch_assoc()) {
                                                $rec_name = $recipient['name'];
                                                $already_got = isset($already_received_lookup[$rec_name]);
                                                $disabled = $already_got ? 'disabled' : '';
                                                $label_class = $already_got ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer';
                                                $text_style = $already_got ? 'color:var(--text-faint);' : '';
                                                $clean_label = htmlspecialchars($recipient['name']);
                                                $display_label = $clean_label;
                                                if ($already_got) $display_label .= ' (Already received today)';
                                                if ($already_got) $text_style .= 'text-decoration:line-through;';
                                                echo "<label class='recOption search-item flex items-center px-4 py-2 {$label_class}' style='{$text_style}' data-label='" . htmlspecialchars($clean_label, ENT_QUOTES) . "' data-search='" . htmlspecialchars($display_label, ENT_QUOTES) . "'>";
                                                echo "<input type='checkbox' name='recipient_ids_chk[]' value='{$recipient['id']}' {$disabled} class='mr-3 h-4 w-4' style='accent-color:var(--accent);' onchange='updateSelectionCount()'>";
                                                echo "<span class='search-text'>{$display_label}</span>";
                                                echo "</label>";
                                            }
                                        } else {
                                            echo "<div class='px-4 py-2' style='color:var(--text-muted);'>No recipients found</div>";
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                            <div id="recipientsChips" class="flex flex-wrap gap-2 mt-3"></div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Distribution Modal -->
    <div id="distributeModal" class="modal-backdrop" style="display:none;">
        <div class="modal-dialog sm">
            <div class="modal-header">
                <h3 class="modal-title">Confirm Distribution</h3>
                <button type="button" class="modal-close" onclick="closeDistributeModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <div class="flex justify-between gap-4" style="padding:12px 0;margin-bottom:14px;border-bottom:1px solid var(--border);">
                    <div>
                        <p style="font-size:12px;color:var(--text-muted);margin-bottom:2px;">Recipients:</p>
                        <p style="font-size:20px;font-weight:700;color:var(--text);"><span id="modalSelectedRecipientCountDisplay">0</span> user(s)</p>
                    </div>
                    <div>
                        <p style="font-size:12px;color:var(--text-muted);margin-bottom:2px;">Subscriptions:</p>
                        <p style="font-size:20px;font-weight:700;color:var(--text);"><span id="modalSelectedCountDisplay">0</span> paper(s)</p>
                    </div>
                </div>

                <p style="font-size:12px;color:var(--text-muted);margin-bottom:6px;">Papers:</p>
                <div id="modalSubList" class="flex flex-wrap gap-2"></div>
                <p style="font-size:12px;color:var(--text-muted);margin:12px 0 6px;">Recipients:</p>
                <div id="modalRecipientList" class="flex flex-wrap gap-2"></div>

                <label class="label">Distributed By</label>
                <input type="text" id="modal_distributed_by" class="input" placeholder="Your name" autocomplete="off">

                <p style="font-size:12px;color:var(--text-muted);margin-top:12px;">
                    <i class="fa-regular fa-circle-info"></i> Distributions will skip any recipients who already received their papers today.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeDistributeModal()" class="btn btn-soft">Cancel</button>
                <button type="button" onclick="submitDistribution(this)" class="btn btn-primary">Confirm Distribution</button>
            </div>
        </div>
    </div>

    <script>
        function getCheckedValues(name) {
            const checkboxes = document.querySelectorAll(`input[name="${name}"]:checked`);
            return Array.from(checkboxes).map(chk => chk.value);
        }

        function updateSelectionCount() {
            const recs = getCheckedValues('recipient_ids_chk[]');
            const subs = getCheckedValues('selected_categories_chk[]');

            const recCount = recs.length;
            const catCount = subs.length;

            document.getElementById('selectedCountBadge').textContent = catCount;
            document.getElementById('selectedRecipientCountBadge').textContent = recCount;

            const distributeBtn = document.getElementById('distributeBtn');
            if (distributeBtn) {
                const btnLabel = distributeBtn.querySelector('span');
                if (btnLabel) btnLabel.textContent = `Distribute (${recCount} rec, ${catCount} sub)`;
                distributeBtn.disabled = (catCount === 0 || recCount === 0);
            }

            const recText = document.getElementById('recipientsDropdownText');
            if (recText) recText.textContent = recCount > 0 ? `${recCount} recipients selected` : 'Select recipients...';

            const subText = document.getElementById('subscriptionsDropdownText');
            if (subText) subText.textContent = catCount > 0 ? `${catCount} subscriptions selected` : 'Select subscriptions...';

            renderSelectionChips('subscriptionsChips', 'selected_categories_chk[]', 'No subscriptions selected');
            renderSelectionChips('recipientsChips', 'recipient_ids_chk[]', 'No recipients selected');
        }

        function filterSelectOptions(input, itemClass) {
            const q = input.value.trim().toLowerCase();
            document.querySelectorAll('.' + itemClass).forEach(label => {
                const txt = (label.dataset.search || label.textContent).toLowerCase();
                label.classList.toggle('hidden', q !== '' && txt.indexOf(q) === -1);
            });
        }

        function selectAllOptions(name) {
            document.querySelectorAll(`input[name="${name}"]`).forEach(cb => {
                const label = cb.closest('label');
                if (!cb.disabled && label && !label.classList.contains('hidden')) cb.checked = true;
            });
            updateSelectionCount();
        }

        function clearOptions(name) {
            document.querySelectorAll(`input[name="${name}"]`).forEach(cb => { cb.checked = false; });
            updateSelectionCount();
        }

        function optionLabel(cb) {
            const label = cb.closest('label');
            return label && label.dataset.label ? label.dataset.label : (label ? label.textContent.trim() : cb.value);
        }

        function renderSelectionChips(containerId, name, emptyText) {
            const container = document.getElementById(containerId);
            if (!container) return;
            const boxes = getCheckedValues(name);
            container.innerHTML = '';
            boxes.forEach(value => {
                const cb = document.querySelector(`input[name="${name}"][value="${value}"]`);
                if (!cb) return;
                const chip = document.createElement('span');
                chip.className = 'filter-chip';
                chip.innerHTML = '<i class="fa-solid fa-circle-check" style="color:var(--green);margin-right:6px;font-size:10px;"></i>' + esc(optionLabel(cb)) + ' <button type="button" class="chip-remove" title="Remove">&times;</button>';
                chip.querySelector('.chip-remove').onclick = () => { cb.checked = false; updateSelectionCount(); };
                container.appendChild(chip);
            });
            if (boxes.length === 0) {
                container.innerHTML = '<span class="text-xs" style="color:var(--text-faint);">' + emptyText + '</span>';
            }
        }

        function populateModalSummary() {
            const subList = document.getElementById('modalSubList');
            const recList = document.getElementById('modalRecipientList');
            if (!subList || !recList) return;
            const MAX = 6;
            const subs = getCheckedValues('selected_categories_chk[]');
            const recs = getCheckedValues('recipient_ids_chk[]');
            const htmlFor = (name) => {
                const values = name === 'selected_categories_chk[]' ? subs : recs;
                const items = values.map(value => {
                    const cb = document.querySelector(`input[name="${name}"][value="${value}"]`);
                    return '<span class="filter-chip">' + esc(cb ? optionLabel(cb) : value) + '</span>';
                });
                if (items.length > MAX) {
                    return items.slice(0, MAX).join(' ') + ' <span class="filter-chip">+' + (items.length - MAX) + ' more</span>';
                }
                return items.join(' ') || '<span class="text-xs" style="color:var(--text-faint);">None</span>';
            };
            subList.innerHTML = htmlFor('selected_categories_chk[]');
            recList.innerHTML = htmlFor('recipient_ids_chk[]');
        }

        function openDistributeModal() {
            const recCount = getCheckedValues('recipient_ids_chk[]').length;
            const catCount = getCheckedValues('selected_categories_chk[]').length;

            if (recCount === 0) {
                MailroomToast.error('Please select at least one recipient');
                return;
            }
            if (catCount === 0) {
                MailroomToast.error('Please select at least one subscription');
                return;
            }
            document.getElementById('modalSelectedCountDisplay').textContent = catCount;
            document.getElementById('modalSelectedRecipientCountDisplay').textContent = recCount;
            populateModalSummary();
            MailroomModal.open('distributeModal');
        }

        function closeDistributeModal() {
            MailroomModal.close('distributeModal');
            document.getElementById('modal_distributed_by').value = '';
        }

        function submitDistribution(btn) {
            const distributedBy = document.getElementById('modal_distributed_by').value.trim();

            if (!distributedBy) {
                MailroomToast.error('Please enter who is distributing');
                return;
            }

            const formData = new FormData();
            formData.append('csrf_token', '<?php echo csrf_token(); ?>');
            formData.append('distribute_submit', '1');
            formData.append('distributed_by', distributedBy);

            const recArray = getCheckedValues('recipient_ids_chk[]');
            formData.append('recipient_ids', recArray.join(','));

            const selectedArray = getCheckedValues('selected_categories_chk[]');
            formData.append('selected_categories', selectedArray.join(','));

            const originalText = btn ? btn.innerHTML : null;
            if (btn) {
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Processing...';
                btn.disabled = true;
            }

            fetch('newspaper_distribution.php', {
                method: 'POST',
                body: formData,
                redirect: 'manual'
            }).then(response => {
                // Server replies with a 302 carrying the toast session; a manual reload shows it.
                if (response.type === 'opaqueredirect' || response.ok) {
                    window.location.replace('newspaper_distribution.php');
                } else {
                    if (btn) { btn.innerHTML = originalText; btn.disabled = false; }
                    MailroomToast.error('Error submitting distribution');
                }
            }).catch(error => {
                if (btn) { btn.innerHTML = originalText; btn.disabled = false; }
                MailroomToast.error('Error submitting distribution');
            });

            closeDistributeModal();
        }

        <?php if (!empty($already_received_today)): ?>
            document.addEventListener('DOMContentLoaded', function() {
                const alreadyReceived = <?php echo json_encode($already_received_today); ?>;
                alreadyReceived.forEach((name, index) => {
                    setTimeout(() => {
                        MailroomToast.warning(`Already received today: ${name}`);
                    }, index * 800); // 800ms delay between toasts
                });
            });
        <?php endif; ?>

        // Export today's distribution status to CSV
        function exportTodayStatus() {
            const data = <?php echo json_encode($today_distribution_export); ?>;
            const filename = 'today_distribution_status_' + new Date().toISOString().split('T')[0] + '.csv';
            exportToCSV(data, filename);
        }
    </script>
    <script src="assets/app.js"></script>
</body>

</html>