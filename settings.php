<?php
require_once './config/db.php';
session_start();

$error = '';
$message = '';

$backup_dir = __DIR__ . '/config/backups';
if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0755, true);
}

$settings_file = __DIR__ . '/config/settings.json';
$settings = [
    'auto_backup' => false,
    'backup_interval' => 'daily',
    'retention_days' => 30,
    'max_backups' => 10,
];
if (file_exists($settings_file)) {
    $saved = json_decode(file_get_contents($settings_file), true);
    if ($saved) {
        $settings = array_merge($settings, $saved);
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_backup':
                try {
                    $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
                    $filepath = $backup_dir . '/' . $filename;

                    $host = $host ?? 'localhost';
                    $user = $user ?? 'root';
                    $password = $password ?? '';
                    $dbname = $dbname ?? '';

                    $cmd = sprintf(
                        'mysqldump --host=%s --user=%s --password=%s --routines --events --triggers --add-drop-table %s 2>&1',
                        escapeshellarg($host),
                        escapeshellarg($user),
                        escapeshellarg($password),
                        escapeshellarg($dbname)
                    );

                    $output = [];
                    $return_var = 0;
                    exec($cmd . ' > ' . escapeshellarg($filepath), $output, $return_var);

                    if ($return_var === 0 && file_exists($filepath) && filesize($filepath) > 0) {
                        // Log the backup
                        $backup_info = [
                            'filename' => $filename,
                            'date' => date('Y-m-d H:i:s'),
                            'size' => filesize($filepath),
                            'type' => 'manual'
                        ];

                        $log_file = $backup_dir . '/backup_log.json';
                        $log = [];
                        if (file_exists($log_file)) {
                            $log = json_decode(file_get_contents($log_file), true) ?? [];
                        }
                        array_unshift($log, $backup_info);
                        file_put_contents($log_file, json_encode($log, JSON_PRETTY_PRINT));

                        $message = "Backup created successfully: $filename";
                    } else {
                        // Fallback: PHP-based export
                        $tables = [];
                        $result = $conn->query("SHOW TABLES");
                        while ($row = $result->fetch_row()) {
                            $tables[] = $row[0];
                        }

                        $sql = "-- Mailroom System Backup\n";
                        $sql .= "-- Date: " . date('Y-m-d H:i:s') . "\n";
                        $sql .= "-- Host: $host\n";
                        $sql .= "-- Database: $dbname\n\n";

                        foreach ($tables as $table) {
                            $drop = $conn->query("SHOW CREATE TABLE `$table`");
                            if ($drop) {
                                $row = $drop->fetch_row();
                                $sql .= "\n\n" . $row[1] . ";\n\n";
                            }

                            $data = $conn->query("SELECT * FROM `$table`");
                            while ($row = $data->fetch_assoc()) {
                                $cols = array_keys($row);
                                $vals = array_map(function ($v) use ($conn) {
                                    return $v === null ? 'NULL' : "'" . $conn->real_escape_string($v) . "'";
                                }, array_values($row));
                                $sql .= "INSERT INTO `$table` (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $vals) . ");\n";
                            }
                        }

                        file_put_contents($filepath, $sql);

                        if (file_exists($filepath) && filesize($filepath) > 0) {
                            $backup_info = [
                                'filename' => $filename,
                                'date' => date('Y-m-d H:i:s'),
                                'size' => filesize($filepath),
                                'type' => 'manual'
                            ];
                            $log_file = $backup_dir . '/backup_log.json';
                            $log = [];
                            if (file_exists($log_file)) {
                                $log = json_decode(file_get_contents($log_file), true) ?? [];
                            }
                            array_unshift($log, $backup_info);
                            file_put_contents($log_file, json_encode($log, JSON_PRETTY_PRINT));
                            $message = "Backup created successfully (PHP fallback): $filename";
                        } else {
                            $error = "Failed to create backup. Check directory permissions.";
                        }
                    }
                } catch (Exception $e) {
                    $error = "Backup failed: " . $e->getMessage();
                }
                break;

            case 'delete_backup':
                $filename = basename($_POST['filename'] ?? '');
                $filepath = $backup_dir . '/' . $filename;
                if (file_exists($filepath) && strpos($filename, 'backup_') === 0) {
                    unlink($filepath);
                    $log_file = $backup_dir . '/backup_log.json';
                    if (file_exists($log_file)) {
                        $log = json_decode(file_get_contents($log_file), true) ?? [];
                        $log = array_filter($log, function ($b) use ($filename) {
                            return $b['filename'] !== $filename;
                        });
                        file_put_contents($log_file, json_encode(array_values($log), JSON_PRETTY_PRINT));
                    }
                    $message = "Backup deleted: $filename";
                } else {
                    $error = "Backup file not found.";
                }
                break;

            case 'restore_backup':
                $filename = basename($_POST['filename'] ?? '');
                $filepath = $backup_dir . '/' . $filename;
                if (file_exists($filepath) && strpos($filename, 'backup_') === 0) {
                    $host = $host ?? 'localhost';
                    $user = $user ?? 'root';
                    $password = $password ?? '';
                    $dbname = $dbname ?? '';

                    $cmd = sprintf(
                        'mysql --host=%s --user=%s --password=%s %s < %s 2>&1',
                        escapeshellarg($host),
                        escapeshellarg($user),
                        escapeshellarg($password),
                        escapeshellarg($dbname),
                        escapeshellarg($filepath)
                    );

                    $output = [];
                    $return_var = 0;
                    exec($cmd, $output, $return_var);

                    if ($return_var === 0) {
                        $message = "Database restored successfully from: $filename";
                    } else {
                        $error = "Restore failed: " . implode("\n", $output);
                    }
                } else {
                    $error = "Backup file not found.";
                }
                break;

            case 'save_settings':
                $settings['auto_backup'] = isset($_POST['auto_backup']);
                $settings['backup_interval'] = $_POST['backup_interval'] ?? 'daily';
                $settings['retention_days'] = (int)($_POST['retention_days'] ?? 30);
                $settings['max_backups'] = (int)($_POST['max_backups'] ?? 10);
                file_put_contents($settings_file, json_encode($settings, JSON_PRETTY_PRINT));
                $message = "Settings saved successfully.";
                break;
        }
    }
}

