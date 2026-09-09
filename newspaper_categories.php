<?php
// newspaper_categories.php
include "./config/db.php";
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/csrf.php';

$message = '';
$error = '';

// Handle add category
if (isset($_POST['save'])) {
    csrf_check_post();
    $name = trim($_POST['name']);

    if (!empty($name)) {
        $stmt = $conn->prepare("INSERT INTO newspaper_categories (category_name) VALUES (?)");
        $stmt->bind_param("s", $name);

        if ($stmt->execute()) {
            $message = "Category added successfully!";
        } else {
            $error = "Error adding category.";
        }
        $stmt->close();
    } else {
        $error = "Category name is required";
    }
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_category'])) {
    csrf_check_post();
    $id = (int)$_POST['delete_category'];
    $stmt = $conn->prepare("DELETE FROM newspaper_categories WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $message = "Category deleted successfully!";
    } else {
        $error = "Error deleting category.";
    }
    $stmt->close();
}

// Pagination settings
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Get total count for pagination
$count_result = $conn->query("SELECT COUNT(*) as total FROM newspaper_categories");
$total_categories = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_categories / $limit);

// Get categories with pagination
$result = $conn->query("SELECT * FROM newspaper_categories ORDER BY id DESC LIMIT $offset, $limit");

// Get all categories for export without limit/offset
$all_categories_export = [];
$export_res = $conn->query("SELECT * FROM newspaper_categories ORDER BY id DESC");
if ($export_res) {
    while ($row = $export_res->fetch_assoc()) {
        $all_categories_export[] = [
            'id' => $row['id'],
            'category_name' => $row['category_name'],
            'created_at' => $row['created_at'] ? date('Y-m-d H:i:s', strtotime($row['created_at'])) : ''
        ];
    }
}

