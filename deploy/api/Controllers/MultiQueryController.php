<?php
namespace Controllers;

use Services\QueryExecutionService;
use Services\CrossJoinService;

class MultiQueryController
{
    /**
     * Execute the same SQL query against multiple connections simultaneously
     * POST /query/multi-execute
     */
    public function executeMulti(): void
    {
        $data = getJsonBody();

        if (empty($data['connection_ids']) || !is_array($data['connection_ids'])) {
            errorResponse('Se requiere un array de connection_ids', 422);
        }
        if (empty($data['sql'])) {
            errorResponse('Se requiere la consulta SQL', 422);
        }

        $sql = trim($data['sql']);
        $databases = $data['databases'] ?? [];
        $results = [];
        $totalTime = 0;

        foreach ($data['connection_ids'] as $connId) {
            $connId = (int)$connId;
            $db = $databases[$connId] ?? $databases[(string)$connId] ?? null;

            try {
                $result = QueryExecutionService::execute($connId, $sql, $db);
                $results[] = [
                    'connection_id' => $connId,
                    'success' => true,
                    'columns' => $result['columns'] ?? [],
                    'rows' => $result['rows'] ?? [],
                    'row_count' => $result['row_count'] ?? 0,
                    'execution_time_ms' => $result['execution_time_ms'] ?? 0,
                    'is_select' => $result['is_select'] ?? true
                ];
                $totalTime += ($result['execution_time_ms'] ?? 0);
            } catch (\Throwable $e) {
                $results[] = [
                    'connection_id' => $connId,
                    'success' => false,
                    'error' => $e->getMessage(),
                    'columns' => [],
                    'rows' => [],
                    'row_count' => 0
                ];
            }
        }

        successResponse([
            'results' => $results,
            'total_connections' => count($data['connection_ids']),
            'successful' => count(array_filter($results, fn($r) => $r['success'])),
            'failed' => count(array_filter($results, fn($r) => !$r['success'])),
            'total_time_ms' => round($totalTime, 2)
        ]);
    }

    /**
     * Execute queries on N different connections and JOIN results in PHP.
     * POST /query/cross-join
     *
     * Body format:
     * {
     *   "sources": [
     *     { "alias": "cli", "connection_id": 1, "sql": "SELECT ...", "database": "db1" },
     *     { "alias": "ord", "connection_id": 2, "sql": "SELECT ...", "database": "db2" },
     *     { "alias": "prod", "connection_id": 3, "sql": "SELECT ...", "database": "db3" }
     *   ],
     *   "joins": [
     *     { "left_alias": "cli", "right_alias": "ord", "type": "INNER", "left_key": "cli.id", "right_key": "client_id" },
     *     { "left_alias": "ord", "right_alias": "prod", "type": "LEFT", "left_key": "ord.product_id", "right_key": "id" }
     *   ]
     * }
     *
     * Also supports legacy 2-source format:
     * { "left": {...}, "right": {...}, "join": {...} }
     */
    public function executeCrossJoin(): void
    {
        $data = getJsonBody();

        // Support legacy 2-source format
        if (isset($data['left']) && isset($data['right']) && isset($data['join'])) {
            $data = $this->convertLegacyFormat($data);
        }

        if (empty($data['sources']) || !is_array($data['sources'])) {
            errorResponse('Se requiere un array de sources', 422);
        }
        if (count($data['sources']) < 2) {
            errorResponse('Se requieren al menos 2 fuentes de datos', 422);
        }
        if (empty($data['joins']) || !is_array($data['joins'])) {
            errorResponse('Se requiere un array de joins', 422);
        }

        // Validate and execute all sources
        $sourceResults = [];
        $totalTime = 0;
        $sourceCounts = [];

        foreach ($data['sources'] as $i => $source) {
            if (empty($source['alias'])) {
                errorResponse("La fuente #" . ($i + 1) . " requiere un alias", 422);
            }
            if (empty($source['connection_id'])) {
                errorResponse("La fuente '{$source['alias']}' requiere connection_id", 422);
            }
            if (empty($source['sql'])) {
                errorResponse("La fuente '{$source['alias']}' requiere sql", 422);
            }

            $alias = trim($source['alias']);
            if (isset($sourceResults[$alias])) {
                errorResponse("Alias duplicado: '{$alias}'", 422);
            }

            try {
                $result = QueryExecutionService::execute(
                    (int)$source['connection_id'],
                    trim($source['sql']),
                    $source['database'] ?? null
                );
                $sourceResults[$alias] = [
                    'columns' => $result['columns'] ?? [],
                    'rows' => $result['rows'] ?? []
                ];
                $sourceCounts[$alias] = $result['row_count'] ?? count($result['rows'] ?? []);
                $totalTime += ($result['execution_time_ms'] ?? 0);
            } catch (\Throwable $e) {
                errorResponse("Error en fuente '{$alias}': " . $e->getMessage(), 400);
            }
        }

        // Validate joins
        foreach ($data['joins'] as $i => $join) {
            if (empty($join['type'])) {
                errorResponse("JOIN #" . ($i + 1) . " requiere type", 422);
            }
            if (empty($join['right_alias'])) {
                errorResponse("JOIN #" . ($i + 1) . " requiere right_alias", 422);
            }
            $jt = strtoupper(trim($join['type']));
            if ($jt !== 'CROSS') {
                if (empty($join['left_key']) || empty($join['right_key'])) {
                    errorResponse("JOIN #" . ($i + 1) . " ({$jt}) requiere left_key y right_key", 422);
                }
            }
        }

        // Perform chained JOIN
        try {
            $merged = CrossJoinService::chainJoin($sourceResults, $data['joins']);
        } catch (\Throwable $e) {
            errorResponse('Error en JOIN: ' . $e->getMessage(), 400);
        }

        successResponse([
            'columns' => $merged['columns'],
            'rows' => $merged['rows'],
            'row_count' => $merged['row_count'],
            'source_counts' => $sourceCounts,
            'joins_applied' => count($data['joins']),
            'is_select' => true,
            'execution_time_ms' => round($totalTime, 2)
        ]);
    }

