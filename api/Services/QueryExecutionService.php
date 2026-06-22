<?php

namespace Services;

use Models\Connection;

class QueryExecutionService
{
    /**
     * Execute a SQL query directly against the target database
     */
    public static function execute(int $connectionId, string $sql, ?string $database = null, bool $continueOnError = false): array
    {
        $config = Connection::getById($connectionId);
        if (!$config) {
            throw new \RuntimeException('Conexión no encontrada');
        }

        // Detectar scripts con múltiples sentencias (p.ej. dumps con DELIMITER y
        // procedimientos almacenados). Si hay más de una, se ejecutan en lote.
        $statements = self::splitSqlStatements($sql);
        if (count($statements) > 1) {
            return self::executeScript($connectionId, $config, $statements, $sql, $database, $continueOnError);
        }
        // Una sola sentencia: usar la versión normalizada (sin delimitador/comentarios sobrantes)
        $sql = $statements[0] ?? $sql;

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
            } elseif ($config['driver'] === 'pgsql') {
                // Funcion PostgreSQL que recibe el JSON y devuelve filas
                $stmt = $pdo->prepare("SELECT * FROM {$spName}(?)");
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

    /**
     * Execute a multi-statement script (e.g. a mysqldump with DELIMITER blocks and
     * stored procedures). Statements run sequentially. The result of the last
     * statement that produced a result set is returned for display.
     *
     * @param bool $continueOnError When false (default) it stops at the first failing
     *        statement. When true it keeps going and reports every error at the end.
     */
    private static function executeScript(int $connectionId, array $config, array $statements, string $originalSql, ?string $database, bool $continueOnError = false): array
    {
        $pdo = DatabaseService::connect($connectionId, $database);
        $startTime = microtime(true);

        $executed = 0;
        $totalAffected = 0;
        $lastColumns = [];
        $lastRows = [];
        $lastIsSelect = false;
        $total = count($statements);
        $errors = []; // [ ['index' => int, 'message' => string], ... ]

        foreach ($statements as $idx => $stmt) {
            try {
                // query() usa el protocolo de texto: necesario para DDL y CREATE PROCEDURE/FUNCTION/TRIGGER
                $st = $pdo->query($stmt);

                if ($st->columnCount() > 0) {
                    $lastIsSelect = true;
                    $lastRows = $st->fetchAll(\PDO::FETCH_ASSOC);
                    if (!empty($lastRows)) {
                        $lastColumns = array_keys($lastRows[0]);
                    } else {
                        $lastColumns = [];
                        for ($i = 0; $i < $st->columnCount(); $i++) {
                            $meta = $st->getColumnMeta($i);
                            $lastColumns[] = $meta['name'];
                        }
                    }
                } else {
                    $lastIsSelect = false;
                    $totalAffected += $st->rowCount();
                }
                $st->closeCursor();
                $executed++;
            } catch (\PDOException $e) {
                $error = self::formatError($e, $config['driver']);

                if (!$continueOnError) {
                    $executionMs = round((microtime(true) - $startTime) * 1000);

                    AuditService::log([
                        'connection_id' => $connectionId,
                        'connection_name' => $config['name'],
                        'database_name' => $database ?? $config['database_name'],
                        'query_text' => mb_substr($originalSql, 0, 2000),
                        'execution_mode' => 'script',
                        'execution_time_ms' => $executionMs,
                        'row_count' => $executed,
                        'status' => 'error',
                        'error_message' => "Sentencia " . ($idx + 1) . "/{$total}: {$error}"
                    ]);

                    throw new \RuntimeException(
                        "Error en la sentencia " . ($idx + 1) . " de {$total}: {$error}. " .
                        "Se ejecutaron correctamente {$executed} sentencia(s) antes del error. " .
                        "Activa «Continuar ante errores» para omitir las sentencias que fallen."
                    );
                }

                // Modo tolerante: registrar el error y seguir
                $errors[] = ['index' => $idx + 1, 'message' => $error];
            }
        }

        $executionMs = round((microtime(true) - $startTime) * 1000);
        $failed = count($errors);

        AuditService::log([
            'connection_id' => $connectionId,
            'connection_name' => $config['name'],
            'database_name' => $database ?? $config['database_name'],
            'query_text' => mb_substr($originalSql, 0, 2000),
            'execution_mode' => 'script',
            'execution_time_ms' => $executionMs,
            'row_count' => $lastIsSelect ? count($lastRows) : $totalAffected,
            'status' => $failed > 0 ? 'partial' : 'success',
            'error_message' => $failed > 0
                ? "{$failed} de {$total} sentencia(s) fallaron: " . implode(' | ', array_map(
                    fn($er) => "#{$er['index']}: {$er['message']}", array_slice($errors, 0, 20)))
                : null
        ]);

        $message = "{$executed} de {$total} sentencia(s) ejecutada(s) correctamente en {$executionMs}ms";
        if ($failed > 0) {
            $message .= ", {$failed} con error";
        }
        $message .= $lastIsSelect
            ? " (mostrando la última consulta: " . count($lastRows) . " fila(s))"
            : ", {$totalAffected} fila(s) afectada(s)";

        return [
            'is_select' => $lastIsSelect,
            'columns' => $lastColumns,
            'rows' => $lastRows,
            'row_count' => $lastIsSelect ? count($lastRows) : $totalAffected,
            'statements_executed' => $executed,
            'statements_total' => $total,
            'statements_failed' => $failed,
            'errors' => $errors,
            'execution_time_ms' => $executionMs,
            'message' => $message
        ];
    }

    /**
     * Split a SQL script into individual statements.
     *
     * Handles the constructs that break a naive split-on-';':
     *  - DELIMITER directives (client-side, as produced by mysqldump/phpMyAdmin)
     *  - String/identifier literals ('...', "...", `...`) including escapes
     *  - Line comments (-- and #) and block comments (/* ... *​/)
     *  - Compound statements: BEGIN...END blocks are kept intact even with the
     *    default ';' delimiter, so a single CREATE PROCEDURE pasted without
     *    DELIMITER lines is treated as one statement.
     *
     * @return string[] Trimmed, non-empty statements (delimiters removed).
     */
    private static function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $delimiter = ';';
        $len = strlen($sql);
        $i = 0;
        $buffer = '';
        $beginDepth = 0;   // depth of open BEGIN...END compound blocks
        $atLineStart = true;
        $inner = ['IF', 'CASE', 'LOOP', 'WHILE', 'REPEAT']; // END <x> closes an inner construct

        while ($i < $len) {
            // DELIMITER directive (only meaningful at the logical start of a line)
            if ($atLineStart && $beginDepth === 0) {
                if (preg_match('/^([ \t]*)DELIMITER[ \t]+(\S+)[ \t]*(\r?\n|$)/i', substr($sql, $i), $m)) {
                    if (trim($buffer) !== '') {
                        $statements[] = trim($buffer);
                        $buffer = '';
                    }
                    $delimiter = $m[2];
                    $i += strlen($m[0]);
                    $atLineStart = true;
                    continue;
                }
            }

            $ch = $sql[$i];

            // Line comment: "-- " or end-of-line after --, or '#'
            if ($ch === '-' && $i + 1 < $len && $sql[$i + 1] === '-'
                && ($i + 2 >= $len || $sql[$i + 2] === ' ' || $sql[$i + 2] === "\t" || $sql[$i + 2] === "\r" || $sql[$i + 2] === "\n")) {
                $nl = strpos($sql, "\n", $i);
                if ($nl === false) $nl = $len;
                $buffer .= substr($sql, $i, $nl - $i);
                $i = $nl;
                continue;
            }
            if ($ch === '#') {
                $nl = strpos($sql, "\n", $i);
                if ($nl === false) $nl = $len;
                $buffer .= substr($sql, $i, $nl - $i);
                $i = $nl;
                continue;
            }

            // Block comment (kept verbatim so executable /*! ... */ comments still run)
            if ($ch === '/' && $i + 1 < $len && $sql[$i + 1] === '*') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) { $buffer .= substr($sql, $i); $i = $len; }
                else { $end += 2; $buffer .= substr($sql, $i, $end - $i); $i = $end; }
                $atLineStart = false;
                continue;
            }

