<?php
// includes/audit.php - Audit trail logging helpers

/**
 * Resolve the current operator name for audit logs.
 * Uses the operator name set in Settings > System (stored in session); defaults to 'Library Staff'.
 */
function audit_user()
{
    return isset($_SESSION['app_user']) && $_SESSION['app_user'] !== ''
        ? $_SESSION['app_user']
        : 'Library Staff';
}

/**
 * Record an audit log entry.
 *
 * @param mysqli $conn        Database connection
 * @param string $module      Module name, e.g. 'documents' | 'parcels' | 'distribution'
 * @param string $action_type Action, e.g. 'create' | 'update' | 'delete' | 'distribute' | 'pickup'
 * @param int|null $entity_id ID of the affected record (if any)
 * @param string|null $entity_name Human-readable reference to the affected record
 * @param string|null $description Summary of what happened
 * @param string|array|null $details Optional JSON string or array of details / field changes
 */
function audit_log($conn, $module, $action_type, $entity_id = null, $entity_name = null, $description = null, $details = null)
{
    static $table_exists = null;
    if ($table_exists === null) {
        $res = $conn->query("SHOW TABLES LIKE 'audit_logs'");
        $table_exists = $res && $res->num_rows > 0;
    }
    if (!$table_exists) {
        return false;
    }

    $user = audit_user();
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    $stmt = $conn->prepare(
        "INSERT INTO audit_logs (action_type, module, entity_id, entity_name, description, details, user, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        return false;
    }

    if (is_array($details)) {
        $details = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $stmt->bind_param('ssisssss', $action_type, $module, $entity_id, $entity_name, $description, $details, $user, $ip);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * Build a JSON 'details' payload describing which fields changed and how.
 *
 * @param array $before Original field values
 * @param array $after  New field values
 */
function audit_diff(array $before, array $after)
{
    $changes = [];
    foreach ($after as $field => $new_value) {
        $old_value = $before[$field] ?? null;
        if ((string)$old_value !== (string)$new_value) {
            $changes[$field] = ['from' => $old_value, 'to' => $new_value];
        }
    }
    return $changes ? json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
}