    /**
     * Execute queries on N sources and combine with set operations (UNION, INTERSECT, EXCEPT).
     * POST /query/set-operation
     * Body: {
     *   "sources": [ { "alias": "a", "connection_id": 1, "sql": "...", "database": "..." }, ... ],
     *   "operation": "EXCEPT"  // UNION, UNION_ALL, INTERSECT, EXCEPT
     * }
     */
    public function executeSetOperation(): void
    {
        $data = getJsonBody();

        if (empty($data['sources']) || !is_array($data['sources']) || count($data['sources']) < 2) {
            errorResponse('Se requieren al menos 2 fuentes de datos', 422);
        }
        if (empty($data['operation'])) {
            errorResponse('Se requiere una operacion (UNION, UNION_ALL, INTERSECT, EXCEPT)', 422);
        }

        $sourceResults = [];
        $sourceCounts = [];
        $totalTime = 0;

        foreach ($data['sources'] as $i => $source) {
            if (empty($source['alias'])) errorResponse("Fuente #" . ($i + 1) . " requiere alias", 422);
            if (empty($source['connection_id'])) errorResponse("Fuente '{$source['alias']}' requiere connection_id", 422);
            if (empty($source['sql'])) errorResponse("Fuente '{$source['alias']}' requiere sql", 422);

            $alias = trim($source['alias']);

            try {
                $result = QueryExecutionService::execute(
                    (int)$source['connection_id'],
                    trim($source['sql']),
                    $source['database'] ?? null
                );
                $sourceResults[$alias] = [
                    'columns' => $result['columns'] ?? [],
                    'rows' => $result['rows'] ?? []
                ];
                $sourceCounts[$alias] = $result['row_count'] ?? count($result['rows'] ?? []);
                $totalTime += ($result['execution_time_ms'] ?? 0);
            } catch (\Throwable $e) {
                errorResponse("Error en fuente '{$alias}': " . $e->getMessage(), 400);
            }
        }

        try {
            $result = CrossJoinService::setOperation($sourceResults, $data['operation']);
        } catch (\Throwable $e) {
            errorResponse('Error en operacion: ' . $e->getMessage(), 400);
        }

        successResponse([
            'columns' => $result['columns'],
            'rows' => $result['rows'],
            'row_count' => $result['row_count'],
            'source_counts' => $sourceCounts,
            'operation' => strtoupper($data['operation']),
            'is_select' => true,
            'execution_time_ms' => round($totalTime, 2)
        ]);
    }

