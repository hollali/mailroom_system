<?php
// recipients.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once './config/db.php';
session_start();

// Handle Add Recipient
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_recipient'])) {
    $name = trim($_POST['name']);

    if (!empty($name)) {
        $stmt = $conn->prepare("INSERT INTO recipients (name) VALUES (?)");
        $stmt->bind_param("s", $name);

        if ($stmt->execute()) {
            $_SESSION['toast'] = [
                'type' => 'success',
                'message' => "Recipient added successfully"
            ];
        } else {
            $_SESSION['toast'] = [
                'type' => 'error',
                'message' => "Error adding recipient: " . $conn->error
            ];
        }
    } else {
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => "Recipient name cannot be empty"
        ];
    }

    header('Location: recipients.php');
    exit();
}

// Handle Edit Recipient
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_recipient'])) {
    $id = (int)$_POST['id'];
    $name = trim($_POST['name']);

    if (!empty($name)) {
        $stmt = $conn->prepare("UPDATE recipients SET name = ? WHERE id = ?");
        $stmt->bind_param("si", $name, $id);

        if ($stmt->execute()) {
            $_SESSION['toast'] = [
                'type' => 'success',
                'message' => "Recipient updated successfully"
            ];
        } else {
            $_SESSION['toast'] = [
                'type' => 'error',
                'message' => "Error updating recipient: " . $conn->error
            ];
        }
    } else {
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => "Recipient name cannot be empty"
        ];
    }

    header('Location: recipients.php');
    exit();
}

// Handle Delete Recipient
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];

    // Get recipient name first
    $name_query = $conn->query("SELECT name FROM recipients WHERE id = $id");
    $recipient = $name_query->fetch_assoc();
    $name = $recipient['name'] ?? '';

    // Check if recipient is used in distributions
    $check = $conn->query("SELECT COUNT(*) as count FROM distribution WHERE distributed_to LIKE '%" . $conn->real_escape_string($name) . "%'");
    $result = $check->fetch_assoc();

    if ($result['count'] > 0) {
        // Instead of deleting, deactivate
        $stmt = $conn->prepare("UPDATE recipients SET is_active = 0 WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $_SESSION['toast'] = [
            'type' => 'warning',
            'message' => "Recipient has distribution records. Deactivated instead of deleted."
        ];
    } else {
        // Delete if no distributions
        $stmt = $conn->prepare("DELETE FROM recipients WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $_SESSION['toast'] = [
            'type' => 'success',
            'message' => "Recipient deleted successfully"
        ];
    }

    header('Location: recipients.php');
    exit();
}

// Handle Activate Recipient
if (isset($_GET['activate'])) {
    $id = (int)$_GET['activate'];
    $stmt = $conn->prepare("UPDATE recipients SET is_active = 1 WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $_SESSION['toast'] = [
        'type' => 'success',
        'message' => "Recipient activated successfully"
    ];
    header('Location: recipients.php');
    exit();
}

// Pagination settings
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Get total count for pagination
$count_result = $conn->query("SELECT COUNT(*) as total FROM recipients");
$total_recipients = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_recipients / $limit);

