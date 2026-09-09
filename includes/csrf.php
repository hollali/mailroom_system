<?php
// includes/csrf.php - CSRF token generation and validation

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Generate a CSRF token and store it in the session
 */
function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Output a hidden input field containing the CSRF token
 */
function csrf_field()
{
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

/**
 * Validate a submitted CSRF token against the session token
 */
function csrf_validate($token)
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Check and validate CSRF token for POST requests, sending JSON error if invalid.
 * Returns true if valid, false and sends JSON response if invalid.
 * For non-AJAX POSTs, redirects back with error.
 */
function csrf_check_post()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return true;
    }

    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!csrf_validate($token)) {
        $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        if ($is_ajax || isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit();
        }

        http_response_code(403);
        echo 'Invalid CSRF token.';
        exit();
    }

    return true;
}