// Build a pagination URL preserving only the page parameter
function buildCategoriesUrl($overrides = [])
{
    $params = $_GET;
    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    return 'newspaper_categories.php' . (!empty($params) ? '?' . http_build_query($params) : '');
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Newspaper Categories - Mailroom Ops</title>
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
                        <a href="index.php">Management</a>
                        <span class="sep">/</span>
                        <span>Newspaper Categories</span>
                    </div>
                    <h1 class="page-header-title">Newspaper Categories</h1>
                    <p class="page-header-subtitle">Manage the subscription categories used to organize newspapers.</p>
                </div>
                <div class="header-actions flex items-center gap-2 print-hide">
                    <button onclick="exportCategories()" class="btn btn-soft">
                        <i class="fa-regular fa-file-excel"></i>
                        <span class="hidden sm:inline">Export CSV</span>
                    </button>
                    <button onclick="MailroomModal.open('categoryModal')" class="btn btn-primary">
                        <i class="fa-solid fa-plus"></i>
                        <span class="hidden sm:inline">Add Category</span>
                    </button>
                </div>
            </div>

            <div class="page-body">
                <!-- Categories Table -->
                <div class="card">
                    <div class="card-header" style="padding:14px 20px;">
                        <div>
                            <div class="card-title">Subscription Categories</div>
                            <div class="card-subtitle">Categories available for newspaper classification.</div>
                        </div>
                        <span class="filter-chip"><i class="fa-solid fa-layer-group"></i> Total: <?php echo $total_categories; ?></span>
                    </div>

                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Category Name</th>
                                    <th class="hidden md:table-cell">Created</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($result && $result->num_rows > 0): ?>
                                    <?php while ($row = $result->fetch_assoc()): ?>
                                        <tr>
                                            <td class="hidden md:table-cell"><span class="table-cell-mono text-[#7d8398]"><?php echo $row['id']; ?></span></td>
                                            <td><span class="table-cell-title"><?php echo htmlspecialchars($row['category_name']); ?></span></td>
                                            <td class="hidden md:table-cell"><span class="text-xs text-[#4b5570]"><?php echo $row['created_at'] ? date('M j, Y', strtotime($row['created_at'])) : '-'; ?></span></td>
                                            <td>
                                                <button type="button" class="icon-btn danger" title="Delete category"
                                                    onclick="openConfirmModal(<?php echo $row['id']; ?>, <?php echo json_encode($row['category_name']); ?>)">
                                                    <i class="fa-regular fa-trash-can"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4">
                                            <div class="empty-state">
                                                <div class="empty-state-icon"><i class="fa-solid fa-layer-group"></i></div>
                                                <div class="empty-state-title">No categories found</div>
                                                <div class="empty-state-text">Add a category to get started.</div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($result && $result->num_rows > 0 && $total_pages > 1): ?>
                        <div class="pagination-shell">
                            <div class="pagination-meta">
                                <div class="pagination-title">Showing <?php echo $result->num_rows; ?> <?php echo $result->num_rows === 1 ? 'category' : 'categories'; ?> on this page</div>
                                <div>Records <?php echo $offset + 1; ?>-<?php echo min($offset + $limit, $total_categories); ?> of <?php echo $total_categories; ?> total</div>
                            </div>
                            <div class="pagination-controls">
                                <div class="pagination-page-indicator">Page <?php echo $page; ?> of <?php echo $total_pages; ?></div>
                                <div class="pagination">
                                    <a class="pagination-item compact <?php echo $page === 1 ? 'disabled' : ''; ?>" <?php echo $page === 1 ? 'aria-disabled="true" tabindex="-1"' : 'href="' . htmlspecialchars(buildCategoriesUrl(['page' => null])) . '"'; ?> aria-label="First page">
                                        <i class="fa-solid fa-chevrons-left"></i>
                                    </a>
                                    <a class="pagination-item compact <?php echo $page === 1 ? 'disabled' : ''; ?>" <?php echo $page === 1 ? 'aria-disabled="true" tabindex="-1"' : 'href="' . htmlspecialchars(buildCategoriesUrl(['page' => $page - 1])) . '"'; ?> aria-label="Previous page">
                                        <i class="fa-solid fa-chevron-left"></i>
                                    </a>

                                    <?php
                                    $startPage = max(1, $page - 2);
                                    $endPage = min($total_pages, $page + 2);

                                    if ($startPage > 1) {
                                        echo '<a class="pagination-item" href="' . htmlspecialchars(buildCategoriesUrl(['page' => 1])) . '">1</a>';
                                        if ($startPage > 2) {
                                            echo '<span class="pagination-ellipsis">...</span>';
                                        }
                                    }

                                    for ($i = $startPage; $i <= $endPage; $i++) {
                                        $activeClass = $i == $page ? 'active' : '';
                                        if ($i == $page) {
                                            echo '<span class="pagination-item active">' . $i . '</span>';
                                        } else {
                                            echo '<a class="pagination-item" href="' . htmlspecialchars(buildCategoriesUrl(['page' => $i])) . '">' . $i . '</a>';
                                        }
                                    }

                                    if ($endPage < $total_pages) {
                                        if ($endPage < $total_pages - 1) {
                                            echo '<span class="pagination-ellipsis">...</span>';
                                        }
                                        echo '<a class="pagination-item" href="' . htmlspecialchars(buildCategoriesUrl(['page' => $total_pages])) . '">' . $total_pages . '</a>';
                                    }
                                    ?>

                                    <a class="pagination-item compact <?php echo $page >= $total_pages ? 'disabled' : ''; ?>" <?php echo $page >= $total_pages ? 'aria-disabled="true" tabindex="-1"' : 'href="' . htmlspecialchars(buildCategoriesUrl(['page' => $page + 1])) . '"'; ?> aria-label="Next page">
                                        <i class="fa-solid fa-chevron-right"></i>
                                    </a>
                                    <a class="pagination-item compact <?php echo $page >= $total_pages ? 'disabled' : ''; ?>" <?php echo $page >= $total_pages ? 'aria-disabled="true" tabindex="-1"' : 'href="' . htmlspecialchars(buildCategoriesUrl(['page' => $total_pages])) . '"'; ?> aria-label="Last page">
                                        <i class="fa-solid fa-chevrons-right"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Category Modal -->
    <div id="categoryModal" class="modal-backdrop" style="display:none;">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title">Add Category</h3>
                <button type="button" class="modal-close" onclick="MailroomModal.close('categoryModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST">
                <?php csrf_field(); ?>
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-field span-2">
                            <label class="label">Category Name <span class="req">*</span></label>
                            <input type="text" name="name" required
                                placeholder="e.g., Daily News, Sports, Business"
                                class="input" autocomplete="off">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-soft" onclick="MailroomModal.close('categoryModal')">Cancel</button>
                    <button type="submit" name="save" class="btn btn-primary">
                        <i class="fa-regular fa-floppy-disk"></i> Save Category
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Confirm Delete Modal -->
    <div id="confirmModal" class="modal-backdrop" style="display:none;">
        <div class="modal-dialog sm">
            <div class="modal-header">
                <h3 class="modal-title">Confirm Delete</h3>
                <button type="button" class="modal-close" onclick="MailroomModal.close('confirmModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <p id="confirmDeleteMessage" style="font-size:13px;color:var(--text-secondary);line-height:1.5;">
                    Are you sure you want to delete this category? This action cannot be undone.
                </p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-soft" onclick="MailroomModal.close('confirmModal')">Cancel</button>
                <button id="confirmDeleteBtn" class="btn btn-danger">
                    <i class="fa-regular fa-trash-can"></i> Delete
                </button>
            </div>
        </div>
    </div>

    <script>
        function openConfirmModal(id, name) {
            document.getElementById('confirmDeleteMessage').textContent =
                'Are you sure you want to delete "' + (name || 'this category') + '"? This action cannot be undone.';
            MailroomModal.open('confirmModal');
            document.getElementById('confirmDeleteBtn').onclick = function() {
                submitPostForm('newspaper_categories.php', {
                    csrf_token: '<?php echo csrf_token(); ?>',
                    delete_category: id
                });
            };
        }

        <?php if ($message): ?>
            document.addEventListener('DOMContentLoaded', function() {
                MailroomToast.success(<?php echo json_encode($message); ?>);
            });
        <?php endif; ?>
        <?php if ($error): ?>
            document.addEventListener('DOMContentLoaded', function() {
                MailroomToast.error(<?php echo json_encode($error); ?>);
            });
        <?php endif; ?>

        function exportCategories() {
            const data = <?php echo json_encode($all_categories_export); ?>;
            exportToCSV(data, 'newspaper_categories_' + new Date().toISOString().split('T')[0] + '.csv');
            MailroomToast.success('Export completed successfully!');
        }
    </script>
    <script src="assets/app.js"></script>
</body>

</html>