// Get recipients with pagination
$recipients = $conn->query("SELECT * FROM recipients ORDER BY is_active DESC, name ASC LIMIT $offset, $limit");

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
    <title>Manage Recipients - Mailroom</title>
    <link rel="icon" type="image/png" href="./images/logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #f5f5f4;
        }

        .stat-card {
            background: white;
            border: 1px solid #e5e5e5;
            padding: 1.25rem;
            border-radius: 0.5rem;
            transition: all 0.2s ease;
        }

        .stat-card:hover {
            border-color: #9e9e9e;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .action-btn {
            color: #9e9e9e;
            transition: color 0.2s;
            margin: 0 0.25rem;
            background: none;
            border: none;
            cursor: pointer;
        }

        .action-btn:hover {
            color: #1e1e1e;
        }

        .delete-btn:hover {
            color: #dc2626;
        }

        .edit-btn:hover {
            color: #3b82f6;
        }

        .activate-btn:hover {
            color: #10b981;
        }

        .pagination-shell {
            padding: 1rem 1.25rem;
            border-top: 1px solid #e5e5e5;
            background: linear-gradient(180deg, #ffffff 0%, #fafaf9 100%);
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .pagination-meta {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .pagination-title {
            font-size: 0.95rem;
            font-weight: 600;
            color: #1c1917;
        }

        .pagination-subtitle {
            font-size: 0.82rem;
            color: #78716c;
        }

        .pagination-controls {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: flex-end;
            gap: 0.75rem;
        }

        .pagination-page-indicator {
            padding: 0.45rem 0.85rem;
            border-radius: 9999px;
            background-color: #f5f5f4;
            color: #44403c;
            font-size: 0.82rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .pagination {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.4rem;
        }

        .pagination-item {
            min-width: 2.5rem;
            height: 2.5rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 0.85rem;
            border: 1px solid #e7e5e4;
            border-radius: 0.8rem;
            background: white;
            color: #292524;
            font-size: 0.875rem;
            font-weight: 500;
            box-shadow: 0 1px 2px rgba(28, 25, 23, 0.04);
            transition: all 0.2s ease;
        }

        .pagination-item:hover {
            background-color: #f5f5f4;
            border-color: #d6d3d1;
            transform: translateY(-1px);
        }

        .pagination-item.active {
            background-color: #1c1917;
            color: white;
            border-color: #1c1917;
            box-shadow: 0 10px 20px rgba(28, 25, 23, 0.14);
        }

        .pagination-item.disabled {
            opacity: 0.45;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .pagination-item.compact {
            min-width: auto;
            padding: 0 0.9rem;
        }

        .pagination-ellipsis {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 2.5rem;
            height: 2.5rem;
            color: #a8a29e;
            font-size: 0.95rem;
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

        .toast-warning {
            border-left: 4px solid #f59e0b;
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

        .modal {
            transition: opacity 0.3s ease;
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
                        <h1 class="text-2xl font-medium text-[#1e1e1e]">Manage Recipients</h1>
                        <p class="text-sm text-[#6e6e6e] mt-1">Add, edit, and manage distribution recipients</p>
                    </div>
                    <button onclick="openAddModal()" class="px-4 py-2 text-sm bg-[#1e1e1e] text-white rounded-md hover:bg-[#2d2d2d]">
                        <i class="fa-solid fa-plus mr-1"></i> Add Recipient
                    </button>
                </div>
            </div>

            <div class="p-8">
                <!-- Recipients Table -->
                <div class="bg-white border border-[#e5e5e5] rounded-lg overflow-hidden">
                    <div class="px-5 py-4 bg-[#fafafa] border-b border-[#e5e5e5]">
                        <h3 class="text-sm font-medium text-[#1e1e1e]">Recipients List</h3>
                        <p class="text-xs text-[#6e6e6e] mt-1">Format: Name - Department/Office (e.g., John Doe - HR Department)</p>
                    </div>

                    <?php if ($recipients && $recipients->num_rows > 0): ?>
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead>
                                    <tr class="bg-[#fafafa] border-b border-[#e5e5e5]">
                                        <th class="text-left p-3 text-xs font-medium text-[#6e6e6e]">#</th>
                                        <th class="text-left p-3 text-xs font-medium text-[#6e6e6e]">Recipient Name</th>
                                        <th class="text-left p-3 text-xs font-medium text-[#6e6e6e]">Created</th>
                                        <th class="text-left p-3 text-xs font-medium text-[#6e6e6e]">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $counter = ($page - 1) * $limit + 1;
                                    while ($recipient = $recipients->fetch_assoc()): ?>
                                        <tr class="border-b border-[#f0f0f0] hover:bg-[#fafafa]">
                                            <td class="p-3 text-sm"><?php echo $counter++; ?></td>
                                            <td class="p-3 text-sm font-medium"><?php echo htmlspecialchars($recipient['name']); ?></td>
                                            <td class="p-3 text-sm text-[#6e6e6e]">
                                                <?php echo date('M j, Y', strtotime($recipient['created_at'])); ?>
                                            </td>
                                            <td class="p-3">
                                                <div class="flex items-center gap-2">
                                                    <button onclick="editRecipient(<?php echo $recipient['id']; ?>, '<?php echo htmlspecialchars(addslashes($recipient['name'])); ?>')"
                                                        class="action-btn edit-btn" title="Edit">
                                                        <i class="fa-regular fa-pen-to-square"></i>
                                                    </button>
                                                    <?php if ($recipient['is_active']): ?>
                                                        <button onclick="deleteRecipient(<?php echo $recipient['id']; ?>, '<?php echo htmlspecialchars(addslashes($recipient['name'])); ?>')"
                                                            class="action-btn delete-btn" title="Delete/Deactivate">
                                                            <i class="fa-regular fa-trash-can"></i>
                                                        </button>
                                                    <?php else: ?>
                                                        <a href="?activate=<?php echo $recipient['id']; ?>"
                                                            class="action-btn activate-btn" title="Activate"
                                                            onclick="return confirm('Activate this recipient?')">
                                                            <i class="fa-regular fa-circle-check"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <div class="pagination-shell">
                                <div class="pagination-meta">
                                    <div class="pagination-title">
                                        Showing <?php echo min($limit, $total_recipients - ($page - 1) * $limit); ?> recipient(s) on this page
                                    </div>
                                    <div class="pagination-subtitle">
                                        Records <?php echo ($page - 1) * $limit + 1; ?>-<?php echo min($page * $limit, $total_recipients); ?> of <?php echo $total_recipients; ?> total
                                    </div>
                                </div>
                                <div class="pagination-controls">
                                    <div class="pagination-page-indicator">Page <?php echo $page; ?> of <?php echo $total_pages; ?></div>
                                    <div class="pagination">
                                        <?php if ($page > 1): ?>
                                            <a href="?page=1" class="pagination-item compact" aria-label="First page">
                                                <i class="fa-solid fa-chevrons-left"></i>
                                            </a>
                                            <a href="?page=<?php echo $page - 1; ?>" class="pagination-item compact" aria-label="Previous page">
                                                <i class="fa-solid fa-chevron-left"></i>
                                            </a>
                                        <?php endif; ?>

                                        <?php
                                        $start = max(1, $page - 2);
                                        $end = min($total_pages, $page + 2);

                                        if ($start > 1) {
                                            echo '<a href="?page=1" class="pagination-item">1</a>';
                                            if ($start > 2) {
                                                echo '<span class="pagination-ellipsis">...</span>';
                                            }
                                        }

                                        for ($i = $start; $i <= $end; $i++) {
                                            $active_class = ($i == $page) ? 'active' : '';
                                            echo '<a href="?page=' . $i . '" class="pagination-item ' . $active_class . '">' . $i . '</a>';
                                        }

                                        if ($end < $total_pages) {
                                            if ($end < $total_pages - 1) {
                                                echo '<span class="pagination-ellipsis">...</span>';
                                            }
                                            echo '<a href="?page=' . $total_pages . '" class="pagination-item">' . $total_pages . '</a>';
                                        }
                                        ?>

                                        <?php if ($page < $total_pages): ?>
                                            <a href="?page=<?php echo $page + 1; ?>" class="pagination-item compact" aria-label="Next page">
                                                <i class="fa-solid fa-chevron-right"></i>
                                            </a>
                                            <a href="?page=<?php echo $total_pages; ?>" class="pagination-item compact" aria-label="Last page">
                                                <i class="fa-solid fa-chevrons-right"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="text-center py-8 text-[#6e6e6e]">
                            <i class="fa-regular fa-user text-3xl mb-2"></i>
                            <p>No recipients found</p>
                            <button onclick="openAddModal()" class="inline-block mt-3 text-sm text-blue-600 hover:underline">
                                Add your first recipient →
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Recipient Modal -->
    <div id="addModal" class="fixed inset-0 bg-[#000000] bg-opacity-20 hidden items-center justify-center z-50 modal">
        <div class="bg-white border border-[#e5e5e5] rounded-md w-full max-w-md p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-medium text-[#1e1e1e]">Add Recipient</h2>
                <button type="button" onclick="closeAddModal()" class="text-[#9e9e9e] hover:text-[#1e1e1e]">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>

            <form method="POST" action="recipients.php">
                <div class="mb-4">
                    <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Recipient Name *</label>
                    <input type="text" name="name" required
                        class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]"
                        placeholder="e.g., John Doe - HR Department">
                    <p class="text-xs text-[#6e6e6e] mt-1">Format: Name - Department/Office</p>
                </div>

                <div class="flex justify-end gap-2 mt-6">
                    <button type="button" onclick="closeAddModal()"
                        class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                        Cancel
                    </button>
                    <button type="submit" name="add_recipient"
                        class="px-4 py-2 text-sm bg-[#1e1e1e] text-white rounded-md hover:bg-[#2d2d2d]">
                        Add Recipient
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Recipient Modal -->
    <div id="editModal" class="fixed inset-0 bg-[#000000] bg-opacity-20 hidden items-center justify-center z-50 modal">
        <div class="bg-white border border-[#e5e5e5] rounded-md w-full max-w-md p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-medium text-[#1e1e1e]">Edit Recipient</h2>
                <button type="button" onclick="closeEditModal()" class="text-[#9e9e9e] hover:text-[#1e1e1e]">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>

            <form method="POST" action="recipients.php">
                <input type="hidden" name="id" id="edit_id">
                <div class="mb-4">
                    <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Recipient Name *</label>
                    <input type="text" name="name" id="edit_name" required
                        class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]"
                        placeholder="e.g., John Doe - HR Department">
                    <p class="text-xs text-[#6e6e6e] mt-1">Format: Name - Department/Office</p>
                </div>

                <div class="flex justify-end gap-2 mt-6">
                    <button type="button" onclick="closeEditModal()"
                        class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                        Cancel
                    </button>
                    <button type="submit" name="edit_recipient"
                        class="px-4 py-2 text-sm bg-[#1e1e1e] text-white rounded-md hover:bg-[#2d2d2d]">
                        Update Recipient
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="fixed inset-0 bg-[#000000] bg-opacity-20 hidden items-center justify-center z-50 modal">
        <div class="bg-white border border-[#e5e5e5] rounded-md w-full max-w-md p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-medium text-[#1e1e1e]">Confirm Action</h2>
                <button type="button" onclick="closeDeleteModal()" class="text-[#9e9e9e] hover:text-[#1e1e1e]">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>

            <div class="py-2">
                <p class="text-sm text-[#6e6e6e]" id="deleteMessage">Are you sure you want to delete this recipient?</p>
                <p class="text-xs text-[#9e9e9e] mt-3" id="deleteNote">
                    <i class="fa-solid fa-circle-info mr-1"></i>
                    If this recipient has distribution records, they will be deactivated instead of deleted.
                </p>
            </div>

            <div class="flex justify-end gap-2 mt-6">
                <button onclick="closeDeleteModal()"
                    class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                    Cancel
                </button>
                <a href="#" id="confirmDeleteBtn"
                    class="px-4 py-2 text-sm bg-red-600 text-white rounded-md hover:bg-red-700">
                    Confirm
                </a>
            </div>
        </div>
    </div>

    <script>
        // Toast Notification
        function showToast(type, message) {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;

            const icon = type === 'success' ? 'fa-circle-check' : (type === 'error' ? 'fa-circle-exclamation' : 'fa-triangle-exclamation');

            toast.innerHTML = `
                <div class="flex items-center gap-3">
                    <i class="fa-regular ${icon} text-${type === 'success' ? 'green' : (type === 'error' ? 'red' : 'orange')}-500"></i>
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

        // Modal Functions
        function openAddModal() {
            const modal = document.getElementById('addModal');
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
        }

        function closeAddModal() {
            const modal = document.getElementById('addModal');
            modal.classList.add('hidden');
            modal.style.display = 'none';
        }

        function editRecipient(id, name) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_name').value = name;
            const modal = document.getElementById('editModal');
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
        }

        function closeEditModal() {
            const modal = document.getElementById('editModal');
            modal.classList.add('hidden');
            modal.style.display = 'none';
        }

        let currentDeleteId = null;

        function deleteRecipient(id, name) {
            currentDeleteId = id;
            document.getElementById('deleteMessage').innerHTML = `Are you sure you want to delete "<strong>${escapeHtml(name)}</strong>"?`;
            document.getElementById('confirmDeleteBtn').href = `?delete=${id}`;
            const modal = document.getElementById('deleteModal');
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
        }

        function closeDeleteModal() {
            const modal = document.getElementById('deleteModal');
            modal.classList.add('hidden');
            modal.style.display = 'none';
            currentDeleteId = null;
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const addModal = document.getElementById('addModal');
            const editModal = document.getElementById('editModal');
            const deleteModal = document.getElementById('deleteModal');

            if (event.target == addModal) {
                closeAddModal();
            }
            if (event.target == editModal) {
                closeEditModal();
            }
            if (event.target == deleteModal) {
                closeDeleteModal();
            }
        }

        // ESC key to close modals
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAddModal();
                closeEditModal();
                closeDeleteModal();
            }
        });
    </script>
</body>

</html>