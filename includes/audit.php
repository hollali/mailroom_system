<?php
// includes/audit.php - Audit trail logging helpers

require_once __DIR__ . '/../config/db.php';

/**
 * Log an activity to the audit trail
 *
 * @param string $action   e.g. 'create', 'update', 'delete', 'receive', 'pickup', 'distribute', 'import', 'restore'
 * @param string $module   e.g. 'parcel', 'document', 'distribution','newspaper','recipient','backup'
 * @param int|null $record_id   The record affected
 * @param string $details  Human-readable description of what happened
 * @param string|null $actor    Who performed the action (from form fields, falls back to IP)
 */
function audit_log($action, $module, $record_id = null, $details = '', $actor = null)
{
    global $conn;

    // Safely attempt to write, never break the main operation on a log failure
    try {
        $actor = $actor !== null && trim($actor) !== '' ? trim($actor) : 'System';

        $stmt = $conn->prepare("
            INSERT INTO audit_log (user_name, action, module, record_id, details, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $stmt->bind_param("sssiss", $actor, $action, $module, $record_id, $details, $ip);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        // Silently ignore logging errors
    }
}