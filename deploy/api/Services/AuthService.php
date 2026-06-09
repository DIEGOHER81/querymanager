<?php

namespace Services;

use Database\SQLiteManager;

class AuthService
{
    /**
     * Ensure default admin user exists
     */
    public static function ensureDefaultUser(): void
    {
        $pdo = SQLiteManager::getConnection();
        $stmt = $pdo->query("SELECT COUNT(*) as c FROM users");
        $count = (int)$stmt->fetch()['c'];

        if ($count === 0) {
            // Generate a secure random password for first-time setup
            $defaultPassword = bin2hex(random_bytes(6)); // 12 char hex password
            $now = date('c');
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role, is_active, must_change_password, created_at, updated_at) VALUES (?, ?, ?, ?, 1, 1, ?, ?)");
            $stmt->execute([
                'admin',
                password_hash($defaultPassword, PASSWORD_BCRYPT),
                'Administrador',
                'admin',
                $now,
                $now
            ]);

            // Write the initial password to a temp file (read once, then delete)
            $initFile = DATA_DIR . '/.initial_password';
            file_put_contents($initFile, "Usuario: admin\nContraseña: {$defaultPassword}\n");
            @chmod($initFile, 0600);
        }
    }

    /**
     * Authenticate user
     */
    public static function login(string $username, string $password): ?array
    {
        $pdo = SQLiteManager::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return null;
        }

        // Update last login
        $stmt = $pdo->prepare("UPDATE users SET last_login = ? WHERE id = ?");
        $stmt->execute([date('c'), $user['id']]);

        // Set session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_fullname'] = $user['full_name'];
        $_SESSION['must_change_password'] = (bool)($user['must_change_password'] ?? false);

        unset($user['password_hash']);
        return $user;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function isLoggedIn(): bool
    {
        return !empty($_SESSION['user_id']);
    }

    public static function getCurrentUser(): ?array
    {
        if (!self::isLoggedIn()) return null;
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'role' => $_SESSION['user_role'],
            'full_name' => $_SESSION['user_fullname']
        ];
    }

    public static function changePassword(int $userId, string $currentPassword, string $newPassword): bool
    {
        $pdo = SQLiteManager::getConnection();
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
            return false;
        }

        $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 0, updated_at = ? WHERE id = ?");
        $stmt->execute([password_hash($newPassword, PASSWORD_BCRYPT), date('c'), $userId]);
        $_SESSION['must_change_password'] = false;
        return true;
    }

    public static function getAll(): array
    {
        $pdo = SQLiteManager::getConnection();
        $stmt = $pdo->query("SELECT id, username, full_name, role, is_active, last_login, created_at FROM users ORDER BY username");
        return $stmt->fetchAll();
    }

    public static function create(array $data): int
    {
        $pdo = SQLiteManager::getConnection();
        $now = date('c');
        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $data['username'],
            password_hash($data['password'], PASSWORD_BCRYPT),
            $data['full_name'] ?? '',
            $data['role'] ?? 'user',
            $data['is_active'] ?? 1,
            $now,
            $now
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function update(int $id, array $data): bool
    {
        $pdo = SQLiteManager::getConnection();
        $fields = [];
        $values = [];

        foreach (['username', 'full_name', 'role'] as $f) {
            if (isset($data[$f])) {
                $fields[] = "$f = ?";
                $values[] = $data[$f];
            }
        }
        if (isset($data['is_active'])) {
            $fields[] = "is_active = ?";
            $values[] = $data['is_active'] ? 1 : 0;
        }
        if (!empty($data['password'])) {
            $fields[] = "password_hash = ?";
            $values[] = password_hash($data['password'], PASSWORD_BCRYPT);
        }

        $fields[] = "updated_at = ?";
        $values[] = date('c');
        $values[] = $id;

        $stmt = $pdo->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?");
        return $stmt->execute($values);
    }

    public static function delete(int $id): bool
    {
        $pdo = SQLiteManager::getConnection();
        // Don't allow deleting the last admin
        $admins = $pdo->query("SELECT COUNT(*) as c FROM users WHERE role = 'admin' AND is_active = 1")->fetch()['c'];
        $user = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $user->execute([$id]);
        $row = $user->fetch();
        if ($row && $row['role'] === 'admin' && $admins <= 1) {
            throw new \RuntimeException('No se puede eliminar el último administrador');
        }

        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }
}
