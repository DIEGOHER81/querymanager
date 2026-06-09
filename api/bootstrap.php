<?php

// Suppress all PHP error output - errors go through the exception handler as JSON
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');

// Start output buffering so any accidental output is captured and discarded on error
ob_start();

// Secure session configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', 1);
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    ini_set('session.cookie_secure', 1);
}

session_start();

require_once __DIR__ . '/../config/app.php';

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self';");

// Simple autoloader
spl_autoload_register(function ($class) {
    $file = __DIR__ . '/' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Error handler
set_exception_handler(function (Throwable $e) {
    // Discard any accidental output (PHP warnings, var_dump, etc.) before JSON
    while (ob_get_level() > 0) { ob_end_clean(); }

    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    $response = [
        'success' => false,
        'error' => $e->getMessage(),
        'code' => 500
    ];
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        $response['trace'] = $e->getTraceAsString();
        $response['file'] = $e->getFile() . ':' . $e->getLine();
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
});

// Convert PHP errors to exceptions, but ignore @-suppressed errors
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Catch fatal errors (out of memory, parse errors, etc.) that bypass the exception handler
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success' => false,
            'error' => 'Error fatal: ' . $err['message'],
            'code' => 500,
            'file' => (defined('DEBUG_MODE') && DEBUG_MODE) ? ($err['file'] . ':' . $err['line']) : null
        ], JSON_UNESCAPED_UNICODE);
    }
});

// Initialize SQLite database
require_once __DIR__ . '/Database/SQLiteManager.php';
Database\SQLiteManager::init();

// Ensure default admin user exists
require_once __DIR__ . '/Services/AuthService.php';
Services\AuthService::ensureDefaultUser();

// CSRF token generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Require authentication for protected routes
 */
function requireAuth(): void
{
    if (empty($_SESSION['user_id'])) {
        errorResponse('No autenticado. Inicie sesión.', 401);
    }
}

/**
 * Check if user must change password (block other actions)
 */
function checkPasswordChange(string $currentPath): void
{
    $allowedPaths = ['/auth/logout', '/auth/change-password', '/auth/me'];
    if (!empty($_SESSION['must_change_password']) && !in_array($currentPath, $allowedPaths)) {
        errorResponse('Debe cambiar su contraseña antes de continuar', 403);
    }
}

/**
 * Send JSON response
 */
function jsonResponse($data, int $code = 200): void
{
    // Discard any accidental output (PHP warnings/notices) before sending JSON
    while (ob_get_level() > 0) { ob_end_clean(); }

    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function successResponse($data = null, string $message = 'OK'): void
{
    jsonResponse(['success' => true, 'message' => $message, 'data' => $data]);
}

function errorResponse(string $message, int $code = 400, $details = null): void
{
    $resp = ['success' => false, 'error' => $message, 'code' => $code];
    if ($details !== null && DEBUG_MODE) {
        $resp['details'] = $details;
    }
    jsonResponse($resp, $code);
}

/**
 * Get JSON request body
 */
function getJsonBody(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Validate CSRF token for mutating requests
 */
function validateCsrf(): void
{
    $method = $_SERVER['REQUEST_METHOD'];
    if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'], $token)) {
            errorResponse('Token CSRF inválido', 403);
        }
    }
}
