<?php
/**
 * Verificacion de instalacion - ELIMINAR DESPUES DE USAR
 */

$dataDir = __DIR__ . '/data';
$configDir = __DIR__ . '/config';

echo "<h2>Query Manager - Verificacion de instalacion</h2>";
echo "<pre>";

// Check data directory
echo "data/ existe: " . (is_dir($dataDir) ? 'SI' : 'NO') . "\n";
echo "data/ escribible: " . (is_writable($dataDir) ? 'SI' : 'NO - NECESITA PERMISOS 755 o 775') . "\n";

// Check config directory
echo "config/ existe: " . (is_dir($configDir) ? 'SI' : 'NO') . "\n";
echo "config/ escribible: " . (is_writable($configDir) ? 'SI' : 'NO - NECESITA PERMISOS 755 o 775') . "\n";

// Check SQLite
echo "app.sqlite existe: " . (file_exists($dataDir . '/app.sqlite') ? 'SI (' . filesize($dataDir . '/app.sqlite') . ' bytes)' : 'NO (se creara al primer acceso)') . "\n";

// Check encryption key
echo "encryption_key existe: " . (file_exists($configDir . '/.encryption_key') ? 'SI' : 'NO (se creara automaticamente)') . "\n";

// Check initial password
$passFile = $dataDir . '/.initial_password';
echo "\n--- CREDENCIALES INICIALES ---\n";
if (file_exists($passFile)) {
    echo file_get_contents($passFile);
    echo "\n(Cambie la contrasena al ingresar y elimine este archivo: check_setup.php)\n";
} else {
    echo "Archivo .initial_password NO encontrado.\n";
    echo "Posibles causas:\n";
    echo "  - La carpeta data/ no tiene permisos de escritura\n";
    echo "  - La BD aun no se ha inicializado (acceda a la app primero)\n";

    // Try to force initialization
    if (is_writable($dataDir)) {
        echo "\nIntentando inicializar...\n";
        try {
            require_once __DIR__ . '/config/app.php';
            require_once __DIR__ . '/api/Database/SQLiteManager.php';
            Database\SQLiteManager::init();
            require_once __DIR__ . '/api/Services/AuthService.php';
            Services\AuthService::ensureDefaultUser();

            if (file_exists($passFile)) {
                echo "\n--- CREDENCIALES GENERADAS ---\n";
                echo file_get_contents($passFile);
            } else {
                echo "El usuario admin ya existia. Si no recuerda la contrasena, elimine data/app.sqlite y recargue esta pagina.\n";
            }
        } catch (Throwable $e) {
            echo "Error: " . $e->getMessage() . "\n";
        }
    }
}

// PHP extensions
echo "\n--- EXTENSIONES PHP ---\n";
echo "pdo_sqlite: " . (extension_loaded('pdo_sqlite') ? 'OK' : 'FALTA') . "\n";
echo "openssl: " . (extension_loaded('openssl') ? 'OK' : 'FALTA') . "\n";
echo "json: " . (extension_loaded('json') ? 'OK' : 'FALTA') . "\n";
echo "pdo_mysql: " . (extension_loaded('pdo_mysql') ? 'OK' : 'NO (necesario para MySQL)') . "\n";
echo "pdo_pgsql: " . (extension_loaded('pdo_pgsql') ? 'OK' : 'NO (necesario para PostgreSQL)') . "\n";
echo "mbstring: " . (extension_loaded('mbstring') ? 'OK' : 'NO') . "\n";
echo "PHP version: " . phpversion() . "\n";
echo "mod_rewrite: " . (function_exists('apache_get_modules') && in_array('mod_rewrite', apache_get_modules()) ? 'OK' : 'No detectable (puede estar activo)') . "\n";

echo "</pre>";
echo "<p style='color:red;font-weight:bold;'>ELIMINE ESTE ARCHIVO (check_setup.php) DESPUES DE VERIFICAR.</p>";
