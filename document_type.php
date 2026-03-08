<?php
// document_types.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session for messages
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include database configuration
require_once 'config/db.php';

// Create connection with error handling
function getConnection()
{
    global $conn;

    // Check if connection exists from db.php
    if (!isset($conn) || $conn->connect_error) {
        error_log("Database connection not available");
        return null;
    }

    return $conn;
}

// Set flash message
function setFlashMessage($type, $message)
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

// Get all document types
function getAllDocumentTypes()
{
    $conn = getConnection();
    if (!$conn) {
        return [];
    }

    $sql = "SELECT dt.*, COUNT(d.id) as document_count 
            FROM document_types dt
            LEFT JOIN documents d ON dt.id = d.type_id
            GROUP BY dt.id
            ORDER BY dt.type_name";
    $result = $conn->query($sql);

    if (!$result) {
        error_log("Error in getAllDocumentTypes: " . $conn->error);
        return [];
    }

    $types = [];
    while ($row = $result->fetch_assoc()) {
        $types[] = $row;
    }
    return $types;
}

// Get single document type by ID
function getDocumentTypeById($id)
{
    $conn = getConnection();
    if (!$conn) {
        return null;
    }

    $stmt = $conn->prepare("SELECT * FROM document_types WHERE id = ?");
    if (!$stmt) {
        error_log("Error preparing statement: " . $conn->error);
        return null;
    }

    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $type = $result->fetch_assoc();
    $stmt->close();

    return $type;
}

// Create new document type
function createDocumentType($type_name, $description = '')
{
    $conn = getConnection();
    if (!$conn) {
        return ['success' => false, 'message' => 'Database connection error'];
    }

    // Check if type already exists
    $check = $conn->prepare("SELECT id FROM document_types WHERE type_name = ?");
    if (!$check) {
        return ['success' => false, 'message' => 'Error preparing statement'];
    }

    $check->bind_param("s", $type_name);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $check->close();
        return ['success' => false, 'message' => 'Document type already exists!'];
    }
    $check->close();

    // Insert new type
    $stmt = $conn->prepare("INSERT INTO document_types (type_name, description) VALUES (?, ?)");
    if (!$stmt) {
        return ['success' => false, 'message' => 'Error preparing insert statement'];
    }

    $stmt->bind_param("ss", $type_name, $description);

    if ($stmt->execute()) {
        $stmt->close();
        return ['success' => true, 'message' => 'Document type created successfully!'];
    } else {
        $error = $stmt->error;
        $stmt->close();
        return ['success' => false, 'message' => 'Error: ' . $error];
    }
}

// Update document type
function updateDocumentType($id, $type_name, $description = '')
{
    $conn = getConnection();
    if (!$conn) {
        return ['success' => false, 'message' => 'Database connection error'];
    }

    // Check if type name already exists (excluding current ID)
    $check = $conn->prepare("SELECT id FROM document_types WHERE type_name = ? AND id != ?");
    if (!$check) {
        return ['success' => false, 'message' => 'Error preparing check statement'];
    }

    $check->bind_param("si", $type_name, $id);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $check->close();
        return ['success' => false, 'message' => 'Document type name already exists!'];
    }
    $check->close();

    // Update type
    $stmt = $conn->prepare("UPDATE document_types SET type_name = ?, description = ? WHERE id = ?");
    if (!$stmt) {
        return ['success' => false, 'message' => 'Error preparing update statement'];
    }

    $stmt->bind_param("ssi", $type_name, $description, $id);

    if ($stmt->execute()) {
        $stmt->close();
        return ['success' => true, 'message' => 'Document type updated successfully!'];
    } else {
        $error = $stmt->error;
        $stmt->close();
        return ['success' => false, 'message' => 'Error: ' . $error];
    }
}

// Delete document type
function deleteDocumentType($id)
{
    $conn = getConnection();
    if (!$conn) {
        return ['success' => false, 'message' => 'Database connection error'];
    }

    // Check if type is being used in documents
    $check = $conn->prepare("SELECT COUNT(*) as count FROM documents WHERE type_id = ?");
    if (!$check) {
        return ['success' => false, 'message' => 'Error preparing check statement'];
    }

    $check->bind_param("i", $id);
    $check->execute();
    $result = $check->get_result();
    $row = $result->fetch_assoc();
    $check->close();

    if ($row['count'] > 0) {
        return ['success' => false, 'message' => 'Cannot delete: This document type is used by ' . $row['count'] . ' document(s)'];
    }

    // Delete type
    $stmt = $conn->prepare("DELETE FROM document_types WHERE id = ?");
    if (!$stmt) {
        return ['success' => false, 'message' => 'Error preparing delete statement'];
    }

    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        $stmt->close();
        return ['success' => true, 'message' => 'Document type deleted successfully!'];
    } else {
        $error = $stmt->error;
        $stmt->close();
        return ['success' => false, 'message' => 'Error: ' . $error];
    }
}