    /**
     * Execute a free-form SQL query against virtual tables from different connections.
     * POST /query/virtual-sql
     * Body: {
     *   "sources": [ { "alias": "a", "connection_id": 1, "sql": "...", "database": "..." }, ... ],
     *   "sql": "SELECT a.name, b.total FROM a INNER JOIN b ON a.id = b.client_id WHERE b.total > 10"
     * }
     */
    public function executeVirtualSql(): void
    {
        $data = getJsonBody();

        if (empty($data['sources']) || !is_array($data['sources'])) {
            errorResponse('Se requiere un array de sources', 422);
        }
        if (empty($data['sql'])) {
            errorResponse('Se requiere el query SQL', 422);
        }

        // Execute each source to get its data
        $tables = [];
        $sourceCounts = [];
        $fetchTime = 0;

        foreach ($data['sources'] as $i => $source) {
            if (empty($source['alias'])) errorResponse("Fuente #" . ($i + 1) . " requiere alias", 422);
            if (empty($source['connection_id'])) errorResponse("Fuente '{$source['alias']}' requiere connection_id", 422);
            if (empty($source['sql'])) errorResponse("Fuente '{$source['alias']}' requiere sql", 422);

            $alias = trim($source['alias']);

            try {
                $result = QueryExecutionService::execute(
                    (int)$source['connection_id'],
                    trim($source['sql']),
                    $source['database'] ?? null
                );
                $tables[$alias] = [
                    'columns' => $result['columns'] ?? [],
                    'rows' => $result['rows'] ?? []
                ];
                $sourceCounts[$alias] = $result['row_count'] ?? count($result['rows'] ?? []);
                $fetchTime += ($result['execution_time_ms'] ?? 0);
            } catch (\Throwable $e) {
                errorResponse("Error en fuente '{$alias}': " . $e->getMessage(), 400);
            }
        }

        // Execute the virtual SQL against the in-memory tables
        try {
            $engine = new \Services\VirtualQueryEngine($tables);
            $queryStart = microtime(true);
            $result = $engine->execute(trim($data['sql']));
            $queryTime = round((microtime(true) - $queryStart) * 1000, 2);
        } catch (\Throwable $e) {
            errorResponse('Error en query virtual: ' . $e->getMessage(), 400);
        }

        successResponse([
            'columns' => $result['columns'],
            'rows' => $result['rows'],
            'row_count' => $result['row_count'],
            'source_counts' => $sourceCounts,
            'is_select' => true,
            'execution_time_ms' => round($fetchTime + $queryTime, 2),
            'fetch_time_ms' => round($fetchTime, 2),
            'query_time_ms' => $queryTime
        ]);
    }

    /**
     * Convert legacy 2-source format to new N-source format
     */
    private function convertLegacyFormat(array $data): array
    {
        $left = $data['left'];
        $right = $data['right'];
        $join = $data['join'];

        $leftAlias = $left['alias'] ?? 'izq';
        $rightAlias = $right['alias'] ?? 'der';

        return [
            'sources' => [
                ['alias' => $leftAlias, 'connection_id' => $left['connection_id'], 'sql' => $left['sql'], 'database' => $left['database'] ?? null],
                ['alias' => $rightAlias, 'connection_id' => $right['connection_id'], 'sql' => $right['sql'], 'database' => $right['database'] ?? null]
            ],
            'joins' => [
                [
                    'left_alias' => $leftAlias,
                    'right_alias' => $rightAlias,
                    'type' => $join['type'],
                    'left_key' => $leftAlias . '.' . $join['left_key'],
                    'right_key' => $join['right_key']
                ]
            ]
        ];
    }
}
