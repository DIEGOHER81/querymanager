<?php

namespace Services;

use Database\SQLiteManager;

class AuditService
{
    public static function log(array $data): int
    {
        if (!AUDIT_ENABLED) return 0;

        $pdo = SQLiteManager::getConnection();
        $stmt = $pdo->prepare("INSERT INTO audit_logs (connection_id, connection_name, database_name, query_text, execution_mode, execution_time_ms, row_count, status, error_message, user_ip, executed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $stmt->execute([
            $data['connection_id'] ?? null,
            $data['connection_name'] ?? '',
            $data['database_name'] ?? '',
            $data['query_text'],
            $data['execution_mode'] ?? 'direct',
            $data['execution_time_ms'] ?? 0,
            $data['row_count'] ?? 0,
            $data['status'] ?? 'success',
            $data['error_message'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            date('c')
        ]);

        return (int)$pdo->lastInsertId();
    }

    public static function getAll(int $page = 1, int $perPage = 50, array $filters = []): array
    {
        $pdo = SQLiteManager::getConnection();
        $where = [];
        $params = [];

        if (!empty($filters['connection_id'])) {
            $where[] = "connection_id = ?";
            $params[] = $filters['connection_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = "status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = "executed_at >= ?";
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = "executed_at <= ?";
            $params[] = $filters['date_to'];
        }
        if (!empty($filters['search'])) {
            $where[] = "query_text LIKE ?";
            $params[] = '%' . $filters['search'] . '%';
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Count total
        $countSql = "SELECT COUNT(*) as total FROM audit_logs {$whereClause}";
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($params);
        $total = (int)$stmt->fetch()['total'];

        // Get page
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT * FROM audit_logs {$whereClause} ORDER BY executed_at DESC LIMIT ? OFFSET ?";
        $params[] = $perPage;
        $params[] = $offset;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return [
            'items' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage)
        ];
    }

    public static function clear(): int
    {
        $pdo = SQLiteManager::getConnection();
        $count = (int)$pdo->query("SELECT COUNT(*) as c FROM audit_logs WHERE is_favorite = 0")->fetch()['c'];
        $pdo->exec("DELETE FROM audit_logs WHERE is_favorite = 0");
        return $count;
    }

    public static function toggleFavorite(int $id): bool
    {
        $pdo = SQLiteManager::getConnection();
        $stmt = $pdo->prepare("UPDATE audit_logs SET is_favorite = CASE WHEN is_favorite = 1 THEN 0 ELSE 1 END WHERE id = ?");
        $stmt->execute([$id]);
        $stmt2 = $pdo->prepare("SELECT is_favorite FROM audit_logs WHERE id = ?");
        $stmt2->execute([$id]);
        $row = $stmt2->fetch();
        return $row ? (bool)$row['is_favorite'] : false;
    }

    public static function getFavorites(?int $connectionId = null, ?string $database = null): array
    {
        $pdo = SQLiteManager::getConnection();
        $where = ["is_favorite = 1"];
        $params = [];

        if ($connectionId) {
            $where[] = "connection_id = ?";
            $params[] = $connectionId;
        }
        if ($database) {
            $where[] = "database_name = ?";
            $params[] = $database;
        }

        $sql = "SELECT * FROM audit_logs WHERE " . implode(' AND ', $where) . " ORDER BY executed_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function getStats(): array
    {
        $pdo = SQLiteManager::getConnection();

        $total = $pdo->query("SELECT COUNT(*) as c FROM audit_logs")->fetch()['c'];
        $success = $pdo->query("SELECT COUNT(*) as c FROM audit_logs WHERE status='success'")->fetch()['c'];
        $errors = $pdo->query("SELECT COUNT(*) as c FROM audit_logs WHERE status='error'")->fetch()['c'];
        $avgTime = $pdo->query("SELECT AVG(execution_time_ms) as avg FROM audit_logs WHERE status='success'")->fetch()['avg'];

        $byConnection = $pdo->query("SELECT connection_name, COUNT(*) as count FROM audit_logs GROUP BY connection_name ORDER BY count DESC LIMIT 10")->fetchAll();

        $recent = $pdo->query("SELECT * FROM audit_logs ORDER BY executed_at DESC LIMIT 5")->fetchAll();

        return [
            'total_queries' => (int)$total,
            'successful' => (int)$success,
            'errors' => (int)$errors,
            'avg_execution_ms' => round((float)$avgTime, 2),
            'by_connection' => $byConnection,
            'recent' => $recent
        ];
    }
}
