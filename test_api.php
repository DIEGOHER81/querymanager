<?php
/**
 * Diagnostico de API - ELIMINAR DESPUES DE USAR
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Diagnostico API</h2><pre>";

// 1. Check PHP version
echo "PHP: " . phpversion() . "\n";

// 2. Check extensions
$required = ['pdo_sqlite', 'openssl', 'json'];
foreach ($required as $ext) {
    echo "$ext: " . (extension_loaded($ext) ? 'OK' : 'FALTA!') . "\n";
}

// 3. Check directories
$dataDir = __DIR__ . '/data';
$configDir = __DIR__ . '/config';
echo "\ndata/ existe: " . (is_dir($dataDir) ? 'SI' : 'NO') . "\n";
echo "data/ escribible: " . (is_writable($dataDir) ? 'SI' : 'NO') . "\n";
echo "config/ existe: " . (is_dir($configDir) ? 'SI' : 'NO') . "\n";
echo "config/ escribible: " . (is_writable($configDir) ? 'SI' : 'NO') . "\n";

// 4. Try loading config
echo "\n--- Cargando config ---\n";
try {
    require_once __DIR__ . '/config/app.php';
    echo "config/app.php: OK\n";
    echo "APP_ROOT: " . APP_ROOT . "\n";
    echo "DATA_DIR: " . DATA_DIR . "\n";
    echo "SQLITE_DB: " . SQLITE_DB . "\n";
} catch (Throwable $e) {
    echo "ERROR en config: " . $e->getMessage() . "\n";
}

// 5. Try SQLite
echo "\n--- Probando SQLite ---\n";
try {
    $pdo = new PDO('sqlite:' . SQLITE_DB);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Conexion SQLite: OK\n";
} catch (Throwable $e) {
    echo "ERROR SQLite: " . $e->getMessage() . "\n";
}

// 6. Try bootstrap
echo "\n--- Cargando bootstrap ---\n";
try {
    // Simulate session for bootstrap
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    require_once __DIR__ . '/api/Database/SQLiteManager.php';
    Database\SQLiteManager::init();
    echo "SQLiteManager::init(): OK\n";
} catch (Throwable $e) {
    echo "ERROR bootstrap: " . $e->getMessage() . "\n";
    echo "Archivo: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}

// 7. Check initial password
echo "\n--- Credenciales ---\n";
$passFile = $dataDir . '/.initial_password';
if (file_exists($passFile)) {
    echo file_get_contents($passFile);
} else {
    echo ".initial_password no existe\n";
    // Try to create default user
    try {
        require_once __DIR__ . '/api/Services/AuthService.php';
        Services\AuthService::ensureDefaultUser();
        if (file_exists($passFile)) {
            echo "Generado:\n" . file_get_contents($passFile);
        } else {
            echo "Usuario admin ya existe\n";
        }
    } catch (Throwable $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
}

echo "</pre>";
echo "<p style='color:red;font-weight:bold;'>ELIMINAR test_api.php DESPUES DE USAR</p>";