            // Quoted string or backtick identifier
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
                $buffer .= $ch;
                $i++;
                while ($i < $len) {
                    $c = $sql[$i];
                    if ($c === '\\' && $quote !== '`') { // backslash escape (not in identifiers)
                        $buffer .= $c;
                        if ($i + 1 < $len) { $buffer .= $sql[$i + 1]; $i += 2; }
                        else { $i++; }
                        continue;
                    }
                    if ($c === $quote) {
                        if ($i + 1 < $len && $sql[$i + 1] === $quote) { // doubled quote = escaped
                            $buffer .= $c . $quote;
                            $i += 2;
                            continue;
                        }
                        $buffer .= $c;
                        $i++;
                        break;
                    }
                    $buffer .= $c;
                    $i++;
                }
                $atLineStart = false;
                continue;
            }

            // Word token: track BEGIN / END to keep compound blocks intact
            if (($ch >= 'A' && $ch <= 'Z') || ($ch >= 'a' && $ch <= 'z') || $ch === '_') {
                $j = $i;
                while ($j < $len) {
                    $w = $sql[$j];
                    if (($w >= 'A' && $w <= 'Z') || ($w >= 'a' && $w <= 'z') || ($w >= '0' && $w <= '9') || $w === '_') {
                        $j++;
                    } else {
                        break;
                    }
                }
                $word = substr($sql, $i, $j - $i);
                $upper = strtoupper($word);

                if ($upper === 'BEGIN') {
                    $beginDepth++;
                } elseif ($upper === 'END') {
                    // Look at the next word; "END IF/CASE/LOOP/WHILE/REPEAT" closes an inner block
                    $k = $j;
                    while ($k < $len && ($sql[$k] === ' ' || $sql[$k] === "\t" || $sql[$k] === "\r" || $sql[$k] === "\n")) $k++;
                    $n = $k;
                    while ($n < $len) {
                        $w = $sql[$n];
                        if (($w >= 'A' && $w <= 'Z') || ($w >= 'a' && $w <= 'z') || ($w >= '0' && $w <= '9') || $w === '_') $n++;
                        else break;
                    }
                    $nextWord = strtoupper(substr($sql, $k, $n - $k));
                    if (!in_array($nextWord, $inner, true) && $beginDepth > 0) {
                        $beginDepth--;
                    }
                }

                $buffer .= $word;
                $i = $j;
                $atLineStart = false;
                continue;
            }

            // Delimiter (only splits at the top level, outside BEGIN...END)
            $dlen = strlen($delimiter);
            if ($beginDepth === 0 && $dlen > 0 && substr($sql, $i, $dlen) === $delimiter) {
                $stmt = trim($buffer);
                if ($stmt !== '') $statements[] = $stmt;
                $buffer = '';
                $i += $dlen;
                $atLineStart = false;
                continue;
            }

            // Ordinary character
            $buffer .= $ch;
            if ($ch === "\n") {
                $atLineStart = true;
            } elseif ($ch !== ' ' && $ch !== "\t" && $ch !== "\r") {
                $atLineStart = false;
            }
            $i++;
        }

        if (trim($buffer) !== '') {
            $statements[] = trim($buffer);
        }

        return $statements;
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

        if ($driver === 'pgsql') {
            if (preg_match('/SQLSTATE\[\w+\].*?:\s*(.+)/', $message, $m)) {
                return "Error PostgreSQL [{$code}]: " . trim($m[1]);
            }
        }

        return "Error de base de datos [{$code}]: {$message}";
    }
}
