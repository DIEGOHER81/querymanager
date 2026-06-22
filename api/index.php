<?php

require_once __DIR__ . '/bootstrap.php';

// Parse the request - auto-detect base path
$requestUri = $_SERVER['REQUEST_URI'];
$scriptName = $_SERVER['SCRIPT_NAME']; // e.g. /phpadmin/api/index.php or /api/index.php
$basePath = dirname($scriptName);       // e.g. /phpadmin/api or /api
$path = parse_url($requestUri, PHP_URL_PATH);
// Remove the base path prefix to get the route
if ($basePath !== '/' && strpos($path, $basePath) === 0) {
    $path = substr($path, strlen($basePath));
}
$path = $path ?: '/';
$path = rtrim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];

// CORS: restrict to same origin only (no external origins allowed)
$allowedOrigin = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
header('Access-Control-Allow-Origin: ' . $allowedOrigin);
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');

// Handle OPTIONS (CORS preflight)
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Public routes (no auth required)
$publicRoutes = [
    ['GET',  '/csrf-token',  null, 'csrfToken'],
    ['POST', '/auth/login',  'Controllers\AuthController', 'login'],
    ['GET',  '/auth/me',     'Controllers\AuthController', 'me'],
];

// Protected routes (auth required)
$protectedRoutes = [
    // Auth
    ['POST', '/auth/logout',          'Controllers\AuthController', 'logout'],
    ['POST', '/auth/change-password', 'Controllers\AuthController', 'changePassword'],

    // Users (admin)
    ['GET',    '/users',       'Controllers\AuthController', 'users'],
    ['POST',   '/users',       'Controllers\AuthController', 'createUser'],
    ['PUT',    '/users/(\d+)', 'Controllers\AuthController', 'updateUser'],
    ['DELETE', '/users/(\d+)', 'Controllers\AuthController', 'deleteUser'],

    // Connections
    ['GET',    '/connections',          'Controllers\ConnectionController', 'index'],
    ['GET',    '/connections/(\d+)',    'Controllers\ConnectionController', 'show'],
    ['POST',   '/connections',          'Controllers\ConnectionController', 'store'],
    ['POST',   '/connections/test',     'Controllers\ConnectionController', 'testParams'],
    ['PUT',    '/connections/(\d+)',    'Controllers\ConnectionController', 'update'],
    ['DELETE', '/connections/(\d+)',    'Controllers\ConnectionController', 'destroy'],
    ['POST',   '/connections/(\d+)/test', 'Controllers\ConnectionController', 'test'],

    // Browser
    ['GET', '/browser/(\d+)/databases',        'Controllers\BrowserController', 'databases'],
    ['GET', '/browser/(\d+)/tables',           'Controllers\BrowserController', 'tables'],
    ['GET', '/browser/(\d+)/views',            'Controllers\BrowserController', 'views'],
    ['GET', '/browser/(\d+)/procedures',       'Controllers\BrowserController', 'procedures'],
    ['GET', '/browser/(\d+)/functions',        'Controllers\BrowserController', 'functions'],
    ['GET', '/browser/(\d+)/columns/(.+)',     'Controllers\BrowserController', 'columns'],
    ['GET', '/browser/(\d+)/routine-params/(.+)', 'Controllers\BrowserController', 'routineParams'],
    ['GET', '/browser/(\d+)/routine-definition/(.+)', 'Controllers\BrowserController', 'routineDefinition'],

    // Query
    ['POST', '/query/execute',      'Controllers\QueryController', 'execute'],
    ['POST', '/query/execute-json', 'Controllers\QueryController', 'executeJson'],

    // Multi-Query & Cross-Join
    ['POST', '/query/multi-execute',  'Controllers\MultiQueryController', 'executeMulti'],
    ['POST', '/query/cross-join',     'Controllers\MultiQueryController', 'executeCrossJoin'],
    ['POST', '/query/set-operation',  'Controllers\MultiQueryController', 'executeSetOperation'],
    ['POST', '/query/virtual-sql',    'Controllers\MultiQueryController', 'executeVirtualSql'],

    // Schema Compare
    ['POST', '/schema-compare/compare',          'Controllers\SchemaCompareController', 'compare'],
    ['POST', '/schema-compare/generate-script',   'Controllers\SchemaCompareController', 'generateScript'],
    ['POST', '/schema-compare/execute-script',    'Controllers\SchemaCompareController', 'executeScript'],

    // Export
    ['POST', '/export/csv',   'Controllers\ExportController', 'csv'],
    ['POST', '/export/excel', 'Controllers\ExportController', 'excel'],
    ['POST', '/export/json',  'Controllers\ExportController', 'json'],

    // Backup
    ['POST', '/backup/generate', 'Controllers\BackupController', 'generate'],

    // Audit
    ['GET', '/audit',              'Controllers\AuditController', 'index'],
    ['GET', '/audit/stats',        'Controllers\AuditController', 'stats'],
    ['GET', '/audit/favorites',    'Controllers\AuditController', 'favorites'],
    ['POST', '/audit/(\d+)/toggle-favorite', 'Controllers\AuditController', 'toggleFavorite'],
    ['DELETE', '/audit',           'Controllers\AuditController', 'clear'],
];

// Try public routes first
foreach ($publicRoutes as $route) {
    [$routeMethod, $pattern, $controller, $action] = $route;
    if ($method !== $routeMethod) continue;

    $regex = '#^' . $pattern . '$#';
    if (preg_match($regex, $path, $matches)) {
        array_shift($matches);

        if ($action === 'csrfToken') {
            // Report available drivers, verify sqlsrv ODBC driver is actually installed
            $pdoDrivers = \PDO::getAvailableDrivers();
            $workingDrivers = [];
            foreach ($pdoDrivers as $d) {
                if ($d === 'sqlsrv') {
                    // Test if ODBC driver is actually available
                    try {
                        @new \PDO('sqlsrv:Server=__test__;LoginTimeout=1', '', '');
                    } catch (\PDOException $e) {
                        $msg = $e->getMessage();
                        // When ODBC Driver is NOT installed, the error says:
                        // "This extension requires the Microsoft ODBC Driver for SQL Server"
                        // When it IS installed but server doesn't exist, the error says:
                        // "[Microsoft][ODBC Driver 17 for SQL Server]Login timeout expired"
                        if (stripos($msg, 'requires the Microsoft ODBC Driver') !== false
                            || stripos($msg, 'requires the Microsoft') !== false) {
                            continue; // Skip - ODBC truly not installed
                        }
                        // Any other error = driver works, just can't connect to fake server
                    }
                }
                $workingDrivers[] = $d;
            }
            successResponse([
                'token' => $_SESSION['csrf_token'],
                'drivers' => $workingDrivers
            ]);
        }

        validateCsrf();
        $controllerInstance = new $controller();
        call_user_func_array([$controllerInstance, $action], $matches);
        exit;
    }
}

// Try protected routes (require auth)
foreach ($protectedRoutes as $route) {
    [$routeMethod, $pattern, $controller, $action] = $route;
    if ($method !== $routeMethod) continue;

    $regex = '#^' . $pattern . '$#';
    if (preg_match($regex, $path, $matches)) {
        array_shift($matches);

        // Require authentication
        requireAuth();
        checkPasswordChange($path);

        validateCsrf();
        $controllerInstance = new $controller();
        call_user_func_array([$controllerInstance, $action], $matches);
        exit;
    }
}

errorResponse('Ruta no encontrada: ' . $method . ' ' . $path, 404);
