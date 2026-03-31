<?php
// newspaper_distribution.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once './config/db.php';
session_start();

// Handle Distribution Form Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['distribute_submit'])) {
    $recipient_id = isset($_POST['recipient_id']) ? (int)$_POST['recipient_id'] : 0;
    $distributed_by = trim($_POST['distributed_by']);
    $selected_newspapers = $_POST['selected_newspapers'] ?? [];
    $date_distributed = date('Y-m-d');

    if ($recipient_id <= 0) {
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => "Please select a recipient"
        ];
        header('Location: distribution_history.php');
        exit();
    }

    // Get recipient details
    $recipient_query = $conn->query("SELECT name FROM recipients WHERE id = $recipient_id AND is_active = 1");
    if (!$recipient = $recipient_query->fetch_assoc()) {
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => "Invalid recipient selected"
        ];
        header('Location: distribution_history.php');
        exit();
    }

    $full_name = $recipient['name'];
    // Split name and department if format is "Name - Department"
    $individual_name = $full_name;
    $department = '';
    if (strpos($full_name, ' - ') !== false) {
        $parts = explode(' - ', $full_name, 2);
        $individual_name = $parts[0];
        $department = $parts[1];
    }

    if (!empty($selected_newspapers)) {
        $conn->begin_transaction();

        try {
            $success_count = 0;
            $distributed_details = [];

            foreach ($selected_newspapers as $newspaper_id) {
                // Get newspaper details
                $result = $conn->query("SELECT n.*, nc.category_name FROM newspapers n 
                                        LEFT JOIN newspaper_categories nc ON n.category_id = nc.id 
                                        WHERE n.id = $newspaper_id AND n.available_copies > 0");
                $paper = $result->fetch_assoc();

                if ($paper) {
                    // Update newspaper available copies (distribute 1 copy)
                    $conn->query("UPDATE newspapers SET available_copies = available_copies - 1 WHERE id = $newspaper_id");

                    // Update status based on new available copies
                    $new_available = $paper['available_copies'] - 1;
                    if ($new_available == 0) {
                        $conn->query("UPDATE newspapers SET status = 'distributed' WHERE id = $newspaper_id");
                    } else {
                        $conn->query("UPDATE newspapers SET status = 'partial' WHERE id = $newspaper_id");
                    }

                    // Insert distribution record
                    $stmt = $conn->prepare("INSERT INTO distribution (newspaper_id, distributed_to, department, copies, date_distributed, distributed_by) VALUES (?, ?, ?, 1, ?, ?)");
                    $stmt->bind_param("issss", $newspaper_id, $individual_name, $department, $date_distributed, $distributed_by);
                    $stmt->execute();

                    $success_count++;
                    $distributed_details[] = $paper['newspaper_name'] . " (" . $paper['category_name'] . ") - Issue: " . $paper['newspaper_number'];
                }
            }

            $conn->commit();

            if ($success_count > 0) {
                $_SESSION['toast'] = [
                    'type' => 'success',
                    'message' => "$success_count newspaper(s) distributed to $individual_name"
                ];

                // Store last distribution info in session
                $_SESSION['last_distribution'] = [
                    'individual' => $individual_name,
                    'department' => $department,
                    'count' => $success_count,
                    'newspapers' => $distributed_details,
                    'date' => $date_distributed,
                    'distributed_by' => $distributed_by,
                    'timestamp' => time()
                ];
            } else {
                $_SESSION['toast'] = [
                    'type' => 'error',
                    'message' => "No newspapers were available for distribution"
                ];
            }
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['toast'] = [
                'type' => 'error',
                'message' => "Distribution failed: " . $e->getMessage()
            ];
        }
    } else {
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => "No newspapers selected for distribution"
        ];
    }

    header('Location: distribution_history.php');
    exit();
}

