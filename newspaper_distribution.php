<?php
// newspaper_distribution.php - Distribute newspapers by category (Once per day per recipient)
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once './config/db.php';
session_start();

// Handle Distribution Form Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['distribute_submit'])) {
    $recipient_id = isset($_POST['recipient_id']) ? (int)$_POST['recipient_id'] : 0;
    $distributed_by = trim($_POST['distributed_by']);

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

    if ($recipient_id <= 0) {
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => "Please select a recipient"
        ];
        header('Location: newspaper_distribution.php');
        exit();
    }

    // Get recipient details
    $recipient_query = $conn->query("SELECT name FROM recipients WHERE id = $recipient_id AND is_active = 1");
    if (!$recipient = $recipient_query->fetch_assoc()) {
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => "Invalid recipient selected"
        ];
        header('Location: newspaper_distribution.php');
        exit();
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
    $check_today_stmt = $conn->prepare("SELECT id, categories_list FROM distribution WHERE distributed_to = ? AND department = ? AND date_distributed = ?");
    $check_today_stmt->bind_param("sss", $individual_name, $department, $date_distributed);
    $check_today_stmt->execute();
    $today_distribution = $check_today_stmt->get_result()->fetch_assoc();
    $check_today_stmt->close();

    if ($today_distribution) {
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => "Newspaper(s) have been distributed to $individual_name already today."
        ];
        header('Location: newspaper_distribution.php');
        exit();
    }

    if (!empty($selected_categories)) {
        $conn->begin_transaction();

        try {
            $category_names = [];

            // Get category names for selected categories
            foreach ($selected_categories as $category_id) {
                $category_id = (int)$category_id;
                $cat_result = $conn->query("SELECT category_name FROM newspaper_categories WHERE id = $category_id");
                $category = $cat_result->fetch_assoc();
                if ($category) {
                    $category_names[] = $category['category_name'];
                }
            }

            $success_count = count($selected_categories);
            $categories_str = implode(', ', $category_names);

            // Insert new distribution record
            $stmt = $conn->prepare("INSERT INTO distribution (distributed_to, department, copies, date_distributed, distributed_by, categories_list) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssisss", $individual_name, $department, $success_count, $date_distributed, $distributed_by, $categories_str);

            if ($stmt->execute()) {
                $message = "Newspaper has been distributed to $individual_name";
            } else {
                throw new Exception("Failed to insert distribution");
            }
            $stmt->close();

            $_SESSION['toast'] = [
                'type' => 'success',
                'message' => $message
            ];

            $_SESSION['last_distribution'] = [
                'individual' => $individual_name,
                'department' => $department,
                'count' => $success_count,
                'categories' => $category_names,
                'date' => $date_distributed,
                'distributed_by' => $distributed_by,
                'timestamp' => time()
            ];

            $conn->commit();
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
            'message' => "No categories selected for distribution"
        ];
    }

    header('Location: newspaper_distribution.php');
    exit();
}

// Get all active recipients
$recipients = $conn->query("SELECT id, name FROM recipients WHERE is_active = 1 ORDER BY name");

// Get all categories for distribution
$categories_for_distribution = $conn->query("SELECT id, category_name FROM newspaper_categories ORDER BY category_name");

