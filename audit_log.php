<?php
// audit_log.php - Redirect to the unified audit trail viewer
require_once __DIR__ . '/includes/csrf.php';

$query = http_build_query(array_filter([
    'module'    => $_GET['module'] ?? null,
    'action'    => $_GET['action'] ?? null,
    'q'         => $_GET['q'] ?? null,
    'date_from' => $_GET['date'] ?? null,
    'date_to'   => $_GET['date'] ?? null,
    'page'      => $_GET['page'] ?? null,
]));

header('Location: audit_trail.php' . ($query ? '?' . $query : ''));
exit();