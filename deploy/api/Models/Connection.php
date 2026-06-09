<?php

namespace Models;

use Database\SQLiteManager;
use Services\EncryptionService;

class Connection
{
    public static function getAll(): array
    {
        $pdo = SQLiteManager::getConnection();
        $stmt = $pdo->query("SELECT id, name, driver, host, port, database_name, username, charset, sp_name, options_json, is_active, created_at, updated_at FROM connections ORDER BY name");
        return $stmt->fetchAll();
    }

    public static function getById(int $id): ?array
    {
        $pdo = SQLiteManager::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM connections WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(array $data): int
    {
        $pdo = SQLiteManager::getConnection();
        $now = date('c');
        $stmt = $pdo->prepare("INSERT INTO connections (name, driver, host, port, database_name, username, password_encrypted, charset, sp_name, options_json, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $data['name'],
            $data['driver'],
            $data['host'],
            $data['port'] ?? ($data['driver'] === 'mysql' ? 3306 : 1433),
            $data['database_name'] ?? null,
            $data['username'],
            EncryptionService::encrypt($data['password'] ?? ''),
            $data['charset'] ?? 'utf8mb4',
            $data['sp_name'] ?? null,
            json_encode($data['options'] ?? []),
            1,
            $now,
            $now
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function update(int $id, array $data): bool
    {
        $pdo = SQLiteManager::getConnection();
        $existing = self::getById($id);
        if (!$existing) return false;

        $fields = [];
        $values = [];

        foreach (['name', 'driver', 'host', 'port', 'database_name', 'username', 'charset', 'sp_name'] as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = ?";
                $values[] = $data[$field];
            }
        }

        if (!empty($data['password'])) {
            $fields[] = "password_encrypted = ?";
            $values[] = EncryptionService::encrypt($data['password']);
        }

        if (isset($data['options'])) {
            $fields[] = "options_json = ?";
            $values[] = json_encode($data['options']);
        }

        if (isset($data['is_active'])) {
            $fields[] = "is_active = ?";
            $values[] = $data['is_active'] ? 1 : 0;
        }

        $fields[] = "updated_at = ?";
        $values[] = date('c');
        $values[] = $id;

        $sql = "UPDATE connections SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($values);
    }

    public static function delete(int $id): bool
    {
        $pdo = SQLiteManager::getConnection();
        $stmt = $pdo->prepare("DELETE FROM connections WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public static function getDecryptedPassword(int $id): string
    {
        $conn = self::getById($id);
        if (!$conn) throw new \RuntimeException('Conexión no encontrada');
        return EncryptionService::decrypt($conn['password_encrypted']);
    }
}