// Get recipients who already received distribution today
$already_received_today = [];
$today_recipients_query = $conn->query("
    SELECT DISTINCT distributed_to, department 
    FROM distribution 
    WHERE date_distributed = CURDATE() 
    AND categories_list IS NOT NULL
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
                        <p class="text-sm text-gray-500 mt-1">Select subscriptions to distribute to recipients</p>
                    </div>
                    <button id="distributeBtn" class="distribute-btn" onclick="openDistributeModal()" disabled>
                        <i class="fa-solid fa-hand-holding-hand"></i>
                        <span>Distribute (<span id="selectedCount">0</span> subscriptions)</span>
                    </button>
                </div>

                <div class="bg-white border border-gray-200 p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-base font-medium">Select Subscriptions</h2>
                        <div class="selected-count-badge">
                            <span id="selectedCountBadge">0</span> selected
                        </div>
                    </div>

                    <?php if ($categories_for_distribution && $categories_for_distribution->num_rows > 0): ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                            <?php while ($category = $categories_for_distribution->fetch_assoc()): ?>
                                <div class="category-card p-3" data-category-id="<?php echo $category['id']; ?>" onclick="toggleCategorySelection(this, <?php echo $category['id']; ?>)">
                                    <div class="flex items-center gap-2">
                                        <input type="checkbox"
                                            class="category-checkbox"
                                            id="category-<?php echo $category['id']; ?>"
                                            onclick="event.stopPropagation(); toggleCategorySelectionById(<?php echo $category['id']; ?>)">
                                        <label for="category-<?php echo $category['id']; ?>" class="text-sm font-medium cursor-pointer" onclick="event.stopPropagation()">
                                            <?php echo htmlspecialchars($category['category_name']); ?>
                                        </label>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8 text-gray-500">
                            <i class="fa-regular fa-folder-open text-3xl mb-2 block"></i>
                            <p>No subscriptions found</p>
                            <p class="text-sm mt-2"><a href="categories.php" class="text-blue-600 hover:underline">Add subscriptions first</a></p>
                        </div>
                    <?php endif; ?>
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
                <div class="mb-4 p-3 bg-gray-50 border border-gray-200">
                    <p class="text-sm text-gray-600 mb-1">Selected Subscriptions:</p>
                    <p class="text-lg font-semibold"><span id="modalSelectedCountDisplay">0</span> subscription(s)</p>
                </div>

                <div class="mb-3">
                    <label class="block text-xs text-gray-600 mb-1">Recipient</label>
                    <select id="modal_recipient_select" class="w-full p-2 border border-gray-200 text-sm">
                        <option value="">-- Select a recipient --</option>
                        <?php
                        $recipients->data_seek(0);
                        while ($recipient = $recipients->fetch_assoc()):
                        ?>
                            <option value="<?php echo $recipient['id']; ?>"><?php echo htmlspecialchars($recipient['name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs text-gray-600 mb-1">Distributed By</label>
                    <input type="text" id="modal_distributed_by" class="w-full p-2 border border-gray-200 text-sm" placeholder="Your name">
                </div>

                <div class="mt-3 text-xs text-gray-500">
                    <i class="fa-regular fa-info-circle"></i> Each recipient can only receive distribution once per day.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeDistributeModal()" class="btn-secondary">Cancel</button>
                <button type="button" onclick="submitDistribution()" class="btn-primary-small">Confirm Distribution</button>
            </div>
        </div>
    </div>

    <script>
        let selectedCategories = new Set();
        const alreadyReceivedToday = <?php echo json_encode($already_received_lookup); ?>;

        function toggleCategorySelection(cardElement, categoryId) {
            const checkbox = document.getElementById(`category-${categoryId}`);
            checkbox.checked = !checkbox.checked;

            if (checkbox.checked) {
                selectedCategories.add(categoryId);
                cardElement.classList.add('selected');
            } else {
                selectedCategories.delete(categoryId);
                cardElement.classList.remove('selected');
            }
            updateSelectionCount();
        }

        function toggleCategorySelectionById(categoryId) {
            const checkbox = document.getElementById(`category-${categoryId}`);
            const cardElement = checkbox.closest('.category-card');

            if (checkbox.checked) {
                selectedCategories.add(categoryId);
                cardElement.classList.add('selected');
            } else {
                selectedCategories.delete(categoryId);
                cardElement.classList.remove('selected');
            }
            updateSelectionCount();
        }

        function updateSelectionCount() {
            const count = selectedCategories.size;
            document.getElementById('selectedCount').textContent = count;
            document.getElementById('selectedCountBadge').textContent = count;

            const distributeBtn = document.getElementById('distributeBtn');
            distributeBtn.disabled = count === 0;
        }

        function openDistributeModal() {
            if (selectedCategories.size === 0) {
                showToast('error', 'Please select at least one subscription');
                return;
            }
            document.getElementById('modalSelectedCountDisplay').textContent = selectedCategories.size;
            document.getElementById('distributeModal').style.display = 'flex';
        }

        function closeDistributeModal() {
            document.getElementById('distributeModal').style.display = 'none';
            document.getElementById('modal_recipient_select').value = '';
            document.getElementById('modal_distributed_by').value = '';
        }

        function submitDistribution() {
            const recipientSelect = document.getElementById('modal_recipient_select');
            const recipientId = recipientSelect.value;
            const distributedBy = document.getElementById('modal_distributed_by').value.trim();

            if (!recipientId) {
                showToast('error', 'Please select a recipient');
                return;
            }
            if (!distributedBy) {
                showToast('error', 'Please enter who is distributing');
                return;
            }

            const recipientName = recipientSelect.options[recipientSelect.selectedIndex].text.trim();
            if (alreadyReceivedToday[recipientName]) {
                showToast('error', `Newspaper(s) have been distributed to ${recipientName} already.`);
                return;
            }

            const formData = new FormData();
            formData.append('distribute_submit', '1');
            formData.append('recipient_id', recipientId);
            formData.append('distributed_by', distributedBy);

            const selectedArray = Array.from(selectedCategories);
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
