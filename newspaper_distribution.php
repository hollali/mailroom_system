<?php
// newspaper_distribution.php - Distribute newspapers by category (Once per day per recipient)
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once './config/db.php';
session_start();

// Handle Distribution Form Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['distribute_submit'])) {
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

        foreach ($recipient_ids as $recipient_id) {
            $recipient_id = (int)$recipient_id;
            
            // Get recipient details
            $recipient_query = $conn->query("SELECT name FROM recipients WHERE id = $recipient_id AND is_active = 1");
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

            // Insert new distribution record
            $stmt = $conn->prepare("INSERT INTO distribution (distributed_to, department, copies, date_distributed, distributed_by, newspapers_list, newspaper_ids) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssissss", $individual_name, $department, $success_count, $date_distributed, $distributed_by, $newspapers_str, $newspaper_ids_str);

            if ($stmt->execute()) {
                $success_recipients[] = $individual_name;
                // Deduct copies for successful distribution
                foreach ($selected_categories as $paper_id) {
                    $pid = (int)$paper_id;
                    $conn->query("UPDATE newspapers SET available_copies = GREATEST(0, available_copies - 1) WHERE id = $pid");
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
            if (count($success_recipients) == 0) {
                $_SESSION['toast'] = ['type' => 'error', 'message' => "Skipped distributions. All selected recipients already received today."];
                header('Location: newspaper_distribution.php');
                exit();
            }
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

// Get all active recipients
$recipients = $conn->query("SELECT id, name FROM recipients WHERE is_active = 1 ORDER BY name");

// Get all available newspapers for distribution
$categories_for_distribution = $conn->query("SELECT id, newspaper_name, newspaper_number, available_copies FROM newspapers WHERE available_copies > 0 AND status != 'archived' ORDER BY newspaper_name, newspaper_number");

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

// Get toast message from session
$toast = null;
if (isset($_SESSION['toast'])) {
    $toast = $_SESSION['toast'];
    unset($_SESSION['toast']);
}

include './sidebar.php';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Newspaper Distribution - Mailroom</title>
    <link rel="icon" type="image/png" href="./images/logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #f5f5f4;
        }

        .category-card {
            border: 1px solid #e5e5e5;
            border-radius: 4px;
            background-color: white;
            margin-bottom: 1rem;
            cursor: pointer;
        }

        .category-card:hover {
            background-color: #fafaf9;
        }

        .category-card.selected {
            border: 2px solid #1c1917;
            background-color: #fafaf9;
        }

        .distribute-btn {
            background-color: #1c1917;
            color: white;
            padding: 6px 12px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            border: none;
            font-size: 13px;
        }

        .distribute-btn:hover {
            background-color: #292524;
        }

        .distribute-btn:disabled {
            background-color: #9e9e9e;
            cursor: not-allowed;
        }

        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }

        .toast {
            min-width: 260px;
            background-color: white;
            border: 1px solid #e5e5e5;
            padding: 10px 16px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .toast-success {
            border-left: 3px solid #10b981;
        }

        .toast-error {
            border-left: 3px solid #ef4444;
        }

        .toast-warning {
            border-left: 3px solid #f59e0b;
        }

        .selected-count-badge {
            background-color: #1c1917;
            color: white;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }

        .category-checkbox {
            width: 16px;
            height: 16px;
            cursor: pointer;
            margin-right: 10px;
        }

        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.4);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .modal-content {
            background: white;
            max-width: 450px;
            width: 90%;
        }

        .modal-header {
            padding: 16px 20px;
            border-bottom: 1px solid #e5e5e5;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 16px;
            font-weight: 500;
        }

        .modal-body {
            padding: 20px;
        }

        .modal-footer {
            padding: 12px 20px;
            border-top: 1px solid #e5e5e5;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        .btn-secondary {
            background: white;
            border: 1px solid #e5e5e5;
            padding: 6px 12px;
            cursor: pointer;
            font-size: 13px;
        }

        .btn-primary-small {
            background: #1c1917;
            color: white;
            border: none;
            padding: 6px 12px;
            cursor: pointer;
            font-size: 13px;
        }

        .info-box {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            padding: 12px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .warning-box {
            background: #fef3c7;
            border: 1px solid #fde68a;
            padding: 12px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
    </style>
</head>

<body class="bg-[#f5f5f4]">
    <div id="toastContainer" class="toast-container"></div>

    <div class="flex">
        <main class="flex-1 ml-60">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-xl font-medium">Newspaper Distribution</h1>
                        <p class="text-sm text-gray-500 mt-1">Select recipients and subscriptions to distribute</p>
                    </div>
                    <button id="distributeBtn" class="distribute-btn" onclick="openDistributeModal()" disabled>
                        <i class="fa-solid fa-hand-holding-hand"></i>
                        <span>Distribute</span>
                    </button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="bg-white border border-gray-200 p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h2 class="text-base font-medium">1. Select Recipients</h2>
                            <div class="selected-count-badge">
                                <span id="selectedRecipientCountBadge">0</span> selected
                            </div>
                        </div>

                        <div id="recipientListContainer" class="mt-2 relative">
                        <button type="button" class="w-full bg-white border border-gray-300 rounded-md shadow-sm pl-3 pr-10 py-2 text-left cursor-pointer focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500 sm:text-sm" onclick="document.getElementById('recipientsDropdownOptions').classList.toggle('hidden')">
                            <span class="block truncate" id="recipientsDropdownText">Select recipients...</span>
                            <span class="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none">
                                <i class="fa-solid fa-chevron-down text-gray-400"></i>
                            </span>
                        </button>
                        <div id="recipientsDropdownOptions" class="hidden absolute z-10 mt-1 w-full bg-white shadow-lg max-h-60 rounded-md py-1 text-base ring-1 ring-black ring-opacity-5 overflow-auto focus:outline-none sm:text-sm border border-gray-200">
                            <?php 
                            if ($recipients && $recipients->num_rows > 0) {
                                $recipients->data_seek(0);
                                while ($recipient = $recipients->fetch_assoc()) {
                                    $rec_name = $recipient['name'];
                                    $already_got = isset($already_received_lookup[$rec_name]);
                                    $disabled = $already_got ? 'disabled' : '';
                                    $label_class = $already_got ? 'text-gray-400 cursor-not-allowed' : 'text-gray-700 cursor-pointer';
                                    $label = htmlspecialchars($recipient['name']);
                                    if ($already_got) $label .= ' (Already received today)';
                                    echo "<label class='flex items-center px-4 py-2 hover:bg-gray-100 {$label_class}'>";
                                    echo "<input type='checkbox' name='recipient_ids_chk[]' value='{$recipient['id']}' {$disabled} class='mr-3 h-4 w-4 text-blue-600 rounded border-gray-300' onchange='updateSelectionCount()'>";
                                    echo "<span>{$label}</span>";
                                    echo "</label>";
                                }
                            } else {
                                echo "<div class='px-4 py-2 text-gray-500'>No recipients found</div>";
                            }
                            ?>
                        </div>
                    </div>
                </div>

                <div class="bg-white border border-gray-200 p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-base font-medium">2. Select Subscriptions (Papers)</h2>
                        <div class="selected-count-badge">
                            <span id="selectedCountBadge">0</span> selected
                        </div>
                    </div>

                    <div class="mt-2 relative">
                        <button type="button" class="w-full bg-white border border-gray-300 rounded-md shadow-sm pl-3 pr-10 py-2 text-left cursor-pointer focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500 sm:text-sm" onclick="document.getElementById('subscriptionsDropdownOptions').classList.toggle('hidden')">
                            <span class="block truncate" id="subscriptionsDropdownText">Select subscriptions...</span>
                            <span class="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none">
                                <i class="fa-solid fa-chevron-down text-gray-400"></i>
                            </span>
                        </button>
                        <div id="subscriptionsDropdownOptions" class="hidden absolute z-10 mt-1 w-full bg-white shadow-lg max-h-60 rounded-md py-1 text-base ring-1 ring-black ring-opacity-5 overflow-auto focus:outline-none sm:text-sm border border-gray-200">
                            <?php 
                            if ($categories_for_distribution && $categories_for_distribution->num_rows > 0) {
                                $categories_for_distribution->data_seek(0);
                                while ($paper = $categories_for_distribution->fetch_assoc()) {
                                    $label = htmlspecialchars($paper['newspaper_name']);
                                    if ($paper['newspaper_number']) $label .= ' (Issue: ' . htmlspecialchars($paper['newspaper_number']) . ')';
                                    $label .= ' - ' . $paper['available_copies'] . ' left';
                                    
                                    echo "<label class='flex items-center px-4 py-2 hover:bg-gray-100 text-gray-700 cursor-pointer'>";
                                    echo "<input type='checkbox' name='selected_categories_chk[]' value='{$paper['id']}' class='mr-3 h-4 w-4 text-blue-600 rounded border-gray-300' onchange='updateSelectionCount()'>";
                                    echo "<span>{$label}</span>";
                                    echo "</label>";
                                }
                            } else {
                                echo "<div class='px-4 py-2 text-gray-500'>No available newspapers found</div>";
                            }
                            ?>
                        </div>
                    </div>
                </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Distribution Modal -->
    <div id="distributeModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Confirm Distribution</h3>
                <button type="button" onclick="closeDistributeModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="mb-4 p-3 bg-gray-50 border border-gray-200 flex justify-between gap-4">
                    <div>
                        <p class="text-sm text-gray-600 mb-1">Recipients:</p>
                        <p class="text-lg font-semibold"><span id="modalSelectedRecipientCountDisplay">0</span> user(s)</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600 mb-1">Subscriptions:</p>
                        <p class="text-lg font-semibold"><span id="modalSelectedCountDisplay">0</span> paper(s)</p>
                    </div>
                </div>

                <div>
                    <label class="block text-xs text-gray-600 mb-1">Distributed By</label>
                    <input type="text" id="modal_distributed_by" class="w-full p-2 border border-gray-200 text-sm" placeholder="Your name">
                </div>

                <div class="mt-3 text-xs text-gray-500">
                    <i class="fa-regular fa-info-circle"></i> Distributions will skip any recipients who already received their papers today.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeDistributeModal()" class="btn-secondary">Cancel</button>
                <button type="button" onclick="submitDistribution()" class="btn-primary-small">Confirm Distribution</button>
            </div>
        </div>
    </div>

    <script>
        // Initialize Choices.js
        const recipientsSelectEl = document.getElementById('recipientsSelect');
        const subscriptionsSelectEl = document.getElementById('subscriptionsSelect');
        
        // Close dropdowns on outside click
        document.addEventListener('click', function(event) {
            const recBtn = document.getElementById('recipientsDropdownText')?.parentElement;
            const recDropdown = document.getElementById('recipientsDropdownOptions');
            const subBtn = document.getElementById('subscriptionsDropdownText')?.parentElement;
            const subDropdown = document.getElementById('subscriptionsDropdownOptions');
            
            if (recBtn && recDropdown && !recBtn.contains(event.target) && !recDropdown.contains(event.target)) {
                recDropdown.classList.add('hidden');
            }
            if (subBtn && subDropdown && !subBtn.contains(event.target) && !subDropdown.contains(event.target)) {
                subDropdown.classList.add('hidden');
            }
        });

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
            const btnText = `Distribute (${recCount} rec, ${catCount} sub)`;
            if (distributeBtn) distributeBtn.querySelector('span').textContent = btnText;
            
            if (distributeBtn) distributeBtn.disabled = (catCount === 0 || recCount === 0);

            const recText = document.getElementById('recipientsDropdownText');
            if (recText) recText.textContent = recCount > 0 ? `${recCount} recipients selected` : 'Select recipients...';

            const subText = document.getElementById('subscriptionsDropdownText');
            if (subText) subText.textContent = catCount > 0 ? `${catCount} subscriptions selected` : 'Select subscriptions...';
        }

        function openDistributeModal() {
            const recCount = getCheckedValues('recipient_ids_chk[]').length;
            const catCount = getCheckedValues('selected_categories_chk[]').length;
            
            if (recCount === 0) {
                showToast('error', 'Please select at least one recipient');
                return;
            }
            if (catCount === 0) {
                showToast('error', 'Please select at least one subscription');
                return;
            }
            document.getElementById('modalSelectedCountDisplay').textContent = catCount;
            document.getElementById('modalSelectedRecipientCountDisplay').textContent = recCount;
            document.getElementById('distributeModal').style.display = 'flex';
        }

        function closeDistributeModal() {
            document.getElementById('distributeModal').style.display = 'none';
            document.getElementById('modal_distributed_by').value = '';
        }

        function submitDistribution() {
            const distributedBy = document.getElementById('modal_distributed_by').value.trim();

            if (!distributedBy) {
                showToast('error', 'Please enter who is distributing');
                return;
            }

            const formData = new FormData();
            formData.append('distribute_submit', '1');
            formData.append('distributed_by', distributedBy);

            const recArray = getCheckedValues('recipient_ids_chk[]');
            formData.append('recipient_ids', recArray.join(','));

            const selectedArray = getCheckedValues('selected_categories_chk[]');
            formData.append('selected_categories', selectedArray.join(','));

            const confirmBtn = event.target;
            const originalText = confirmBtn.innerHTML;
            confirmBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Processing...';
            confirmBtn.disabled = true;

            fetch('newspaper_distribution.php', {
                method: 'POST',
                body: formData
            }).then(response => {
                window.location.href = 'newspaper_distribution.php';
            }).catch(error => {
                showToast('error', 'Error submitting distribution');
                confirmBtn.innerHTML = originalText;
                confirmBtn.disabled = false;
            });

            closeDistributeModal();
        }

        function showToast(type, message, duration = 5000) {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.innerHTML = `
                <i class="fa-regular ${type === 'success' ? 'fa-circle-check' : type === 'warning' ? 'fa-clock' : 'fa-circle-exclamation'}"></i>
                <span class="flex-1 text-sm">${message}</span>
                <button onclick="this.parentElement.remove()" class="text-gray-400">×</button>
            `;
            container.appendChild(toast);
            setTimeout(() => toast.remove(), duration);
        }

        <?php if ($toast): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showToast('<?php echo $toast['type']; ?>', '<?php echo addslashes($toast['message']); ?>');
            });
        <?php endif; ?>

        <?php if (!empty($already_received_today)): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showToast(
                    'warning',
                    'Already received today: <?php echo addslashes(implode(", ", $already_received_today)); ?>',
                    2000
                );
            });
        <?php endif; ?>

        window.onclick = function(event) {
            const modal = document.getElementById('distributeModal');
            if (event.target == modal) {
                closeDistributeModal();
            }
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeDistributeModal();
            }
        });
    </script>
</body>

</html>