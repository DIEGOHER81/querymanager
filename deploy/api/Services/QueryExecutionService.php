<?php

namespace Services;

use Models\Connection;

class QueryExecutionService
{
    /**
     * Execute a SQL query directly against the target database
     */
    public static function execute(int $connectionId, string $sql, ?string $database = null): array
    {
        $config = Connection::getById($connectionId);
        if (!$config) {
            throw new \RuntimeException('Conexión no encontrada');
        }

        $pdo = DatabaseService::connect($connectionId, $database);
        $startTime = microtime(true);
        $error = null;
        $rows = [];
        $columns = [];
        $rowCount = 0;
        $isSelect = false;

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute();

            // Determine if it's a SELECT-type query
            $isSelect = $stmt->columnCount() > 0;

            if ($isSelect) {
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                $rowCount = count($rows);

                // Extract column metadata
                if ($rowCount > 0) {
                    $columns = array_keys($rows[0]);
                } else {
                    for ($i = 0; $i < $stmt->columnCount(); $i++) {
                        $meta = $stmt->getColumnMeta($i);
                        $columns[] = $meta['name'];
                    }
                }
            } else {
                $rowCount = $stmt->rowCount();
            }
        } catch (\PDOException $e) {
            $error = self::formatError($e, $config['driver']);
            $executionMs = round((microtime(true) - $startTime) * 1000);

            AuditService::log([
                'connection_id' => $connectionId,
                'connection_name' => $config['name'],
                'database_name' => $database ?? $config['database_name'],
                'query_text' => $sql,
                'execution_mode' => 'direct',
                'execution_time_ms' => $executionMs,
                'row_count' => 0,
                'status' => 'error',
                'error_message' => $error
            ]);

            throw new \RuntimeException($error);
        }

        $executionMs = round((microtime(true) - $startTime) * 1000);

        AuditService::log([
            'connection_id' => $connectionId,
            'connection_name' => $config['name'],
            'database_name' => $database ?? $config['database_name'],
            'query_text' => $sql,
            'execution_mode' => 'direct',
            'execution_time_ms' => $executionMs,
            'row_count' => $rowCount,
            'status' => 'success'
        ]);

        return [
            'is_select' => $isSelect,
            'columns' => $columns,
            'rows' => $rows,
            'row_count' => $rowCount,
            'execution_time_ms' => $executionMs,
            'message' => $isSelect
                ? "{$rowCount} filas encontradas en {$executionMs}ms"
                : "{$rowCount} filas afectadas en {$executionMs}ms"
        ];
    }

    /**
     * Execute via JSON stored procedure
     */
    public static function executeViaJson(int $connectionId, string $sql, ?string $database = null, array $params = []): array
    {
        $config = Connection::getById($connectionId);
        if (!$config) {
            throw new \RuntimeException('Conexión no encontrada');
        }

        $spName = $config['sp_name'] ?? 'sp_ExecuteJsonQuery';

        $jsonPayload = json_encode([
            'query' => $sql,
            'params' => $params,
            'limit' => JSON_RESULT_LIMIT
        ], JSON_UNESCAPED_UNICODE);

        $pdo = DatabaseService::connect($connectionId, $database);
        $startTime = microtime(true);

        try {
            if ($config['driver'] === 'mysql') {
                $stmt = $pdo->prepare("CALL {$spName}(?)");
            } else {
                $stmt = $pdo->prepare("EXEC {$spName} @JsonInput = ?");
            }

            $stmt->execute([$jsonPayload]);
            $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $executionMs = round((microtime(true) - $startTime) * 1000);

            AuditService::log([
                'connection_id' => $connectionId,
                'connection_name' => $config['name'],
                'database_name' => $database ?? $config['database_name'],
                'query_text' => "JSON_SP[{$spName}]: {$sql}",
                'execution_mode' => 'json_sp',
                'execution_time_ms' => $executionMs,
                'row_count' => count($result),
                'status' => 'success'
            ]);

            return [
                'is_select' => true,
                'sp_name' => $spName,
                'result' => $result,
                'row_count' => count($result),
                'execution_time_ms' => $executionMs,
                'message' => "Ejecutado via {$spName} en {$executionMs}ms"
            ];

        } catch (\PDOException $e) {
            $executionMs = round((microtime(true) - $startTime) * 1000);
            $error = self::formatError($e, $config['driver']);

            AuditService::log([
                'connection_id' => $connectionId,
                'connection_name' => $config['name'],
                'database_name' => $database ?? $config['database_name'],
                'query_text' => "JSON_SP[{$spName}]: {$sql}",
                'execution_mode' => 'json_sp',
                'execution_time_ms' => $executionMs,
                'row_count' => 0,
                'status' => 'error',
                'error_message' => $error
            ]);

            throw new \RuntimeException($error);
        }
    }

    private static function formatError(\PDOException $e, string $driver): string
    {
        $code = $e->getCode();
        $message = $e->getMessage();

        // Clean up common error patterns
        if ($driver === 'mysql') {
            if (preg_match('/SQLSTATE\[\w+\].*?:\s*(.+)/', $message, $m)) {
                return "Error MySQL [{$code}]: {$m[1]}";
            }
        }

        if ($driver === 'sqlsrv') {
            if (preg_match('/\[SQL Server\](.+)/', $message, $m)) {
                return "Error SQL Server: " . trim($m[1]);
            }
        }

        return "Error de base de datos [{$code}]: {$message}";
    }
}