// Get documents by type
function getDocumentsByType($type_id)
{
    $conn = getConnection();
    if (!$conn) {
        return [];
    }

    $stmt = $conn->prepare("SELECT * FROM documents WHERE type_id = ? ORDER BY date_received DESC");
    if (!$stmt) {
        error_log("Error preparing statement: " . $conn->error);
        return [];
    }

    $stmt->bind_param("i", $type_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $documents = [];
    while ($row = $result->fetch_assoc()) {
        $documents[] = $row;
    }
    $stmt->close();

    return $documents;
}

// Handle form submissions
$action = isset($_GET['action']) ? $_GET['action'] : 'list';

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create'])) {
        $type_name = trim($_POST['type_name']);
        $description = trim($_POST['description']);

        if (empty($type_name)) {
            setFlashMessage('danger', 'Document type name is required!');
        } else {
            $result = createDocumentType($type_name, $description);
            setFlashMessage($result['success'] ? 'success' : 'danger', $result['message']);
            if ($result['success']) {
                header('Location: document_types.php?action=list');
                exit();
            }
        }
    } elseif (isset($_POST['update'])) {
        $id = $_POST['id'];
        $type_name = trim($_POST['type_name']);
        $description = trim($_POST['description']);

        if (empty($type_name)) {
            setFlashMessage('danger', 'Document type name is required!');
        } else {
            $result = updateDocumentType($id, $type_name, $description);
            setFlashMessage($result['success'] ? 'success' : 'danger', $result['message']);
            if ($result['success']) {
                header('Location: document_types.php?action=list');
                exit();
            }
        }
    }
}

// Handle GET requests for delete
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $result = deleteDocumentType($_GET['id']);
    setFlashMessage($result['success'] ? 'success' : 'danger', $result['message']);
    header('Location: document_types.php?action=list');
    exit();
}

// Get flash message
$flashMessage = '';
$flashType = '';
if (isset($_SESSION['flash'])) {
    $flashMessage = $_SESSION['flash']['message'];
    $flashType = $_SESSION['flash']['type'];
    unset($_SESSION['flash']);
}

// Include sidebar
include './sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Types - Mailroom</title>
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
    </style>
</head>

