<?php
// categories.php
include "./config/db.php";

$message = '';
$error = '';

if (isset($_POST['save'])) {
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
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
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
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Newspaper Subscription - Mailroom</title>
    <link rel="icon" type="image/png" href="./images/logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #f5f5f4;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 0.75rem 1rem;
            border-bottom: 2px solid #e5e5e5;
            font-weight: 500;
            color: #4a4a4a;
            font-size: 0.75rem;
        }

        td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #e5e5e5;
            font-size: 0.875rem;
            color: #1e1e1e;
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
    </style>
</head>

<body class="bg-[#f5f5f4]">
    <?php include './sidebar.php'; ?>

    <main class="ml-60 min-h-screen">
        <!-- Header -->
        <div class="px-8 py-6 border-b border-[#e5e5e5] bg-white">
            <div class="flex justify-between items-center">
                <h1 class="text-2xl font-medium text-[#1e1e1e]">Newspaper Subscription</h1>
                <button onclick="openModal()"
                    class="px-3 py-1.5 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                    <i class="fa-regular fa-plus mr-1 text-[#6e6e6e]"></i> Add Subscription
                </button>
            </div>
        </div>

        <div class="p-8">
            <!-- Messages -->
            <?php if ($message): ?>
                <div id="successToast" class="mb-6 p-3 border border-[#e5e5e5] bg-white rounded-md text-sm text-[#1e1e1e] transition-opacity duration-500">
                    <i class="fa-regular fa-circle-check mr-2 text-[#4a4a4a]"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div id="errorToast" class="mb-6 p-3 border border-[#e5e5e5] bg-white rounded-md text-sm text-[#1e1e1e] transition-opacity duration-500">
                    <i class="fa-regular fa-circle-exclamation mr-2 text-[#4a4a4a]"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <!-- Categories Table -->
            <div class="bg-white border border-[#e5e5e5] rounded-md overflow-hidden">
                <table>
                    <thead>
                        <tr class="bg-[#fafafa]">
                            <th class="text-xs">ID</th>
                            <th class="text-xs">Category Name</th>
                            <th class="text-xs">Created</th>
                            <th class="text-xs">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="categoriesTableBody">
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <tr class="hover:bg-[#fafafa] category-row">
                                    <td class="text-sm text-[#6e6e6e]"><?php echo $row['id']; ?></td>
                                    <td class="text-sm font-medium text-[#1e1e1e]">
                                        <?php echo htmlspecialchars($row['category_name']); ?>
                                    </td>
                                    <td class="text-sm text-[#1e1e1e]">
                                        <?php echo $row['created_at'] ? date('M j, Y', strtotime($row['created_at'])) : '-'; ?>
                                    </td>
                                    <td class="text-sm">
                                        <a href="#" onclick="openConfirmModal(<?php echo $row['id']; ?>)"
                                            class="text-[#9e9e9e] hover:text-[#1e1e1e]">
                                            <i class="fa-regular fa-trash-can"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="text-sm text-[#6e6e6e] text-center py-8">
                                    No categories found. Add one to get started.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <div id="categoriesPagination" class="pagination-shell <?php echo (!$result || $result->num_rows === 0) ? 'hidden' : ''; ?>">
                    <div class="pagination-meta">
                        <div id="categoriesPaginationTitle" class="pagination-title"></div>
                        <div id="categoriesPaginationInfo" class="pagination-subtitle"></div>
                    </div>
                    <div class="pagination-controls">
                        <div id="categoriesPaginationPage" class="pagination-page-indicator"></div>
                        <div class="pagination" id="categoriesPaginationControls"></div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal -->
    <div id="categoryModal"
        class="fixed inset-0 bg-[#000000] bg-opacity-20 hidden items-center justify-center z-50"
        style="display: none;">
        <div class="bg-white border border-[#e5e5e5] rounded-md w-full max-w-md p-5">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-base font-medium text-[#1e1e1e]">Add Category</h3>
                <button onclick="closeModal()" class="text-[#9e9e9e] hover:text-[#1e1e1e]">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form method="POST">
                <div class="space-y-4">
                    <div>
                        <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Category Name</label>
                        <input type="text"
                            name="name"
                            required
                            placeholder="e.g., Daily News, Sports, Business"
                            class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]"
                            autocomplete='off'>
                    </div>
                </div>

                <div class="flex justify-end gap-2 mt-6">
                    <button type="button"
                        onclick="closeModal()"
                        class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                        Cancel
                    </button>
                    <button name="save"
                        class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                        <i class="fa-regular fa-floppy-disk mr-1 text-[#6e6e6e]"></i>
                        Save Category
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div id="confirmModal"
        class="fixed inset-0 bg-[#000000] bg-opacity-20 hidden items-center justify-center z-50"
        style="display: none;">
        <div class="bg-white border border-[#e5e5e5] rounded-md w-full max-w-sm p-5">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-base font-medium text-[#1e1e1e]">Confirm Delete</h3>
                <button onclick="closeConfirmModal()" class="text-[#9e9e9e] hover:text-[#1e1e1e]">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <p class="text-sm text-[#6e6e6e] mb-6">Are you sure you want to delete this category? This action cannot be undone.</p>

            <div class="flex justify-end gap-2">
                <button onclick="closeConfirmModal()"
                    class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                    Cancel
                </button>
                <button id="confirmDeleteBtn"
                    class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                    Delete
                </button>
            </div>
        </div>
    </div>

    <script>
        function openModal() {
            document.getElementById('categoryModal').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('categoryModal').style.display = 'none';
        }

        function openConfirmModal(id) {
            document.getElementById('confirmModal').style.display = 'flex';
            document.getElementById('confirmDeleteBtn').onclick = function() {
                window.location.href = '?delete=' + id;
            };
        }

        function closeConfirmModal() {
            document.getElementById('confirmModal').style.display = 'none';
        }

        const categoriesPageSize = <?php echo $limit; ?>;
        let categoriesCurrentPage = <?php echo $page; ?>;

        function renderCategoriesPagination() {
            const wrapper = document.getElementById('categoriesPagination');
            const title = document.getElementById('categoriesPaginationTitle');
            const info = document.getElementById('categoriesPaginationInfo');
            const pageIndicator = document.getElementById('categoriesPaginationPage');
            const controls = document.getElementById('categoriesPaginationControls');

            if (!wrapper || !title || !info || !pageIndicator || !controls) {
                return;
            }

            const totalPages = <?php echo $total_pages; ?>;
            const totalRecords = <?php echo $total_categories; ?>;
            const currentPage = <?php echo $page; ?>;
            const visibleCount = <?php echo $result ? $result->num_rows : 0; ?>;

            if (totalPages <= 1) {
                wrapper.classList.add('hidden');
                return;
            }

            wrapper.classList.remove('hidden');

            const from = <?php echo $offset + 1; ?>;
            const to = <?php echo min($offset + $limit, $total_categories); ?>;

            title.textContent = `Showing ${visibleCount} ${visibleCount === 1 ? 'category' : 'categories'} on this page`;
            info.textContent = `Records ${from}-${to} of ${totalRecords} total`;
            pageIndicator.textContent = `Page ${currentPage} of ${totalPages}`;

            const startPage = Math.max(1, currentPage - 2);
            const endPage = Math.min(totalPages, currentPage + 2);

            let controlsHtml = `
                <button class="pagination-item compact ${currentPage === 1 ? 'disabled' : ''}" ${currentPage === 1 ? 'disabled' : ''} onclick="changeCategoriesPage(1)" aria-label="First page">
                    <i class="fa-solid fa-chevrons-left"></i>
                </button>
                <button class="pagination-item compact ${currentPage === 1 ? 'disabled' : ''}" ${currentPage === 1 ? 'disabled' : ''} onclick="changeCategoriesPage(${currentPage - 1})" aria-label="Previous page">
                    <i class="fa-solid fa-chevron-left"></i>
                </button>
            `;

            if (startPage > 1) {
                controlsHtml += `<button class="pagination-item" onclick="changeCategoriesPage(1)">1</button>`;
                if (startPage > 2) {
                    controlsHtml += `<span class="pagination-ellipsis">...</span>`;
                }
            }

            for (let i = startPage; i <= endPage; i++) {
                controlsHtml += `<button class="pagination-item ${i === currentPage ? 'active' : ''}" onclick="changeCategoriesPage(${i})">${i}</button>`;
            }

            if (endPage < totalPages) {
                if (endPage < totalPages - 1) {
                    controlsHtml += `<span class="pagination-ellipsis">...</span>`;
                }
                controlsHtml += `<button class="pagination-item" onclick="changeCategoriesPage(${totalPages})">${totalPages}</button>`;
            }

            controlsHtml += `
                <button class="pagination-item compact ${currentPage === totalPages ? 'disabled' : ''}" ${currentPage === totalPages ? 'disabled' : ''} onclick="changeCategoriesPage(${currentPage + 1})" aria-label="Next page">
                    <i class="fa-solid fa-chevron-right"></i>
                </button>
                <button class="pagination-item compact ${currentPage === totalPages ? 'disabled' : ''}" ${currentPage === totalPages ? 'disabled' : ''} onclick="changeCategoriesPage(${totalPages})" aria-label="Last page">
                    <i class="fa-solid fa-chevrons-right"></i>
                </button>
            `;

            controls.innerHTML = controlsHtml;
        }

        function changeCategoriesPage(page) {
            const url = new URL(window.location);
            url.searchParams.set('page', page);
            window.location.href = url.toString();
        }

        document.addEventListener('DOMContentLoaded', function() {
            renderCategoriesPagination();

            // Auto-hide toast notifications after 5 seconds
            const successToast = document.getElementById('successToast');
            const errorToast = document.getElementById('errorToast');

            if (successToast) {
                setTimeout(() => {
                    successToast.style.opacity = '0';
                    setTimeout(() => successToast.style.display = 'none', 500);
                }, 5000);
            }

            if (errorToast) {
                setTimeout(() => {
                    errorToast.style.opacity = '0';
                    setTimeout(() => errorToast.style.display = 'none', 500);
                }, 5000);
            }
        });

        function changeCategoriesPage(page) {
            categoriesCurrentPage = Math.max(1, page);
            renderCategoriesPagination();
        }
        window.onclick = function(event) {
            const modal = document.getElementById('categoryModal');
            const confirmModal = document.getElementById('confirmModal');
            if (event.target == modal) {
                closeModal();
            }
            if (event.target == confirmModal) {
                closeConfirmModal();
            }
        }

        // ESC key to close modal
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeModal();
                closeConfirmModal();
            }
        });
    </script>
</body>

</html>