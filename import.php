<?php
require_once './config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/audit.php';
session_start();

$message = '';
$error = '';
$preview = [];
$columns = [];
$all_data = [];
$import_module = $_POST['target_module'] ?? 'recipients';

// Helper to parse a delimited file (CSV or TSV) into rows
function parse_import_file($filepath)
{
    $delimiter = ',';
    $content = file_get_contents($filepath);
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content); // strip BOM

    // Try to auto-detect delimiter: count occurrences of , ; \t in the first line
    $first_line = strtok($content, "\n");
    $counts = [
        ',' => substr_count($first_line, ','),
        ';' => substr_count($first_line, ';'),
        "\t" => substr_count($first_line, "\t"),
        '|' => substr_count($first_line, '|'),
    ];
    arsort($counts);
    $delimiter = array_key_first($counts) ?: ',';
    if ($delimiter === ';' || $delimiter === '|') {
        $delimiter = ',';
    }

    $rows = [];
    $handle = fopen($filepath, 'r');
    while (($line = fgetcsv($handle, 0, $delimiter)) !== false) {
        $line = array_map(function ($v) {
            return trim($v);
        }, $line);
        if (count(array_filter($line)) === 0) {
            continue; // skip empty rows
        }
        $rows[] = $line;
    }
    fclose($handle);
    return $rows;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
    csrf_check_post();

    $import_module = $_POST['target_module'] ?? 'recipients';
    $tmp_path = $_FILES['csv_file']['tmp_name'];

    if ($_FILES['csv_file']['size'] > 5 * 1024 * 1024) {
        $error = 'File is too large. Maximum size is 5MB.';
    } else {
        $rows = parse_import_file($tmp_path);

        if (count($rows) < 1) {
            $error = 'File could not be parsed. Please upload a valid CSV file with a header row.';
        } else {
            // First row is the header
            $columns = $rows[0];
            $all_data = array_slice($rows, 1); // all data rows (carried to import step)
            $preview = array_slice($all_data, 0, 5); // display first 5
        }
    }
}

