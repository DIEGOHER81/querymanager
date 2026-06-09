<?php
/**
 * Reset de contrasena de administrador - ELIMINAR DESPUES DE USAR
 *
 * Uso (navegador):
 *   http://localhost/phpadmin/reset_admin.php
 *      -> muestra instrucciones (no hace nada sin confirmar)
 *
 *   http://localhost/phpadmin/reset_admin.php?confirm=si
 *      -> resetea 'admin' a la clave temporal por defecto y fuerza el cambio
 *
 *   http://localhost/phpadmin/reset_admin.php?confirm=si&user=admin&password=MiClave123
 *      -> resetea el usuario indicado a la contrasena indicada
 *
 * Uso (consola):
 *   php reset_admin.php confirm=si password=MiClave123
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$isCli = (php_sapi_name() === 'cli');

// --- Recoger parametros (GET en web, argv en consola) ---
$params = $_GET;
if ($isCli) {
    foreach (array_slice($argv, 1) as $arg) {
        if (strpos($arg, '=') !== false) {
            [$k, $v] = explode('=', $arg, 2);
            $params[$k] = $v;
        }
    }
}

$confirm     = $params['confirm']  ?? '';
$username    = $params['user']     ?? 'admin';
$newPassword = $params['password'] ?? 'Admin1234';   // clave temporal por defecto
$forceChange = !isset($params['password']);          // si NO dieron clave -> forzar cambio al entrar

if (!$isCli) {
    echo "<h2>Reset de contrasena de administrador</h2><pre>";
}

// --- Sin confirmacion: solo mostrar instrucciones ---
if ($confirm !== 'si') {
    echo "Este script restablece la contrasena de un usuario.\n\n";
    echo "Para ejecutarlo agregue ?confirm=si a la URL:\n";
    echo "  reset_admin.php?confirm=si\n";
    echo "      -> usuario 'admin', clave temporal 'Admin1234', se obliga a cambiarla al entrar.\n\n";
    echo "  reset_admin.php?confirm=si&user=admin&password=MiClave123\n";
    echo "      -> establece la contrasena indicada (no se obliga a cambiar).\n";
    if (!$isCli) {
        echo "</pre><p style='color:red;font-weight:bold;'>ELIMINAR reset_admin.php DESPUES DE USAR</p>";
    }
    exit;
}

// --- Ejecutar reset ---
try {
    require_once __DIR__ . '/config/app.php';

    if (!file_exists(SQLITE_DB)) {
        throw new RuntimeException('La base de datos no existe: ' . SQLITE_DB . ' (acceda primero a la app).');
    }

    $pdo = new PDO('sqlite:' . SQLITE_DB);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Verificar que el usuario exista
    $stmt = $pdo->prepare("SELECT id, role FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user) {
        throw new RuntimeException("El usuario '{$username}' no existe.");
    }

    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare(
        "UPDATE users SET password_hash = ?, must_change_password = ?, is_active = 1, updated_at = ? WHERE id = ?"
    );
    $stmt->execute([$hash, $forceChange ? 1 : 0, date('c'), $user['id']]);

    echo "Contrasena restablecida correctamente.\n\n";
    echo "  Usuario:            {$username}\n";
    echo "  Rol:                {$user['role']}\n";
    echo "  Contrasena nueva:   {$newPassword}\n";
    echo "  Forzar cambio:      " . ($forceChange ? 'SI (se pedira al entrar)' : 'NO') . "\n";
    echo "  Verificacion hash:  " . (password_verify($newPassword, $hash) ? 'OK' : 'FALLO') . "\n";

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

if (!$isCli) {
    echo "</pre><p style='color:red;font-weight:bold;'>ELIMINAR reset_admin.php DESPUES DE USAR</p>";
}