// Load backup log
$backup_log_file = $backup_dir . '/backup_log.json';
$backups = [];
if (file_exists($backup_log_file)) {
    $backups = json_decode(file_get_contents($backup_log_file), true) ?? [];
}

// Enrich with actual file info
foreach ($backups as &$b) {
    $fp = $backup_dir . '/' . $b['filename'];
    if (file_exists($fp)) {
        $b['size'] = filesize($fp);
        $b['exists'] = true;
    } else {
        $b['exists'] = false;
    }
}
unset($b);

// Calculate stats
$total_backup_size = 0;
$latest_backup = null;
foreach ($backups as $b) {
    if ($b['exists']) {
        $total_backup_size += $b['size'];
        if (!$latest_backup || $b['date'] > $latest_backup['date']) {
            $latest_backup = $b;
        }
    }
}

function formatBytes($bytes, $precision = 2)
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings & Backup - Mailroom</title>
    <link rel="icon" type="image/png" href="./images/logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body class="bg-[#f5f5f4] text-[#1e1e1e]">
    <div class="flex">
        <?php include 'sidebar.php'; ?>

        <main class="flex-1 lg:ml-[var(--sidebar-width)] min-h-screen bg-[#f5f5f4]">
            <div class="px-4 py-4 lg:px-8 lg:py-6 border-b border-[#e5e5e5] bg-white">
                <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 class="text-2xl font-medium text-[#1e1e1e]">Settings & Backup</h1>
                        <p class="mt-1 text-sm text-[#6e6e6e]">Manage system settings and database backups</p>
                    </div>
                </div>
            </div>

            <div class="p-4 lg:p-8">
                <?php if ($message): ?>
                    <div class="mb-6 rounded-[28px] bg-[#e8f5e9] px-5 py-4 text-[#2e7d32]">
                        <i class="fa-regular fa-circle-check mr-2"></i>
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="mb-6 rounded-[28px] bg-[#ffdad6] px-5 py-4 text-[#93000a]">
                        <i class="fa-regular fa-circle-exclamation mr-2"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                    <div class="stat-box">
                        <div class="stat-label">Total Backups</div>
                        <div class="stat-value"><?php echo count(array_filter($backups, fn($b) => $b['exists'])); ?></div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-label">Total Size</div>
                        <div class="stat-value"><?php echo formatBytes($total_backup_size); ?></div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-label">Latest Backup</div>
                        <div class="text-lg font-medium text-[#1e1e1e]">
                            <?php echo $latest_backup ? date('M j, Y g:i A', strtotime($latest_backup['date'])) : 'No backups'; ?>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Backup Actions -->
                    <div class="panel circular-panel">
                        <div class="panel-header">
                            <h2 class="text-lg font-semibold text-[#1e1e1e]">Backup Actions</h2>
                        </div>
                        <div class="panel-body">
                            <form method="POST" class="space-y-4">
                                <input type="hidden" name="action" value="create_backup">
                                <p class="text-sm text-[#6e6e6e] mb-4">
                                    Create a complete backup of the database including all tables, routines, and events.
                                </p>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fa-solid fa-database"></i>
                                    Create New Backup
                                </button>
                            </form>

                            <hr class="my-6 border-[#e5e5e5]">

                            <h3 class="text-sm font-medium text-[#1e1e1e] mb-3">Quick Info</h3>
                            <ul class="space-y-2 text-sm text-[#6e6e6e]">
                                <li><i class="fa-solid fa-circle-info mr-2"></i> Backups include all tables, views, routines, and triggers</li>
                                <li><i class="fa-solid fa-circle-info mr-2"></i> Files are stored in <code class="text-[#1e1e1e]">config/backups/</code></li>
                                <li><i class="fa-solid fa-circle-info mr-2"></i> Use restore with caution — it will overwrite existing data</li>
                            </ul>
                        </div>
                    </div>

                    <!-- Backup Settings -->
                    <div class="panel circular-panel">
                        <div class="panel-header">
                            <h2 class="text-lg font-semibold text-[#1e1e1e]">Backup Settings</h2>
                        </div>
                        <div class="panel-body">
                            <form method="POST">
                                <input type="hidden" name="action" value="save_settings">
                                <div class="space-y-5">
                                    <label class="flex items-center gap-3 cursor-pointer">
                                        <input type="checkbox" name="auto_backup" value="1" <?php echo $settings['auto_backup'] ? 'checked' : ''; ?> class="w-4 h-4 rounded border-[#e5e5e5]">
                                        <span class="text-sm text-[#1e1e1e]">Enable automatic backups</span>
                                    </label>

                                    <div>
                                        <label class="block text-sm text-[#6e6e6e] mb-1">Backup Interval</label>
                                        <select name="backup_interval" class="w-full">
                                            <option value="hourly" <?php echo $settings['backup_interval'] === 'hourly' ? 'selected' : ''; ?>>Every Hour</option>
                                            <option value="daily" <?php echo $settings['backup_interval'] === 'daily' ? 'selected' : ''; ?>>Daily</option>
                                            <option value="weekly" <?php echo $settings['backup_interval'] === 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                                            <option value="monthly" <?php echo $settings['backup_interval'] === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                                        </select>
                                    </div>

                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm text-[#6e6e6e] mb-1">Retention (days)</label>
                                            <input type="number" name="retention_days" value="<?php echo $settings['retention_days']; ?>" min="1" max="365" class="w-full">
                                        </div>
                                        <div>
                                            <label class="block text-sm text-[#6e6e6e] mb-1">Max Backups</label>
                                            <input type="number" name="max_backups" value="<?php echo $settings['max_backups']; ?>" min="1" max="100" class="w-full">
                                        </div>
                                    </div>

                                    <button type="submit" class="btn btn-primary">
                                        <i class="fa-regular fa-floppy-disk"></i>
                                        Save Settings
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Backup History -->
                <div class="panel circular-panel mt-6">
                    <div class="panel-header flex items-center justify-between">
                        <h2 class="text-lg font-semibold text-[#1e1e1e]">Backup History</h2>
                        <span class="text-sm text-[#6e6e6e]"><?php echo count($backups); ?> total backups</span>
                    </div>
                    <div class="overflow-x-auto circular-table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Filename</th>
                                    <th>Date</th>
                                    <th>Size</th>
                                    <th>Type</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($backups)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-[#6e6e6e] py-8">No backups yet. Create your first backup above.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($backups as $b): ?>
                                        <tr class="<?php echo !$b['exists'] ? 'opacity-50' : ''; ?>">
                                            <td class="font-medium text-[#1e1e1e]">
                                                <i class="fa-regular fa-file-lines mr-2 text-[#6e6e6e]"></i>
                                                <?php echo htmlspecialchars($b['filename']); ?>
                                            </td>
                                            <td class="text-[#6e6e6e]"><?php echo date('M j, Y g:i A', strtotime($b['date'])); ?></td>
                                            <td class="text-[#6e6e6e]"><?php echo $b['exists'] ? formatBytes($b['size']) : 'File missing'; ?></td>
                                            <td>
                                                <span class="status-badge <?php echo $b['type'] === 'auto' ? 'status-pending' : 'status-picked'; ?>">
                                                    <?php echo ucfirst($b['type']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="flex items-center gap-2">
                                                    <?php if ($b['exists']): ?>
                                                        <a href="config/backups/<?php echo urlencode($b['filename']); ?>" download class="btn btn-sm btn-xs">
                                                            <i class="fa-solid fa-download"></i>
                                                        </a>
                                                        <form method="POST" class="inline" onsubmit="return confirm('Restore this backup? This will overwrite all existing data.');">
                                                            <input type="hidden" name="action" value="restore_backup">
                                                            <input type="hidden" name="filename" value="<?php echo htmlspecialchars($b['filename']); ?>">
                                                            <button type="submit" class="btn btn-sm btn-xs">
                                                                <i class="fa-solid fa-rotate-left"></i>
                                                            </button>
                                                        </form>
                                                        <form method="POST" class="inline" onsubmit="return confirm('Delete this backup?');">
                                                            <input type="hidden" name="action" value="delete_backup">
                                                            <input type="hidden" name="filename" value="<?php echo htmlspecialchars($b['filename']); ?>">
                                                            <button type="submit" class="btn btn-sm" style="color:#93000a">
                                                                <i class="fa-regular fa-trash-can"></i>
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="text-xs text-[#93000a]">File missing</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Database Connection Info -->
                <div class="panel circular-panel mt-6">
                    <div class="panel-header">
                        <h2 class="text-lg font-semibold text-[#1e1e1e]">Database Connection</h2>
                    </div>
                    <div class="panel-body">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                            <div>
                                <span class="text-[#6e6e6e]">Host:</span>
                                <span class="ml-2 text-[#1e1e1e] font-medium"><?php echo htmlspecialchars($host); ?></span>
                            </div>
                            <div>
                                <span class="text-[#6e6e6e]">Database:</span>
                                <span class="ml-2 text-[#1e1e1e] font-medium"><?php echo htmlspecialchars($dbname); ?></span>
                            </div>
                            <div>
                                <span class="text-[#6e6e6e]">User:</span>
                                <span class="ml-2 text-[#1e1e1e] font-medium"><?php echo htmlspecialchars($user); ?></span>
                            </div>
                            <div>
                                <span class="text-[#6e6e6e]">Backup Directory:</span>
                                <span class="ml-2 text-[#1e1e1e] font-medium">config/backups/</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>

</html>