// Handle POST validation + import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_import']) && $_POST['do_import'] === '1') {
    csrf_check_post();

    $import_module = $_POST['target_module'] ?? 'recipients';
    $mapping = $_POST['map'] ?? [];
    $full_name = trim($_POST['received_by_field'] ?? '');
    $default_date = trim($_POST['default_date'] ?? date('Y-m-d'));

    $imported = 0;
    $skipped = 0;
    $errors = [];

    // Build data rows from pasted preview content
    $data_rows = isset($_POST['preview_json']) ? json_decode($_POST['preview_json'], true) : [];

    if (empty($data_rows)) {
        $error = 'No data to import. Please upload a file first.';
    } else {
        try {
            $conn->begin_transaction();

            foreach ($data_rows as $row_idx => $row) {
                $values = [];
                foreach ($mapping as $field => $col_idx) {
                    $col_idx = (int)$col_idx;
                    $values[$field] = trim($row[$col_idx] ?? '');
                }

                // Skip entirely empty rows
                if (count(array_filter($values)) === 0) {
                    continue;
                }

                switch ($import_module) {
                    case 'recipients':
                        if (empty($values['name'])) {
                            $errors[] = "Row " . ($row_idx + 2) . ": recipient name is required. Skipped.";
                            $skipped++;
                            continue 2;
                        }
                        // Avoid duplicates
                        $stmt = $conn->prepare("SELECT id FROM recipients WHERE name = ?");
                        $stmt->bind_param("s", $values['name']);
                        $stmt->execute();
                        if ($stmt->get_result()->num_rows > 0) {
                            $stmt->close();
                            $errors[] = "Row " . ($row_idx + 2) . ": recipient '{$values['name']}' already exists. Skipped.";
                            $skipped++;
                            continue 2;
                        }
                        $stmt->close();

                        $stmt = $conn->prepare("INSERT INTO recipients (name, is_active) VALUES (?, 1)");
                        $stmt->bind_param("s", $values['name']);
                        $stmt->execute();
                        $stmt->close();
                        $imported++;
                        break;

                    case 'documents':
                        if (empty($values['document_name'])) {
                            $errors[] = "Row " . ($row_idx + 2) . ": document name is required. Skipped.";
                            $skipped++;
                            continue 2;
                        }
                        $type_id = null;
                        if (!empty($values['type_id'])) {
                            $type_id = (int)$values['type_id'] ?: null;
                        } elseif (!empty($values['type_name'])) {
                            $stmt = $conn->prepare("SELECT id FROM document_types WHERE type_name = ?");
                            $stmt->bind_param("s", $values['type_name']);
                            $stmt->execute();
                            $res = $stmt->get_result();
                            if ($row = $res->fetch_assoc()) {
                                $type_id = (int)$row['id'];
                            } else {
                                // Auto-create type if it doesn't exist
                                $ins = $conn->prepare("INSERT INTO document_types (type_name, description) VALUES (?, '')");
                                $ins->bind_param("s", $values['type_name']);
                                $ins->execute();
                                $type_id = $conn->insert_id;
                                $ins->close();
                            }
                            $stmt->close();
                        }

                        $copies = (int)($values['copies_received'] ?? 1) > 0 ? (int)$values['copies_received'] : 1;
                        $date_received = $values['date_received'] ?: $default_date;
                        if (strtotime($date_received) === false) {
                            $date_received = $default_date;
                        }
                        $date_received = date('Y-m-d', strtotime($date_received));
                        $origin = $values['origin'] ?? '';

                        $stmt = $conn->prepare("INSERT INTO documents (document_name, type_id, type, origin, copies_received, date_received) VALUES (?, ?, ?, ?, ?, ?)");
                        $type_display = null;
                        if ($type_id) {
                            $td = $conn->query("SELECT type_name FROM document_types WHERE id = $type_id")->fetch_assoc();
                            $type_display = $td['type_name'] ?? null;
                        }
                        $dn = $values['document_name'];
                        $stmt->bind_param("sissss", $dn, $type_id, $type_display, $origin, $copies, $date_received);
                        $stmt->execute();
                        $stmt->close();
                        $imported++;
                        break;

                    case 'parcels':
                        if (empty($values['description']) && empty($values['sender']) && empty($values['addressed_to'])) {
                            $errors[] = "Row " . ($row_idx + 2) . ": at least one of description/sender/addressed_to is required. Skipped.";
                            $skipped++;
                            continue 2;
                        }
                        $tracking_id = $values['tracking_id'] ?: ('PRCL-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6)));
                        $date_received = $values['date_received'] ?: $default_date;
                        if (strtotime($date_received) === false) {
                            $date_received = $default_date;
                        }
                        $date_received = date('Y-m-d', strtotime($date_received));

                        $stmt = $conn->prepare("INSERT INTO parcels_received (description, sender, addressed_to, date_received, received_by, tracking_id, delivery_status) VALUES (?, ?, ?, ?, ?, ?, 'received')");
                        $desc = $values['description'] ?? '';
                        $sender = $values['sender'] ?? '';
                        $addr = $values['addressed_to'] ?? '';
                        $recv_by = $values['received_by'] ?: $full_name;
                        $stmt->bind_param("ssssss", $desc, $sender, $addr, $date_received, $recv_by, $tracking_id);
                        $stmt->execute();
                        $stmt->close();
                        $imported++;
                        break;

                    default:
                        $error = 'Invalid import target module.';
                        throw new Exception('Invalid target');
                }
            }

            $conn->commit();
            $message = "Import complete: {$imported} record(s) imported" . ($skipped > 0 ? ", {$skipped} skipped" : "") . ".";
            audit_log('import', $import_module, null, "Imported {$imported} records into {$import_module}" . ($skipped > 0 ? " ({$skipped} skipped)" : ""), 'System');

            // Reset preview after successful import
            $preview = [];
            $columns = [];

        } catch (Exception $e) {
            $conn->rollback();
            $error = "Import failed and was rolled back: " . $e->getMessage();
        }
    }
}