<body class="bg-[#f5f5f4]">
    <main class="ml-60 min-h-screen">
        <!-- Header -->
        <div class="px-8 py-6 border-b border-[#e5e5e5] bg-white">
            <div class="flex justify-between items-center">
                <h1 class="text-2xl font-medium text-[#1e1e1e]">Document Types</h1>
                <div class="flex gap-2">
                    <a href="?action=list" class="px-3 py-1.5 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e] <?php echo $action == 'list' ? 'bg-[#f0f0f0]' : ''; ?>">
                        <i class="fa-regular fa-list mr-1 text-[#6e6e6e]"></i>List
                    </a>
                    <a href="?action=create" class="px-3 py-1.5 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e] <?php echo $action == 'create' ? 'bg-[#f0f0f0]' : ''; ?>">
                        <i class="fa-regular fa-plus mr-1 text-[#6e6e6e]"></i>Create
                    </a>
                    <a href="?action=stats" class="px-3 py-1.5 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e] <?php echo $action == 'stats' ? 'bg-[#f0f0f0]' : ''; ?>">
                        <i class="fa-regular fa-chart-bar mr-1 text-[#6e6e6e]"></i>Stats
                    </a>
                </div>
            </div>
        </div>

        <div class="p-8">
            <!-- Flash Message -->
            <?php if ($flashMessage): ?>
                <div class="mb-6 p-3 border border-[#e5e5e5] bg-white rounded-md text-sm text-[#1e1e1e]">
                    <i class="fa-regular <?php echo $flashType == 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> mr-2 text-[#4a4a4a]"></i>
                    <?php echo htmlspecialchars($flashMessage); ?>
                </div>
            <?php endif; ?>

            <?php
            // Check database connection
            $conn_check = getConnection();
            if (!$conn_check):
            ?>
                <div class="mb-6 p-3 border border-[#e5e5e5] bg-white rounded-md text-sm text-[#1e1e1e]">
                    <i class="fa-regular fa-circle-exclamation mr-2 text-[#4a4a4a]"></i>
                    Database connection error. Please check your configuration.
                </div>
            <?php
            endif;

            // List all document types
            if ($action == 'list'):
                $types = getAllDocumentTypes();
            ?>
                <!-- Search -->
                <div class="mb-4">
                    <input type="text" id="searchInput" placeholder="Search document types..."
                        class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]">
                </div>

                <!-- Table -->
                <div class="bg-white border border-[#e5e5e5] rounded-md overflow-hidden">
                    <table id="typesTable">
                        <thead>
                            <tr class="bg-[#fafafa]">
                                <th class="text-xs">ID</th>
                                <th class="text-xs">Type Name</th>
                                <th class="text-xs">Description</th>
                                <th class="text-xs">Documents</th>
                                <th class="text-xs">Created</th>
                                <th class="text-xs">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($types)): ?>
                                <tr>
                                    <td colspan="6" class="text-sm text-[#6e6e6e] text-center py-8">
                                        No document types found.
                                        <a href="?action=create" class="text-[#1e1e1e] underline">Create one</a>.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($types as $type): ?>
                                    <tr class="hover:bg-[#fafafa]">
                                        <td class="text-sm text-[#6e6e6e]"><?php echo $type['id']; ?></td>
                                        <td class="text-sm font-medium text-[#1e1e1e]"><?php echo htmlspecialchars($type['type_name']); ?></td>
                                        <td class="text-sm text-[#1e1e1e]"><?php echo htmlspecialchars($type['description'] ?? '-'); ?></td>
                                        <td class="text-sm text-[#1e1e1e]"><?php echo $type['document_count']; ?></td>
                                        <td class="text-sm text-[#1e1e1e]"><?php echo date('M j, Y', strtotime($type['created_at'])); ?></td>
                                        <td class="text-sm">
                                            <div class="flex gap-2">
                                                <a href="?action=view&id=<?php echo $type['id']; ?>" class="text-[#9e9e9e] hover:text-[#1e1e1e]">
                                                    <i class="fa-regular fa-eye"></i>
                                                </a>
                                                <a href="?action=edit&id=<?php echo $type['id']; ?>" class="text-[#9e9e9e] hover:text-[#1e1e1e]">
                                                    <i class="fa-regular fa-pen-to-square"></i>
                                                </a>
                                                <button onclick="confirmDelete(<?php echo $type['id']; ?>, '<?php echo htmlspecialchars($type['type_name']); ?>')" class="text-[#9e9e9e] hover:text-[#1e1e1e]">
                                                    <i class="fa-regular fa-trash-can"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            <?php
            // Create new document type
            elseif ($action == 'create'):
            ?>
                <div class="max-w-lg">
                    <div class="bg-white border border-[#e5e5e5] rounded-md p-6">
                        <h2 class="text-base font-medium text-[#1e1e1e] mb-4">Create New Document Type</h2>

                        <form method="POST">
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Type Name *</label>
                                    <input type="text" name="type_name" required
                                        placeholder="e.g., Legislative Documents"
                                        class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]">
                                </div>

                                <div>
                                    <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Description</label>
                                    <textarea name="description" rows="4"
                                        placeholder="Optional description"
                                        class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]"></textarea>
                                </div>
                            </div>

                            <div class="flex gap-3 mt-6">
                                <button type="submit" name="create" class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                                    <i class="fa-regular fa-floppy-disk mr-1 text-[#6e6e6e]"></i>
                                    Create
                                </button>
                                <a href="?action=list" class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                                    Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

            <?php
            // Edit document type
            elseif ($action == 'edit' && isset($_GET['id'])):
                $type = getDocumentTypeById($_GET['id']);
                if (!$type):
                    setFlashMessage('danger', 'Document type not found!');
                    header('Location: document_types.php?action=list');
                    exit();
                endif;
            ?>
                <div class="max-w-lg">
                    <div class="bg-white border border-[#e5e5e5] rounded-md p-6">
                        <h2 class="text-base font-medium text-[#1e1e1e] mb-4">Edit Document Type</h2>

                        <form method="POST">
                            <input type="hidden" name="id" value="<?php echo $type['id']; ?>">

                            <div class="space-y-4">
                                <div>
                                    <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Type Name *</label>
                                    <input type="text" name="type_name" required
                                        value="<?php echo htmlspecialchars($type['type_name']); ?>"
                                        class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]">
                                </div>

                                <div>
                                    <label class="block text-xs text-[#6e6e6e] uppercase tracking-wide mb-1">Description</label>
                                    <textarea name="description" rows="4"
                                        class="w-full px-3 py-2 text-sm border border-[#e5e5e5] rounded-md focus:outline-none focus:border-[#9e9e9e]"><?php echo htmlspecialchars($type['description'] ?? ''); ?></textarea>
                                </div>
                            </div>

                            <div class="flex gap-3 mt-6">
                                <button type="submit" name="update" class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                                    <i class="fa-regular fa-floppy-disk mr-1 text-[#6e6e6e]"></i>
                                    Update
                                </button>
                                <a href="?action=list" class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                                    Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

            <?php
            // View single document type
            elseif ($action == 'view' && isset($_GET['id'])):
                $type = getDocumentTypeById($_GET['id']);
                if (!$type):
                    setFlashMessage('danger', 'Document type not found!');
                    header('Location: document_types.php?action=list');
                    exit();
                endif;

                $documents = getDocumentsByType($type['id']);
            ?>
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Type Details -->
                    <div class="lg:col-span-1">
                        <div class="bg-white border border-[#e5e5e5] rounded-md p-6">
                            <h2 class="text-base font-medium text-[#1e1e1e] mb-4">Type Details</h2>

                            <div class="space-y-3">
                                <div>
                                    <p class="text-xs text-[#6e6e6e] uppercase tracking-wide">ID</p>
                                    <p class="text-sm text-[#1e1e1e]"><?php echo $type['id']; ?></p>
                                </div>
                                <div>
                                    <p class="text-xs text-[#6e6e6e] uppercase tracking-wide">Name</p>
                                    <p class="text-sm font-medium text-[#1e1e1e]"><?php echo htmlspecialchars($type['type_name']); ?></p>
                                </div>
                                <div>
                                    <p class="text-xs text-[#6e6e6e] uppercase tracking-wide">Description</p>
                                    <p class="text-sm text-[#1e1e1e]"><?php echo htmlspecialchars($type['description'] ?? 'No description'); ?></p>
                                </div>
                                <div>
                                    <p class="text-xs text-[#6e6e6e] uppercase tracking-wide">Created</p>
                                    <p class="text-sm text-[#1e1e1e]"><?php echo date('M j, Y g:i a', strtotime($type['created_at'])); ?></p>
                                </div>
                                <div>
                                    <p class="text-xs text-[#6e6e6e] uppercase tracking-wide">Documents</p>
                                    <p class="text-sm text-[#1e1e1e]"><?php echo count($documents); ?></p>
                                </div>
                            </div>

                            <div class="flex gap-2 mt-6">
                                <a href="?action=edit&id=<?php echo $type['id']; ?>" class="px-3 py-1.5 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                                    <i class="fa-regular fa-pen-to-square mr-1"></i>Edit
                                </a>
                                <button onclick="confirmDelete(<?php echo $type['id']; ?>, '<?php echo htmlspecialchars($type['type_name']); ?>')" class="px-3 py-1.5 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                                    <i class="fa-regular fa-trash-can mr-1"></i>Delete
                                </button>
                                <a href="?action=list" class="px-3 py-1.5 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                                    <i class="fa-regular fa-arrow-left mr-1"></i>Back
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Documents List -->
                    <div class="lg:col-span-2">
                        <div class="bg-white border border-[#e5e5e5] rounded-md p-6">
                            <h2 class="text-base font-medium text-[#1e1e1e] mb-4">Documents of this Type</h2>

                            <?php if (empty($documents)): ?>
                                <p class="text-sm text-[#6e6e6e] text-center py-8">No documents of this type.</p>
                            <?php else: ?>
                                <div class="space-y-2">
                                    <?php foreach ($documents as $doc): ?>
                                        <div class="p-3 border border-[#e5e5e5] rounded-md hover:bg-[#fafafa]">
                                            <div class="flex justify-between items-start">
                                                <div>
                                                    <p class="text-sm font-medium text-[#1e1e1e]"><?php echo htmlspecialchars($doc['document_name']); ?></p>
                                                    <p class="text-xs text-[#6e6e6e] mt-1">Origin: <?php echo htmlspecialchars($doc['origin']); ?> • Copies: <?php echo $doc['copies_received']; ?></p>
                                                </div>
                                                <p class="text-xs text-[#6e6e6e]"><?php echo date('M j, Y', strtotime($doc['date_received'])); ?></p>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            <?php
            // Statistics
            elseif ($action == 'stats'):
                $types = getAllDocumentTypes();
                $totalTypes = count($types);
                $totalDocuments = 0;
                $usedTypes = 0;
                $unusedTypes = 0;

                foreach ($types as $type) {
                    $totalDocuments += $type['document_count'];
                    if ($type['document_count'] > 0) {
                        $usedTypes++;
                    } else {
                        $unusedTypes++;
                    }
                }

                $avgPerType = $totalTypes > 0 ? round($totalDocuments / $totalTypes, 1) : 0;
            ?>
                <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-4 mb-6">
                    <div class="bg-white border border-[#e5e5e5] rounded-md p-4">
                        <p class="text-xs text-[#6e6e6e] uppercase tracking-wide">Total Types</p>
                        <p class="text-2xl font-medium text-[#1e1e1e] mt-1"><?php echo $totalTypes; ?></p>
                    </div>
                    <div class="bg-white border border-[#e5e5e5] rounded-md p-4">
                        <p class="text-xs text-[#6e6e6e] uppercase tracking-wide">Total Documents</p>
                        <p class="text-2xl font-medium text-[#1e1e1e] mt-1"><?php echo $totalDocuments; ?></p>
                    </div>
                    <div class="bg-white border border-[#e5e5e5] rounded-md p-4">
                        <p class="text-xs text-[#6e6e6e] uppercase tracking-wide">Avg per Type</p>
                        <p class="text-2xl font-medium text-[#1e1e1e] mt-1"><?php echo $avgPerType; ?></p>
                    </div>
                    <div class="bg-white border border-[#e5e5e5] rounded-md p-4">
                        <p class="text-xs text-[#6e6e6e] uppercase tracking-wide">Types in Use</p>
                        <p class="text-2xl font-medium text-[#1e1e1e] mt-1"><?php echo $usedTypes; ?></p>
                    </div>
                    <div class="bg-white border border-[#e5e5e5] rounded-md p-4">
                        <p class="text-xs text-[#6e6e6e] uppercase tracking-wide">Unused Types</p>
                        <p class="text-2xl font-medium text-[#1e1e1e] mt-1"><?php echo $unusedTypes; ?></p>
                    </div>
                </div>

                <div class="bg-white border border-[#e5e5e5] rounded-md overflow-hidden">
                    <table>
                        <thead>
                            <tr class="bg-[#fafafa]">
                                <th class="text-xs">Type Name</th>
                                <th class="text-xs">Document Count</th>
                                <th class="text-xs">Percentage</th>
                                <th class="text-xs">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($types as $type): ?>
                                <tr class="hover:bg-[#fafafa]">
                                    <td class="text-sm text-[#1e1e1e]"><?php echo htmlspecialchars($type['type_name']); ?></td>
                                    <td class="text-sm text-[#1e1e1e]"><?php echo $type['document_count']; ?></td>
                                    <td class="text-sm text-[#1e1e1e]">
                                        <?php
                                        $percentage = $totalDocuments > 0 ? round(($type['document_count'] / $totalDocuments) * 100, 1) : 0;
                                        echo $percentage . '%';
                                        ?>
                                    </td>
                                    <td class="text-sm">
                                        <?php if ($type['document_count'] > 0): ?>
                                            <span class="text-[#1e1e1e]">In Use</span>
                                        <?php else: ?>
                                            <span class="text-[#9e9e9e]">Unused</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="fixed inset-0 bg-[#000000] bg-opacity-20 hidden items-center justify-center z-50" style="display: none;">
        <div class="bg-white border border-[#e5e5e5] rounded-md w-full max-w-md p-5">
            <h3 class="text-base font-medium text-[#1e1e1e] mb-2">Confirm Delete</h3>
            <p class="text-sm text-[#6e6e6e] mb-4">Are you sure you want to delete <span id="deleteTypeName" class="font-medium text-[#1e1e1e]"></span>?</p>
            <p class="text-xs text-[#9e9e9e] mb-6">This action cannot be undone.</p>

            <div class="flex justify-end gap-2">
                <button onclick="closeModal()" class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                    Cancel
                </button>
                <a href="#" id="confirmDeleteBtn" class="px-4 py-2 text-sm border border-[#e5e5e5] rounded-md bg-white hover:bg-[#f5f5f4] text-[#1e1e1e]">
                    Delete
                </a>
            </div>
        </div>
    </div>

    <script>
        // Delete confirmation
        function confirmDelete(id, name) {
            document.getElementById('deleteTypeName').textContent = name;
            document.getElementById('confirmDeleteBtn').href = '?action=delete&id=' + id;
            document.getElementById('deleteModal').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('deleteModal');
            if (event.target == modal) {
                closeModal();
            }
        }

        // ESC key to close modal
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeModal();
            }
        });

        // Search functionality
        document.getElementById('searchInput')?.addEventListener('keyup', function() {
            const searchText = this.value.toLowerCase();
            const rows = document.querySelectorAll('#typesTable tbody tr');

            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchText) ? '' : 'none';
            });
        });
    </script>
</body>

</html>