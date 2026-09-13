<?php
// includes/helpers.php - Shared helper functions for the mailroom system

/**
 * Check if a table has a specific column
 */
function tableHasColumn($conn, $table, $column)
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $result && $result->num_rows > 0;
}

/**
 * Normalize a datetime-local input value for database storage
 */
function normalizeDateTimeInput($value)
{
    if (!$value) {
        return null;
    }
    return str_replace('T', ' ', trim($value));
}

/**
 * Format a timestamp for display
 */
function formatTimestampDisplay($value)
{
    if (empty($value)) {
        return 'N/A';
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return htmlspecialchars($value);
    }
    return date('M j, Y g:i A', $timestamp);
}

/**
 * Generate a document distribution reference
 */
function generateDocDistRef($id, $date_distributed = null)
{
    $date_part = date('Ymd', strtotime($date_distributed ?: 'now'));
    return 'DDIST-' . $date_part . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}

/**
 * Generate a newspaper distribution reference
 */
function generateDistributionReference($id, $date_distributed = null)
{
    $date_part = date('Ymd', strtotime($date_distributed ?: 'now'));
    return 'DIST-' . $date_part . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}

/**
 * Format a list of categories for display
 */
function formatCategoriesList($categories_list, $newspapers_list = null)
{
    $list = $newspapers_list ? $newspapers_list : $categories_list;
    if (empty($list)) {
        return '&mdash;';
    }
    $items = explode(', ', $list);
    $html = '';
    foreach ($items as $item) {
        $html .= '<span class="category-badge">' . htmlspecialchars(trim($item)) . '</span>';
    }
    return $html;
}

/**
 * Generate an issue number for a newspaper
 */
function generateIssueNumber($category_name, $date_received)
{
    $category_prefix = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $category_name), 0, 3));
    $date_prefix = date('Ymd', strtotime($date_received));
    $random_suffix = str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
    return $category_prefix . '-' . $date_prefix . '-' . $random_suffix;
}

/**
 * Generate a display serial number for a document
 */
function getDocumentSerialDisplay(array $document, $hasSerialNumberColumn)
{
    if ($hasSerialNumberColumn && !empty($document['serial_number'])) {
        return $document['serial_number'];
    }
    return 'DOC-' . str_pad((string)($document['id'] ?? 0), 6, '0', STR_PAD_LEFT);
}

/**
 * Map a parcel delivery status to a badge class + label
 */
function parcelStatusBadge($delivery_status, $picked = false)
{
    if ($picked) {
        return '<span class="badge badge-green"><i class="fa-solid fa-check"></i> Picked Up</span>';
    }
    return match($delivery_status) {
        'in_transit' => '<span class="badge badge-blue"><i class="fa-solid fa-truck-moving"></i> In Transit</span>',
        'out_for_delivery' => '<span class="badge badge-orange"><i class="fa-solid fa-truck-fast"></i> Out for Delivery</span>',
        'delivered' => '<span class="badge badge-green"><i class="fa-solid fa-circle-check"></i> Delivered</span>',
        'returned' => '<span class="badge badge-red"><i class="fa-solid fa-rotate-left"></i> Returned</span>',
        'picked' => '<span class="badge badge-green"><i class="fa-solid fa-box-open"></i> Picked Up</span>',
        default => '<span class="badge badge-orange"><i class="fa-solid fa-inbox"></i> Received</span>'
    };
}

/**
 * List of valid parcel delivery statuses
 */
function parcelDeliveryStatuses()
{
    return ['received', 'in_transit', 'out_for_delivery', 'delivered', 'returned', 'picked'];
}
