<?php
require_once './config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/audit.php';
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
    csrf_check_post();
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
                        audit_log('backup', 'backup', null, "Created database backup: $filename (" . formatBytes(filesize($filepath)) . ")", 'System');
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
                            audit_log('backup', 'backup', null, "Created database backup: $filename (" . formatBytes(filesize($filepath)) . ")", 'System');
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
                    audit_log('delete', 'backup', null, "Deleted backup: $filename", 'System');
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
                        audit_log('restore', 'backup', null, "Restored database from backup: $filename", 'System');
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

/**
 * Format a byte count into a human readable string.
 *
 * @param int $bytes Size in bytes.
 * @param int $precision Decimal places to keep.
 * @return string
 */
function formatBytes(int $bytes, int $precision = 2)
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
    <title>Settings & Backup - Mailroom Ops</title>
    <link rel="icon" type="image/png" href="./images/logo.png">
    <meta name="csrf-token" content="<?php echo csrf_token(); ?>">
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
                        <a href="index.php">System</a>
                        <span class="sep">/</span>
                        <span>Settings &amp; Backup</span>
                    </div>
                    <h1 class="page-header-title">Settings &amp; Backup</h1>
                    <p class="page-header-subtitle">Manage system settings and database backups.</p>
                </div>
            </div>

            <div class="page-body">
                <?php if ($message): ?>
                    <div class="alert alert-green" style="margin-bottom:20px;">
                        <i class="fa-regular fa-circle-check"></i>
                        <span><?php echo htmlspecialchars($message); ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-red" style="margin-bottom:20px;">
                        <i class="fa-regular fa-circle-exclamation"></i>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>

                <!-- Settings tabs -->
                <div class="tabs no-print" id="settingsTabs">
                    <button type="button" class="tab-button" data-setting-tab="appearance"><i class="fa-solid fa-palette"></i> Appearance</button>
                    <button type="button" class="tab-button active" data-setting-tab="backup"><i class="fa-solid fa-database"></i> Backup</button>
                    <button type="button" class="tab-button" data-setting-tab="system"><i class="fa-solid fa-server"></i> System</button>
                </div>

                <div id="settingsBackup" class="">
                <div class="stat-grid" style="margin-bottom:20px;">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fa-solid fa-database"></i></div>
                        <div class="stat-label">Total Backups</div>
                        <div class="stat-value"><?php echo count(array_filter($backups, fn($b) => $b['exists'])); ?></div>
                        <div class="stat-hint">Backup files on disk</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon gold"><i class="fa-solid fa-hard-drive"></i></div>
                        <div class="stat-label">Total Size</div>
                        <div class="stat-value"><?php echo formatBytes($total_backup_size); ?></div>
                        <div class="stat-hint">Combined storage used</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fa-regular fa-clock"></i></div>
                        <div class="stat-label">Latest Backup</div>
                        <div class="stat-value" style="font-size:15px;line-height:1.35;">
                            <?php echo $latest_backup ? date('M j, Y g:i A', strtotime($latest_backup['date'])) : 'No backups'; ?>
                        </div>
                        <div class="stat-hint"><?php echo $latest_backup ? htmlspecialchars($latest_backup['filename']) : 'Create your first backup below'; ?></div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6" style="gap:20px;">
                    <!-- Backup Actions -->
                    <div class="card" style="margin:0;">
                        <div class="card-header">
                            <div>
                                <div class="card-title">Backup Actions</div>
                                <div class="card-subtitle">Create a full snapshot of the database.</div>
                            </div>
                        </div>
                        <div class="card-body" style="padding:20px;">
                            <form method="POST" id="createBackupForm">
                                <input type="hidden" name="action" value="create_backup">
                                <?php csrf_field(); ?>
                                <p class="card-subtitle" style="margin-bottom:16px;color:var(--text-faint);">
                                    Creates a complete backup of the database including all tables, routines, triggers, and events.
                                </p>
                                <div class="filter-chip" style="margin-bottom:18px;"><i class="fa-solid fa-database"></i> Files stored in <code>config/backups/</code></div>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fa-solid fa-database"></i>
                                    Create New Backup
                                </button>
                            </form>

                            <hr style="border:none;border-top:1px solid var(--border);margin:20px 0;">

                            <div class="card-subtitle" style="margin-bottom:10px;"><i class="fa-solid fa-circle-info" style="color:var(--gold);margin-right:6px;"></i> Quick Info</div>
                            <ul style="list-style:none;padding:0;margin:0;color:var(--text-faint);font-size:13px;line-height:1.9;">
                                <li><i class="fa-solid fa-angle-right" style="color:var(--gold);margin-right:8px;font-size:11px;"></i> Backups include all tables, views, routines, and triggers</li>
                                <li><i class="fa-solid fa-angle-right" style="color:var(--gold);margin-right:8px;font-size:11px;"></i> Files are stored in <code style="color:var(--text);">config/backups/</code></li>
                                <li><i class="fa-solid fa-angle-right" style="color:var(--gold);margin-right:8px;font-size:11px;"></i> Restore overwrites existing data — use with caution</li>
                            </ul>
                        </div>
                    </div>

                    <!-- Backup Settings -->
                    <div class="card" style="margin:0;">
                        <div class="card-header">
                            <div>
                                <div class="card-title">Backup Settings</div>
                                <div class="card-subtitle">Configure automatic backup behavior.</div>
                            </div>
                        </div>
                        <div class="card-body" style="padding:20px;">
                            <form method="POST">
                                <input type="hidden" name="action" value="save_settings">
                                <?php csrf_field(); ?>
                                <div class="form-field" style="margin-bottom:18px;">
                                    <label class="label" style="display:flex;align-items:center;gap:10px;cursor:pointer;color:var(--text);">
                                        <input type="checkbox" name="auto_backup" value="1" <?php echo $settings['auto_backup'] ? 'checked' : ''; ?> style="width:16px;height:16px;accent-color:var(--accent);">
                                        Enable automatic backups
                                    </label>
                                    <div class="card-subtitle" style="margin-top:4px;">Runs backups on the chosen interval.</div>
                                </div>

                                <div class="form-field" style="margin-bottom:18px;">
                                    <label class="label">Backup Interval</label>
                                    <select name="backup_interval" class="select">
                                        <option value="hourly" <?php echo $settings['backup_interval'] === 'hourly' ? 'selected' : ''; ?>>Every Hour</option>
                                        <option value="daily" <?php echo $settings['backup_interval'] === 'daily' ? 'selected' : ''; ?>>Daily</option>
                                        <option value="weekly" <?php echo $settings['backup_interval'] === 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                                        <option value="monthly" <?php echo $settings['backup_interval'] === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                                    </select>
                                </div>

                                <div class="form-grid" style="margin-bottom:20px;">
                                    <div class="form-field">
                                        <label class="label">Retention (days)</label>
                                        <input type="number" name="retention_days" value="<?php echo $settings['retention_days']; ?>" min="1" max="365" class="input">
                                    </div>
                                    <div class="form-field">
                                        <label class="label">Max Backups</label>
                                        <input type="number" name="max_backups" value="<?php echo $settings['max_backups']; ?>" min="1" max="100" class="input">
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary">
                                    <i class="fa-regular fa-floppy-disk"></i>
                                    Save Settings
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Backup History -->
                <div class="card" style="margin-top:20px;">
                    <div class="card-header" style="padding:14px 20px;">
                        <div>
                            <div class="card-title">Backup History</div>
                            <div class="card-subtitle">Download, restore, or remove saved backups.</div>
                        </div>
                        <span class="filter-chip"><i class="fa-solid fa-database"></i> <?php echo count($backups); ?> total</span>
                    </div>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Filename</th>
                                    <th class="hidden md:table-cell">Date</th>
                                    <th>Size</th>
                                    <th>Type</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($backups)): ?>
                                    <tr>
                                        <td colspan="5">
                                            <div class="empty-state">
                                                <div class="empty-state-icon"><i class="fa-solid fa-database"></i></div>
                                                <div class="empty-state-title">No backups yet</div>
                                                <div class="empty-state-text">Create your first backup with the button above.</div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($backups as $b): ?>
                                        <tr style="<?php echo !$b['exists'] ? 'opacity:.55;' : ''; ?>">
                                            <td>
                                                <span class="table-cell-title">
                                                    <i class="fa-regular fa-file-lines" style="color:var(--text-faint);margin-right:8px;"></i>
                                                    <?php echo htmlspecialchars($b['filename']); ?>
                                                </span>
                                            </td>
                                            <td class="hidden md:table-cell">
                                                <span class="table-cell-mono"><?php echo date('M j, Y g:i A', strtotime($b['date'])); ?></span>
                                            </td>
                                            <td>
                                                <span class="table-cell-mono"><?php echo $b['exists'] ? formatBytes($b['size']) : 'File missing'; ?></span>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $b['type'] === 'auto' ? 'badge-orange' : 'badge-green'; ?>">
                                                    <?php echo ucfirst($b['type']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="row-actions" style="justify-content:flex-end;">
                                                    <?php if ($b['exists']): ?>
                                                        <a href="config/backups/<?php echo urlencode($b['filename']); ?>" download class="icon-btn" title="Download">
                                                            <i class="fa-solid fa-download"></i>
                                                        </a>
                                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Restore this backup? This will overwrite all existing data.');">
                                                            <input type="hidden" name="action" value="restore_backup">
                                                            <input type="hidden" name="filename" value="<?php echo htmlspecialchars($b['filename']); ?>">
                                                            <?php csrf_field(); ?>
                                                            <button type="submit" class="icon-btn primary" title="Restore">
                                                                <i class="fa-solid fa-rotate-left"></i>
                                                            </button>
                                                        </form>
                                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this backup?');">
                                                            <input type="hidden" name="action" value="delete_backup">
                                                            <input type="hidden" name="filename" value="<?php echo htmlspecialchars($b['filename']); ?>">
                                                            <?php csrf_field(); ?>
                                                            <button type="submit" class="icon-btn danger" title="Delete">
                                                                <i class="fa-regular fa-trash-can"></i>
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="badge badge-red">File missing</span>
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

                </div><!-- /settingsBackup -->

                <!-- Appearance Pane -->
                <div id="settingsAppearance" class="hidden">
                <!-- Appearance & PWA -->
                <div class="card" style="margin-top:20px;">
                    <div class="card-header">
                        <div>
                            <div class="card-title">Appearance &amp; App</div>
                            <div class="card-subtitle">Display preferences and installable app options. Changes apply instantly on this browser.</div>
                        </div>
                    </div>
                    <div class="card-body" style="padding:20px;">
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:24px;">
                            <div>
                                <div class="label" style="margin-bottom:12px;">Interface Font</div>
                                <div id="msFontRow" style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
                                    <?php $ms_fonts = [
                                        ['system', 'Default', ''],
                                        ['serif', 'Serif', 'font-family:Georgia,serif;'],
                                        ['modern', 'Modern', 'font-family:Inter,"Segoe UI",Arial,sans-serif;'],
                                        ['mono', 'Mono', 'font-family:ui-monospace,Menlo,monospace;'],
                                    ]; ?>
                                    <?php foreach ($ms_fonts as $f): ?>
                                        <button type="button" class="mr-font-btn <?php echo $f[0]; ?>" data-ms-font="<?php echo $f[0]; ?>" style="<?php echo $f[2]; ?>"><?php echo $f[1]; ?></button>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div>
                                <div class="label" style="margin-bottom:12px;">Text Size</div>
                                <div id="msScaleRow" style="display:flex;align-items:center;gap:6px;">
                                    <?php $ms_scales = [['sm', 'A-', 'mr-size-minus'], ['md', 'A', ''], ['lg', 'A+', 'mr-size-plus'], ['xl', 'A++', 'mr-size-plus'], ['xxl', 'A+++', 'mr-size-plus']]; ?>
                                    <?php foreach ($ms_scales as $s): ?>
                                        <button type="button" class="mr-size-btn <?php echo $s[2]; ?>" data-ms-scale="<?php echo $s[0]; ?>" style="flex:1;"><?php echo $s[1]; ?></button>
                                    <?php endforeach; ?>
                                </div>
                                <p class="card-subtitle" style="margin-top:10px;">Scales the whole interface for readability.</p>
                            </div>

                            <div>
                                <div class="label" style="margin-bottom:12px;">Installable App</div>
                                <button type="button" id="msInstallBtn" class="mr-install hidden">
                                    <i class="fa-solid fa-download"></i>&nbsp;Install app
                                </button>
                                <p class="card-subtitle" style="margin-top:10px;">Add Mailroom Ops to your home screen / desktop for quick access with its own window.</p>
                            </div>
                        </div>

                        <hr style="border:none;border-top:1px solid var(--border);margin:18px 0;">

                        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                            <div>
                                <div class="label" style="margin-bottom:4px;">Preview</div>
                                <div id="msPreview" style="font-size:14px;color:var(--text);">
                                    <span style="font-weight:700;">Mailroom operations</span> — documents, newspapers &amp; parcels at a glance.
                                </div>
                            </div>
                            <button type="button" class="btn btn-soft" id="msResetBtn">
                                <i class="fa-solid fa-rotate-left"></i> Reset display settings
                            </button>
                        </div>
                    </div>
                </div>

                </div><!-- /settingsAppearance -->

                <!-- System Pane -->
                <div id="settingsSystem" class="hidden">
                <!-- Database Connection Info -->
                <div class="card" style="margin-top:20px;">
                    <div class="card-header">
                        <div>
                            <div class="card-title">Database Connection</div>
                            <div class="card-subtitle">Connection details used for backup and restore operations.</div>
                        </div>
                    </div>
                    <div class="card-body" style="padding:20px;">
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px;">
                            <div>
                                <div class="label">Host</div>
                                <div class="table-cell-title"><?php echo htmlspecialchars($host); ?></div>
                            </div>
                            <div>
                                <div class="label">Database</div>
                                <div class="table-cell-title"><?php echo htmlspecialchars($dbname); ?></div>
                            </div>
                            <div>
                                <div class="label">User</div>
                                <div class="table-cell-title"><?php echo htmlspecialchars($user); ?></div>
                            </div>
                            <div>
                                <div class="label">Backup Directory</div>
                                <div class="table-cell-title">config/backups/</div>
                            </div>
                        </div>
                    </div>
                </div>
                </div><!-- /settingsSystem -->
            </div>
        </main>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const PROPS = { font: 'mr_font_family', scale: 'mr_text_scale' };

            function current(key) {
                return localStorage.getItem(PROPS[key]) || (key === 'font' ? 'system' : 'md');
            }

            function applyToDoc() {
                document.documentElement.setAttribute('data-font', current('font'));
                if (current('scale') === 'md') {
                    document.documentElement.removeAttribute('data-text-scale');
                } else {
                    document.documentElement.setAttribute('data-text-scale', current('scale'));
                }
                syncActive();
            }

            function setPref(key, value) {
                localStorage.setItem(PROPS[key], value);
                applyToDoc();
                window.dispatchEvent(new CustomEvent('mr:prefs'));
            }

            function syncActive() {
                document.querySelectorAll('#msFontRow .mr-font-btn').forEach(function(b) {
                    b.classList.toggle('active', b.dataset.msFont === current('font'));
                });
                document.querySelectorAll('#msScaleRow .mr-size-btn').forEach(function(b) {
                    b.classList.toggle('active', b.dataset.msScale === current('scale'));
                });
            }

            document.querySelectorAll('#msFontRow .mr-font-btn').forEach(function(b) {
                b.addEventListener('click', function() { setPref('font', b.dataset.msFont); });
            });
            document.querySelectorAll('#msScaleRow .mr-size-btn').forEach(function(b) {
                b.addEventListener('click', function() { setPref('scale', b.dataset.msScale); });
            });

            document.getElementById('msResetBtn').addEventListener('click', function() {
                Object.values(PROPS).forEach(function(k) { localStorage.removeItem(k); });
                applyToDoc();
                window.dispatchEvent(new CustomEvent('mr:prefs'));
            });

            // Install app button (mirrors the floating widget)
            const msInstallBtn = document.getElementById('msInstallBtn');
            function updateInstall() {
                if (!msInstallBtn) return;
                if (window.MailroomPWA && window.MailroomPWA.deferredPrompt) {
                    msInstallBtn.classList.remove('hidden');
                } else {
                    msInstallBtn.classList.add('hidden');
                }
            }
            msInstallBtn.addEventListener('click', async function() {
                if (!window.MailroomPWA || !window.MailroomPWA.deferredPrompt) return;
                window.MailroomPWA.deferredPrompt.prompt();
                await window.MailroomPWA.deferredPrompt.userChoice;
                window.MailroomPWA.deferredPrompt = null;
                updateInstall();
            });
            window.addEventListener('mr:installable', updateInstall);
            window.addEventListener('mr:installed', updateInstall);

            // Settings tabs
            const settingTabs = Array.from(document.querySelectorAll('[data-setting-tab]'));
            const settingPanes = {
                appearance: document.getElementById('settingsAppearance'),
                backup: document.getElementById('settingsBackup'),
                system: document.getElementById('settingsSystem')
            };
            function setSettingTab(name) {
                settingTabs.forEach((t) => {
                    t.classList.toggle('active', t.dataset.settingTab === name);
                });
                Object.entries(settingPanes).forEach(([k, pane]) => {
                    if (pane) pane.classList.toggle('hidden', k !== name);
                });
                localStorage.setItem('mr_settings_tab', name);
            }
            settingTabs.forEach((t) => {
                t.addEventListener('click', function() { setSettingTab(t.dataset.settingTab); });
            });
            const storedTab = localStorage.getItem('mr_settings_tab');
            if (storedTab && settingPanes[storedTab]) setSettingTab(storedTab);

            applyToDoc();
        });
    </script>
    <script src="assets/app.js"></script>
</body>

</html>