// Handle AJAX request to dismiss last distribution notification
if (isset($_POST['ajax']) && $_POST['ajax'] == 'dismiss_last_distribution') {
    if (isset($_SESSION['last_distribution'])) {
        unset($_SESSION['last_distribution']);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit();
}

// Get all active recipients for dropdown
$recipients = $conn->query("SELECT id, name FROM recipients WHERE is_active = 1 ORDER BY name");

// Get all available newspapers with their categories
$available_newspapers = $conn->query("SELECT n.*, nc.category_name, nc.id as category_id 
                                     FROM newspapers n 
                                     LEFT JOIN newspaper_categories nc ON n.category_id = nc.id 
                                     WHERE n.available_copies > 0 
                                     ORDER BY nc.category_name, n.newspaper_name");

// Group newspapers by category for display
$newspapers_by_category = [];
$category_totals = [];

if ($available_newspapers && $available_newspapers->num_rows > 0) {
    while ($row = $available_newspapers->fetch_assoc()) {
        $cat_name = $row['category_name'] ?? 'Uncategorized';
        $cat_id = $row['category_id'] ?? 0;

        if (!isset($newspapers_by_category[$cat_name])) {
            $newspapers_by_category[$cat_name] = [
                'id' => $cat_id,
                'newspapers' => []
            ];
            $category_totals[$cat_name] = 0;
        }
        $newspapers_by_category[$cat_name]['newspapers'][] = $row;
        $category_totals[$cat_name] += $row['available_copies'];
    }
}

// Get statistics
$total_available = $conn->query("SELECT SUM(available_copies) as total FROM newspapers")->fetch_assoc()['total'] ?? 0;
$total_titles = $conn->query("SELECT COUNT(*) as count FROM newspapers WHERE available_copies > 0")->fetch_assoc()['count'] ?? 0;
$total_categories = count($newspapers_by_category);

// Get toast message from session
$toast = null;
if (isset($_SESSION['toast'])) {
    $toast = $_SESSION['toast'];
    unset($_SESSION['toast']);
}

// Include sidebar
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
            border-radius: 0.5rem;
            overflow: hidden;
            background-color: white;
            margin-bottom: 1rem;
        }

        .category-header {
            background-color: #fafafa;
            padding: 1rem;
            border-bottom: 1px solid #e5e5e5;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .newspaper-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #f0f0f0;
            transition: background-color 0.2s;
            cursor: pointer;
        }

        .newspaper-item:hover {
            background-color: #fafafa;
        }

        .newspaper-item:last-child {
            border-bottom: none;
        }

        .newspaper-item.selected {
            background-color: #f0f7ff;
            border-left: 3px solid #1e1e1e;
        }

        .newspaper-checkbox {
            width: 1.2rem;
            height: 1.2rem;
            cursor: pointer;
            accent-color: #1e1e1e;
            margin-right: 0.75rem;
        }

        .category-checkbox {
            width: 1.2rem;
            height: 1.2rem;
            cursor: pointer;
            accent-color: #1e1e1e;
        }

        .issue-number {
            font-family: monospace;
            font-size: 0.75rem;
            color: #6b7280;
        }

        .available-badge {
            background-color: #e8f5e9;
            color: #2e7d32;
            padding: 0.25rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .distribute-btn {
            background-color: #1e1e1e;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            font-size: 0.875rem;
        }

        .distribute-btn:hover {
            background-color: #2d2d2d;
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
            min-width: 300px;
            max-width: 400px;
            background-color: white;
            border: 1px solid #e5e5e5;
            border-radius: 0.375rem;
            padding: 1rem;
            margin-bottom: 0.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            animation: slideIn 0.3s ease-in-out;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .toast-success {
            border-left: 4px solid #10b981;
        }

        .toast-error {
            border-left: 4px solid #ef4444;
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes fadeOut {
            from {
                transform: translateX(0);
                opacity: 1;
            }

            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }

        .toast.fade-out {
            animation: fadeOut 0.3s ease-in-out forwards;
        }

        .notification {
            animation: slideDown 0.3s ease-in-out;
            position: relative;
            overflow: hidden;
        }

        .notification.fade-out {
            animation: fadeOutUp 0.3s ease-in-out forwards;
        }

        .notification-progress {
            position: absolute;
            bottom: 0;
            left: 0;
            height: 3px;
            background-color: rgba(16, 185, 129, 0.3);
            width: 100%;
            animation: progress 3s linear forwards;
        }

        @keyframes slideDown {
            from {
                transform: translateY(-100%);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        @keyframes fadeOutUp {
            from {
                transform: translateY(0);
                opacity: 1;
            }

            to {
                transform: translateY(-100%);
                opacity: 0;
            }
        }

        @keyframes progress {
            from {
                width: 100%;
            }

            to {
                width: 0%;
            }
        }

        .dismiss-btn {
            cursor: pointer;
            transition: all 0.2s;
        }

        .dismiss-btn:hover {
            color: #1e1e1e;
            transform: scale(1.1);
        }

        .recipient-select {
            background-color: white;
        }

        .selected-count-badge {
            background-color: #1e1e1e;
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.75rem;
            font-weight: 500;
        }
    </style>
</head>

<body class="bg-[#f5f5f4]">
    <!-- Toast Container -->
    <div id="toastContainer" class="toast-container"></div>

    <div class="flex">
        <main class="flex-1 ml-60 min-h-screen">
            <!-- Header -->
            <div class="px-8 py-6 border-b border-[#e5e5e5] bg-white">
                <div class="flex justify-between items-center">
                    <div>
                        <h1 class="text-2xl font-medium text-[#1e1e1e]">Newspaper Distribution</h1>
                        <p class="text-sm text-[#6e6e6e] mt-1">View and select newspapers for distribution</p>
                    </div>
                    <div class="flex gap-2">
                        <button id="distributeBtn" class="distribute-btn" onclick="openDistributeModal()" disabled>
                            <i class="fa-solid fa-hand-holding-hand"></i>
                            <span>Distribute (<span id="selectedCount">0</span>)</span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="p-8">
                <!-- Available Newspapers Display (Selectable view) -->
                <div class="bg-white border border-[#e5e5e5] rounded-lg p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-lg font-medium text-[#1e1e1e]">Select Newspapers to Distribute</h2>
                        <div class="selected-count-badge">
                            <i class="fa-regular fa-circle-check mr-1"></i>
                            <span id="selectedCount">0</span> selected
                        </div>
                    </div>

                    <?php if (!empty($newspapers_by_category)): ?>
                        <div class="space-y-4">
                            <?php foreach ($newspapers_by_category as $category_name => $category_data): ?>
                                <?php $newspapers = $category_data['newspapers']; ?>
                                <div class="category-card">
                                    <div class="category-header">
                                        <div class="flex items-center justify-between w-full">
                                            <div class="flex items-center gap-3">
                                                <input type="checkbox"
                                                    class="category-checkbox"
                                                    id="category-<?php echo md5($category_name); ?>"
                                                    onchange="toggleCategorySelection('<?php echo md5($category_name); ?>', [<?php echo implode(',', array_column($newspapers, 'id')); ?>])">
                                                <div>
                                                    <h3 class="font-medium text-[#1e1e1e]"><?php echo htmlspecialchars($category_name); ?></h3>
                                                    <span class="text-xs text-[#6e6e6e]"><?php echo count($newspapers); ?> titles</span>
                                                </div>
                                            </div>
                                            <span class="available-badge">
                                                <?php echo $category_totals[$category_name]; ?> copies available
                                            </span>
                                        </div>
                                    </div>
                                    <div class="divide-y divide-[#f0f0f0]">
                                        <?php foreach ($newspapers as $paper): ?>
                                            <div class="newspaper-item">
                                                <input type="checkbox"
                                                    class="newspaper-checkbox"
                                                    id="newspaper-<?php echo $paper['id']; ?>"
                                                    value="<?php echo $paper['id']; ?>"
                                                    onchange="toggleNewspaperSelection(<?php echo $paper['id']; ?>)">
                                                <div class="flex-1 flex items-center justify-between">
                                                    <div>
                                                        <span class="font-medium"><?php echo htmlspecialchars($paper['newspaper_name']); ?></span>
                                                        <span class="text-xs text-[#6e6e6e] ml-2 issue-number"><?php echo htmlspecialchars($paper['newspaper_number']); ?></span>
                                                    </div>
                                                    <div class="flex items-center gap-4">
                                                        <span class="text-xs <?php echo $paper['available_copies'] > 0 ? 'text-green-600' : 'text-red-600'; ?> font-medium">
                                                            <?php echo $paper['available_copies']; ?> copies
                                                        </span>
                                                        <span class="text-xs text-[#6e6e6e]">
                                                            <?php echo date('M j, Y', strtotime($paper['date_received'])); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8 text-[#6e6e6e]">
                            <i class="fa-regular fa-newspaper text-3xl mb-2"></i>
                            <p>No newspapers available for distribution</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Distribution Modal -->
    <div id="distributeModal" class="fixed inset-0 bg-[#000000] bg-opacity-20 hidden items-center justify-center z-50 modal">
        <div class="bg-white border border-[#e5e5e5] rounded-md w-full max-w-md p-6 modal-content">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-medium text-[#1e1e1e]">Distribute Newspapers</h2>
                <button type="button" onclick="closeDistributeModal()" class="text-[#9e9e9e] hover:text-[#1e1e1e]">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>

            <div class="mb-4 p-3 bg-[#fafafa] border border-[#e5e5e5] rounded-md">
                <p class="text-sm text-[#6e6e6e] mb-1">Selected Newspapers:</p>
                <p class="text-lg font-semibold text-[#1e1e1e]"><span id="modalSelectedCountDisplay">0</span> newspaper(s)</p>
            </div>

            <form method="POST" action="newspaper_distribution.php" id="distributeForm">
                <input type="hidden" name="distribute_submit" value="1">
                <input type="hidden" name="selected_newspapers" id="selected_newspapers_hidden" value="">

                <div class="space-y-4">
                    <div>
                        <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Select Recipient *</label>
                        <select name="recipient_id" id="recipient_select" required
                            class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e] bg-white recipient-select">
                            <option value="">-- Select a recipient --</option>
                            <?php
                            $recipients->data_seek(0);
                            while ($recipient = $recipients->fetch_assoc()):
                            ?>
                                <option value="<?php echo $recipient['id']; ?>"><?php echo htmlspecialchars($recipient['name']); ?></option>
                            <?php endwhile; ?>
                        </select>
                        <p class="text-xs text-[#6e6e6e] mt-1">
                            <i class="fa-regular fa-building mr-1"></i>
                            <a href="recipients.php" class="text-blue-600 hover:underline">Manage recipients</a>
                        </p>
                    </div>

                    <div>
                        <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Distributed By *</label>
                        <input type="text" name="distributed_by" id="modal_distributed_by" required
                            class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]"
                            placeholder="Your name" autocomplete="off">
                    </div>
                </div>

                <div class="flex justify-end gap-2 mt-6 pt-4 border-t border-[#e5e5e5]">
                    <button type="button" onclick="closeDistributeModal()"
                        class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                        Cancel
                    </button>
                    <button type="button"
                        class="px-4 py-2 text-sm bg-[#1e1e1e] text-white rounded-md hover:bg-[#2d2d2d]"
                        onclick="promptDistributionConfirmation()">
                        <i class="fa-solid fa-hand-holding-hand mr-1"></i>
                        Distribute <span id="modalSelectedCount">0</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="distributionConfirmModal" class="fixed inset-0 bg-[#000000] bg-opacity-20 hidden items-center justify-center z-50 modal">
        <div class="bg-white border border-[#e5e5e5] rounded-md w-full max-w-md p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-medium text-[#1e1e1e]">Confirm Distribution</h2>
                <button type="button" onclick="closeDistributionConfirmModal()" class="text-[#9e9e9e] hover:text-[#1e1e1e]">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>

            <div class="space-y-3">
                <p class="text-sm text-[#6e6e6e]">You are about to distribute the selected newspapers with the details below.</p>
                <div class="p-4 bg-[#fafafa] border border-[#e5e5e5] rounded-md space-y-2">
                    <div class="flex justify-between gap-3 text-sm">
                        <span class="text-[#6e6e6e]">Recipient</span>
                        <span class="font-medium text-[#1e1e1e]" id="confirmRecipientName">-</span>
                    </div>
                    <div class="flex justify-between gap-3 text-sm">
                        <span class="text-[#6e6e6e]">Distributed by</span>
                        <span class="font-medium text-[#1e1e1e]" id="confirmDistributedBy">-</span>
                    </div>
                    <div class="flex justify-between gap-3 text-sm">
                        <span class="text-[#6e6e6e]">Selected newspapers</span>
                        <span class="font-medium text-[#1e1e1e]" id="confirmDistributionCount">0</span>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-2 mt-6">
                <button type="button" onclick="closeDistributionConfirmModal()"
                    class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                    Cancel
                </button>
                <button type="button" onclick="submitDistributionForm()"
                    class="px-4 py-2 text-sm bg-[#1e1e1e] text-white rounded-md hover:bg-[#2d2d2d]">
                    Confirm Distribution
                </button>
            </div>
        </div>
    </div>

    <script>
        // ========== TOAST NOTIFICATION ==========
        function showToast(type, message) {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;

            const icon = type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation';

            toast.innerHTML = `
                <div class="flex items-center gap-3">
                    <i class="fa-regular ${icon} text-${type === 'success' ? 'green' : 'red'}-500"></i>
                    <span class="text-sm text-[#1e1e1e]">${message}</span>
                </div>
                <button onclick="this.parentElement.remove()" class="text-[#9e9e9e] hover:text-[#1e1e1e]">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            `;

            container.appendChild(toast);

            setTimeout(() => {
                toast.classList.add('fade-out');
                setTimeout(() => {
                    if (toast.parentElement) {
                        toast.remove();
                    }
                }, 300);
            }, 5000);
        }

        <?php if ($toast): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showToast('<?php echo $toast['type']; ?>', '<?php echo addslashes($toast['message']); ?>');
            });
        <?php endif; ?>

        // ========== NEWSPAPER SELECTION ==========
        let selectedNewspapers = new Set();

        function toggleNewspaperSelection(id) {
            const checkbox = document.getElementById(`newspaper-${id}`);
            const item = checkbox.closest('.newspaper-item');

            if (checkbox.checked) {
                selectedNewspapers.add(id);
                item.classList.add('selected');
            } else {
                selectedNewspapers.delete(id);
                item.classList.remove('selected');
            }

            updateSelectionCount();
            updateCategoryCheckboxes();
        }

        function toggleCategorySelection(categoryId, newspaperIds) {
            const categoryCheckbox = document.getElementById(`category-${categoryId}`);
            const checkboxes = newspaperIds.map(id => document.getElementById(`newspaper-${id}`));

            checkboxes.forEach(cb => {
                cb.checked = categoryCheckbox.checked;
                const item = cb.closest('.newspaper-item');
                if (categoryCheckbox.checked) {
                    selectedNewspapers.add(parseInt(cb.value));
                    item.classList.add('selected');
                } else {
                    selectedNewspapers.delete(parseInt(cb.value));
                    item.classList.remove('selected');
                }
            });

            updateSelectionCount();
        }

        function updateCategoryCheckboxes() {
            const categoryCheckboxes = document.querySelectorAll('.category-checkbox');
            categoryCheckboxes.forEach(cb => {
                const categoryId = cb.id.replace('category-', '');
                const newspaperIds = Array.from(cb.closest('.category-card').querySelectorAll('.newspaper-checkbox')).map(cb => parseInt(cb.value));
                const checkedCount = newspaperIds.filter(id => selectedNewspapers.has(id)).length;

                if (checkedCount === 0) {
                    cb.checked = false;
                    cb.indeterminate = false;
                } else if (checkedCount === newspaperIds.length) {
                    cb.checked = true;
                    cb.indeterminate = false;
                } else {
                    cb.checked = false;
                    cb.indeterminate = true;
                }
            });
        }

        function updateSelectionCount() {
            const count = selectedNewspapers.size;
            document.getElementById('selectedCount').textContent = count;

            const distributeBtn = document.getElementById('distributeBtn');
            if (count > 0) {
                distributeBtn.disabled = false;
            } else {
                distributeBtn.disabled = true;
            }
        }

        // ========== MODAL FUNCTIONS ==========
        function openDistributeModal() {
            if (selectedNewspapers.size === 0) {
                showToast('error', 'Please select at least one newspaper to distribute');
                return;
            }

            document.getElementById('modalSelectedCount').textContent = selectedNewspapers.size;
            document.getElementById('modalSelectedCountDisplay').textContent = selectedNewspapers.size;

            const selectedArray = Array.from(selectedNewspapers);
            document.getElementById('selected_newspapers_hidden').value = selectedArray.join(',');

            document.getElementById('distributeModal').style.display = 'flex';
        }

        function closeDistributeModal() {
            document.getElementById('distributeModal').style.display = 'none';
            document.getElementById('recipient_select').value = '';
            document.getElementById('modal_distributed_by').value = '';
        }

        function promptDistributionConfirmation() {
            let recipientSelect = document.getElementById('recipient_select');
            let distributedBy = document.getElementById('modal_distributed_by').value.trim();
            let selectedCount = selectedNewspapers.size;

            if (!recipientSelect.value) {
                showToast('error', 'Please select a recipient');
                return;
            }

            if (distributedBy === '') {
                showToast('error', 'Please enter who is distributing');
                return;
            }

            let recipientName = recipientSelect.options[recipientSelect.selectedIndex].text;
            document.getElementById('confirmRecipientName').textContent = recipientName;
            document.getElementById('confirmDistributedBy').textContent = distributedBy;
            document.getElementById('confirmDistributionCount').textContent = `${selectedCount} newspaper(s)`;
            document.getElementById('distributionConfirmModal').style.display = 'flex';
        }

        function closeDistributionConfirmModal() {
            document.getElementById('distributionConfirmModal').style.display = 'none';
        }

        function submitDistributionForm() {
            const form = document.getElementById('distributeForm');
            const formData = new FormData(form);

            selectedNewspapers.forEach(id => {
                formData.append('selected_newspapers[]', id);
            });

            // Show loading state
            const confirmBtn = document.querySelector('#distributionConfirmModal button[onclick="submitDistributionForm()"]');
            const originalText = confirmBtn.innerHTML;
            confirmBtn.innerHTML = '<i class="fa-regular fa-spinner fa-spin mr-1"></i> Processing...';
            confirmBtn.disabled = true;

            fetch('newspaper_distribution.php', {
                method: 'POST',
                body: formData
            }).then(response => {
                // Redirect to distribution history page
                window.location.href = 'distribution_history.php';
            }).catch(error => {
                showToast('error', 'Error submitting distribution');
                confirmBtn.innerHTML = originalText;
                confirmBtn.disabled = false;
            });

            closeDistributionConfirmModal();
            closeDistributeModal();
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const distributeModal = document.getElementById('distributeModal');
            const distributionConfirmModal = document.getElementById('distributionConfirmModal');

            if (event.target == distributeModal) {
                closeDistributeModal();
            }
            if (event.target == distributionConfirmModal) {
                closeDistributionConfirmModal();
            }
        }

        // ESC key to close modals
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeDistributeModal();
                closeDistributionConfirmModal();
            }
        });
    </script>
</body>

</html>