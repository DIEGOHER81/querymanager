<?php

namespace Controllers;

use Models\Connection;
use Services\SchemaCompareService;
use Services\SchemaService;
use Services\DatabaseService;
use Services\QueryExecutionService;

class SchemaCompareController
{
    /**
     * POST /schema-compare/compare
     * Body: { connA: int, dbA: string, connB: int, dbB: string }
     * Compares schemas and returns structured diff.
     */
    public function compare(): void
    {
        // Schema comparison can be slow with many tables
        @set_time_limit(600);
        @ini_set('memory_limit', '512M');

        $data = getJsonBody();

        $connA = $data['connA'] ?? null;
        $dbA   = $data['dbA'] ?? null;
        $connB = $data['connB'] ?? null;
        $dbB   = $data['dbB'] ?? null;

        if (!$connA || !$dbA || !$connB || !$dbB) {
            errorResponse('Los campos connA, dbA, connB y dbB son obligatorios', 400);
        }

        try {
            $sideA = $this->getSchemaService((int) $connA, $dbA);
            $sideB = $this->getSchemaService((int) $connB, $dbB);

            $service = new SchemaCompareService(
                $sideA['schema'], $sideA['driver'],
                $sideB['schema'], $sideB['driver']
            );
            $diff = $service->compareAll($dbA, $dbB);

            successResponse([
                'diff'    => $diff,
                'driverA' => $sideA['driver'],
                'driverB' => $sideB['driver'],
            ]);
        } catch (\Throwable $e) {
            errorResponse('Error al comparar esquemas: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /schema-compare/generate-script
     * Body: { connA, dbA, connB, dbB, direction: 'AtoB'|'BtoA', diff: {...} }
     * Generates migration SQL script.
     */
    public function generateScript(): void
    {
        @set_time_limit(600);
        @ini_set('memory_limit', '512M');

        $data = getJsonBody();

        $connA     = $data['connA'] ?? null;
        $dbA       = $data['dbA'] ?? null;
        $connB     = $data['connB'] ?? null;
        $dbB       = $data['dbB'] ?? null;
        $direction = $data['direction'] ?? 'AtoB';
        $diff      = $data['diff'] ?? null;

        if (!$connA || !$dbA || !$connB || !$dbB) {
            errorResponse('Los campos connA, dbA, connB y dbB son obligatorios', 400);
        }

        try {
            $sideA = $this->getSchemaService((int) $connA, $dbA);
            $sideB = $this->getSchemaService((int) $connB, $dbB);

            $service = new SchemaCompareService(
                $sideA['schema'], $sideA['driver'],
                $sideB['schema'], $sideB['driver']
            );

            // If diff not provided, re-run comparison
            if ($diff === null) {
                $diff = $service->compareAll($dbA, $dbB);
            }

            $targetDriver = $direction === 'AtoB' ? $sideB['driver'] : $sideA['driver'];
            $script = $service->generateScript($diff, $direction, $targetDriver);

            successResponse([
                'script'    => $script,
                'direction' => $direction,
                'driver'    => $targetDriver,
            ]);
        } catch (\Throwable $e) {
            errorResponse('Error al generar script de migración: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /schema-compare/execute-script
     * Body: { connection_id: int, database: string, script: string }
     * Executes migration script against a connection.
     * Splits by delimiter and executes each statement, reporting results.
     */
    public function executeScript(): void
    {
        $data = getJsonBody();

        $connectionId = $data['connection_id'] ?? null;
        $database     = $data['database'] ?? null;
        $script       = $data['script'] ?? null;

        if (!$connectionId || !$script) {
            errorResponse('Los campos connection_id y script son obligatorios', 400);
        }

        $config = Connection::getById((int) $connectionId);
        if (!$config) {
            errorResponse('Conexión no encontrada', 404);
        }

        $driver = $config['driver'];
        $statements = $this->splitStatements($script, $driver);

        if (empty($statements)) {
            errorResponse('El script no contiene sentencias válidas', 400);
        }

        $results = [];
        $successCount = 0;
        $errorCount = 0;

        foreach ($statements as $index => $sql) {
            $stmtNumber = $index + 1;
            $snippet = mb_substr(trim($sql), 0, 100);

            try {
                $execResult = QueryExecutionService::execute(
                    (int) $connectionId,
                    $sql,
                    $database
                );

                $results[] = [
                    'statement'     => $stmtNumber,
                    'sql_snippet'   => $snippet,
                    'success'       => true,
                    'error'         => null,
                    'rows_affected' => $execResult['row_count'] ?? 0,
                ];
                $successCount++;
            } catch (\Throwable $e) {
                $results[] = [
                    'statement'     => $stmtNumber,
                    'sql_snippet'   => $snippet,
                    'success'       => false,
                    'error'         => $e->getMessage(),
                    'rows_affected' => 0,
                ];
                $errorCount++;
            }
        }

        successResponse([
            'results'       => $results,
            'total'         => count($statements),
            'success_count' => $successCount,
            'error_count'   => $errorCount,
        ]);
    }

    /**
     * Helper: create SchemaService for a connection (same as BrowserController pattern).
     *
     * @return array{schema: SchemaService, driver: string}
     */
    private function getSchemaService(int $connId, ?string $database = null): array
    {
        $config = Connection::getById($connId);
        if (!$config) {
            errorResponse('Conexión no encontrada (ID: ' . $connId . ')', 404);
        }

        $db = $database ?? $config['database_name'] ?? null;
        $pdo = DatabaseService::connect($connId, $db);

        return [
            'schema' => new SchemaService($pdo, $config['driver']),
            'driver' => $config['driver'],
        ];
    }

    /**
     * Split a SQL script into individual statements.
     * For SQL Server: split by 'GO' on its own line.
     * For MySQL: split by ';'.
     *
     * @return string[] Array of non-empty SQL statements.
     */
    private function splitStatements(string $script, string $driver): array
    {
        if ($driver === 'sqlsrv') {
            // Split by 'GO' on its own line (case-insensitive, optional surrounding whitespace)
            $parts = preg_split('/^\s*GO\s*$/mi', $script);
        } else {
            // MySQL: split by semicolons, respecting that ';' is the delimiter
            $parts = $this->splitBySemicolon($script);
        }

        // Filter out empty statements
        $statements = [];
        foreach ($parts as $part) {
            $trimmed = trim($part);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
        }

        return $statements;
    }

    /**
     * Split SQL by semicolons while respecting string literals and comments.
     *
     * @return string[]
     */
    private function splitBySemicolon(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];

            // Single-line comment: -- until end of line
            if ($char === '-' && $i + 1 < $length && $sql[$i + 1] === '-') {
                $end = strpos($sql, "\n", $i);
                if ($end === false) {
                    $current .= substr($sql, $i);
                    break;
                }
                $current .= substr($sql, $i, $end - $i + 1);
                $i = $end + 1;
                continue;
            }

            // Block comment: /* ... */
            if ($char === '/' && $i + 1 < $length && $sql[$i + 1] === '*') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    $current .= substr($sql, $i);
                    break;
                }
                $current .= substr($sql, $i, $end - $i + 2);
                $i = $end + 2;
                continue;
            }

            // String literal (single quote)
            if ($char === "'") {
                $current .= $char;
                $i++;
                while ($i < $length) {
                    if ($sql[$i] === "'" && $i + 1 < $length && $sql[$i + 1] === "'") {
                        $current .= "''";
                        $i += 2;
                    } elseif ($sql[$i] === "'") {
                        $current .= "'";
                        $i++;
                        break;
                    } else {
                        $current .= $sql[$i];
                        $i++;
                    }
                }
                continue;
            }

            // Semicolon delimiter
            if ($char === ';') {
                $statements[] = $current;
                $current = '';
                $i++;
                continue;
            }

            $current .= $char;
            $i++;
        }

        // Add remaining content
        if (trim($current) !== '') {
            $statements[] = $current;
        }

        return $statements;
    }
}