// Fetch document types for the mapping help
$doc_types = [];
$dt_res = $conn->query("SELECT id, type_name FROM document_types ORDER BY type_name");
if ($dt_res) {
    while ($r = $dt_res->fetch_assoc()) {
        $doc_types[] = $r;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Import - Mailroom Ops</title>
    <link rel="icon" type="image/png" href="./images/logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/app.css">
    <meta name="csrf-token" content="<?php echo csrf_token(); ?>">
</head>

<body>
    <div class="flex">
        <?php include 'sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header flex items-center justify-between gap-4">
                <div>
                    <div class="breadcrumb">
                        <a href="index.php">Home</a>
                        <span class="sep">/</span>
                        <span>Import</span>
                    </div>
                    <h1 class="page-header-title">Data Import</h1>
                    <p class="page-header-subtitle">Bulk import records from a CSV file.</p>
                </div>
                <div class="header-actions print-hide">
                    <a href="import.php" class="btn btn-soft">
                        <i class="fa-solid fa-rotate"></i> Reset
                    </a>
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

                <?php if (empty($preview)): ?>
                <!-- Step 1: Upload -->
                <div class="card" style="margin-bottom:20px;">
                    <div class="card-header">
                        <div>
                            <div class="card-title">1. Upload CSV File</div>
                            <div class="card-subtitle">Select the data type and upload your CSV file.</div>
                        </div>
                    </div>
                    <div class="card-body" style="padding:20px;">
                        <form method="POST" enctype="multipart/form-data" id="uploadForm">
                            <?php csrf_field(); ?>
                            <div class="form-field" style="margin-bottom:16px;">
                                <label class="label">Data Type</label>
                                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;" id="moduleOptions">
                                    <?php
                                    $modules = [
                                        'recipients' => ['fa-user', 'Recipients', 'Name per row. One recipient per line.'],
                                        'documents' => ['fa-file-lines', 'Documents', 'Document records with type, origin, copies & date.'],
                                        'parcels' => ['fa-box', 'Parcels', 'Parcel intake records with sender & addressee.'],
                                    ];
                                    foreach ($modules as $key => $m):
                                    ?>
                                        <label style="cursor:pointer;border:1px solid var(--border);border-radius:10px;padding:14px;display:flex;flex-direction:column;gap:6px;transition:all .15s;" class="module-option <?php echo $import_module === $key ? 'selected' : ''; ?>" data-module="<?php echo $key; ?>">
                                            <input type="radio" name="target_module" value="<?php echo $key; ?>" class="hidden" <?php echo $import_module === $key ? 'checked' : ''; ?>>
                                            <span style="display:flex;align-items:center;gap:8px;font-weight:600;font-size:13px;color:var(--text);">
                                                <i class="fa-solid <?php echo $m[0]; ?>" style="color:var(--accent);"></i> <?php echo $m[1]; ?>
                                            </span>
                                            <span style="font-size:12px;color:var(--text-faint);line-height:1.4;"><?php echo $m[2]; ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="form-field" style="margin-bottom:16px;">
                                <label class="label">CSV File</label>
                                <input type="file" name="csv_file" accept=".csv,.tsv,.txt" class="input" required>
                                <p style="font-size:12px;color:var(--text-faint);margin-top:6px;">
                                    Max 5MB. Comma/semicolon/tab separated. First row must be headers.
                                    Save Excel sheets as CSV before uploading.
                                </p>
                            </div>

                            <div class="form-field" style="margin-bottom:16px;">
                                <label class="label">Received By (for Parcels)</label>
                                <input type="text" name="received_by_field" class="input" placeholder="Who is receiving these parcels?">
                            </div>

                            <div class="form-field" style="margin-bottom:16px;">
                                <label class="label">Default Date (if date column missing)</label>
                                <input type="date" name="default_date" class="input" value="<?php echo date('Y-m-d'); ?>">
                            </div>

                            <button type="submit" class="btn btn-primary">
                                <i class="fa-solid fa-upload"></i> Upload &amp; Preview
                            </button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Step 2: Preview & Mapping -->
                <?php if (!empty($preview) && !empty($columns)): ?>
                <div class="card">
                    <div class="card-header">
                        <div>
                            <div class="card-title">2. Verify &amp; Import</div>
                            <div class="card-subtitle">Map columns and review the first rows before importing.</div>
                        </div>
                        <span class="filter-chip"><i class="fa-solid fa-table"></i> <?php echo count($all_data); ?> rows detected</span>
                    </div>
                    <div class="card-body" style="padding:20px;">
                        <form method="POST">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="do_import" value="1">
                            <input type="hidden" name="target_module" value="<?php echo htmlspecialchars($import_module); ?>">
                            <input type="hidden" name="received_by_field" value="<?php echo htmlspecialchars($_POST['received_by_field'] ?? ''); ?>">
                            <input type="hidden" name="default_date" value="<?php echo htmlspecialchars($_POST['default_date'] ?? date('Y-m-d')); ?>">
                            <input type="hidden" name="preview_json" id="previewJson">

                            <!-- Column mapping -->
                            <div class="card-subtitle" style="margin-bottom:10px;font-weight:600;"><i class="fa-solid fa-signs-post" style="color:var(--accent);margin-right:6px;"></i> Column Mapping</div>
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-bottom:24px;" id="mappingFields">
                                <?php
                                $field_defs = [
                                    'recipients' => [
                                        ['name', 'Recipient Name *', true],
                                    ],
                                    'documents' => [
                                        ['document_name', 'Document Name *', true],
                                        ['type_id', 'Type Name (match by name)', false],
                                        ['origin', 'Origin / Source', false],
                                        ['copies_received', 'Copies Received', false],
                                        ['date_received', 'Date Received (YYYY-MM-DD)', false],
                                    ],
                                    'parcels' => [
                                        ['description', 'Description', true],
                                        ['sender', 'Sender', false],
                                        ['addressed_to', 'Addressed To', false],
                                        ['date_received', 'Date Received', false],
                                        ['tracking_id', 'Tracking ID (optional)', false],
                                    ],
                                ];
                                $defs = $field_defs[$import_module] ?? $field_defs['recipients'];
                                foreach ($defs as $def):
                                    $fname = $def[0];
                                    $flabel = $def[1];
                                    $freq = $def[2];
                                    // Suggest a matching column by name similarity
                                    $matched = null;
                                    foreach ($columns as $idx => $col) {
                                        $lcol = strtolower(preg_replace('/[^a-z0-9]/', '', $col));
                                        $lfname = strtolower(preg_replace('/[^a-z0-9]/', '', $fname));
                                        if (strpos($lcol, $lfname) !== false || strpos($lfname, $lcol) !== false) {
                                            $matched = $idx;
                                            break;
                                        }
                                    }
                                ?>
                                <div>
                                    <label class="label" style="font-size:12px;"><?php echo $flabel; ?></label>
                                    <select name="map[<?php echo $fname; ?>]" class="select">
                                        <option value="">— Skip —</option>
                                        <?php foreach ($columns as $idx => $col): ?>
                                            <option value="<?php echo $idx; ?>" <?php echo $matched === $idx ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($col ?: "Column " . ($idx + 1)); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <?php if ($import_module === 'documents' && !empty($doc_types)): ?>
                                <p style="font-size:12px;color:var(--text-faint);margin-bottom:16px;">
                                    Hint: mapping <strong>Type Name</strong> auto-creates new document types when the name doesn't exist.
                                    Existing types: <?php echo implode(', ', array_map(fn($t) => $t['type_name'], $doc_types)); ?>
                                </p>
                            <?php endif; ?>

                            <!-- Preview table -->
                            <div class="card-subtitle" style="margin-bottom:8px;font-weight:600;"><i class="fa-solid fa-eye" style="color:var(--accent);margin-right:6px;"></i> Preview (first <?php echo count($preview); ?> rows)</div>
                            <div class="table-wrap">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <?php foreach ($columns as $col): ?>
                                                <th><?php echo htmlspecialchars($col ?: 'Untitled'); ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($preview as $row): ?>
                                            <tr>
                                                <?php foreach ($columns as $idx => $col): ?>
                                                    <td><?php echo htmlspecialchars($row[$idx] ?? ''); ?></td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div style="margin-top:20px;display:flex;justify-content:flex-end;gap:10px;">
                                <a href="import.php" class="btn btn-soft">Cancel</a>
                                <button type="submit" class="btn btn-primary" onclick="this.form.querySelector('#previewJson').value=JSON.stringify(window.MR_ALL_DATA)">
                                    <i class="fa-solid fa-file-import"></i> Import Data
                                </button>
                            </div>
                        </form>

                        <?php if (!empty($errors)): ?>
                            <hr style="border:none;border-top:1px solid var(--border);margin:18px 0;">
                            <div class="card-subtitle" style="font-weight:600;color:var(--orange);margin-bottom:6px;"><i class="fa-solid fa-triangle-exclamation"></i> Import Notes</div>
                            <ul style="list-style:none;padding:0;margin:0;font-size:12px;color:var(--text-secondary);line-height:1.8;">
                                <?php foreach (array_slice($errors, 0, 10) as $err): ?>
                                    <li>• <?php echo htmlspecialchars($err); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Templates / help -->
                <div class="card" style="margin-top:20px;">
                    <div class="card-header">
                        <div>
                            <div class="card-title">CSV Templates &amp; Field Reference</div>
                            <div class="card-subtitle">Example headers for each data type.</div>
                        </div>
                    </div>
                    <div class="card-body" style="padding:20px;">
                        <?php
                        $templates = [
                            'recipients' => ['name', 'recipient,department,is_active', 'recipient,department,is_active', 'Hollali Kelvin - IT Department,Jane Doe - HR'],
                            'documents' => ['document_name,type_id,origin,copies_received,date_received', 'Education Reform Bill 2026,Legislative Documents,Ministry of Education,10,2026-04-01', 'Annual Report,Reports,MD Office,5,2026-04-02'],
                            'parcels' => ['description,sender,addressed_to,date_received', 'A gift,John Doe,Mr Smith,2026-04-01', 'Ergonomic Chair,ABC Logistics,HR Manager,2026-04-02'],
                        ];
                        ?>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px;">
                            <?php foreach ($templates as $mod => $t): ?>
                                <div>
                                    <div class="label" style="margin-bottom:8px;">
                                        <i class="fa-solid <?php echo $mod === 'recipients' ? 'fa-user' : ($mod === 'documents' ? 'fa-file-lines' : 'fa-box'); ?>" style="color:var(--accent);margin-right:6px;"></i>
                                        <?php echo ucfirst($mod); ?>
                                    </div>
                                    <pre style="background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:10px;font-size:11px;color:var(--text-secondary);overflow-x:auto;line-height:1.7;"><?php
                                        echo htmlspecialchars($t[1] . "\n" . $t[2] . "\n" . $t[3]);
                                    ?></pre>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        window.MR_ALL_DATA = <?php echo json_encode($all_data); ?>;
        document.querySelectorAll('.module-option').forEach(function(opt) {
            opt.addEventListener('click', function() {
                document.querySelectorAll('.module-option').forEach(o => o.classList.remove('selected'));
                this.classList.add('selected');
                this.querySelector('input').checked = true;
            });
        });
    </script>
    <style>
        .module-option.selected { border-color: var(--accent) !important; background: var(--accent-soft); }
    </style>
    <script src="assets/app.js"></script>
</body>

</html>