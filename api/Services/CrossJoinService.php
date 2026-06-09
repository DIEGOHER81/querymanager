<?php
namespace Services;

class CrossJoinService
{
    const MAX_ROWS = 10000;
    const MAX_CROSS_ROWS = 50000;

    /**
     * Join two result sets in memory.
     * Supports INNER, LEFT, RIGHT and CROSS joins.
     *
     * For CROSS JOIN: left_key and right_key are ignored.
     * Columns are prefixed with their alias to avoid collisions.
     */
    public static function join(
        array $leftRows,
        array $rightRows,
        string $joinType,
        string $leftKey,
        string $rightKey,
        string $leftAlias,
        string $rightAlias,
        array $leftColumns,
        array $rightColumns
    ): array {
        if (count($leftRows) > self::MAX_ROWS) {
            throw new \RuntimeException("El resultado de '{$leftAlias}' excede el limite de " . self::MAX_ROWS . " filas");
        }
        if (count($rightRows) > self::MAX_ROWS) {
            throw new \RuntimeException("El resultado de '{$rightAlias}' excede el limite de " . self::MAX_ROWS . " filas");
        }

        $joinType = strtoupper(trim($joinType));
        if (!in_array($joinType, ['INNER', 'LEFT', 'RIGHT', 'CROSS'])) {
            throw new \RuntimeException("Tipo de JOIN no soportado: {$joinType}. Use INNER, LEFT, RIGHT o CROSS");
        }

        // CROSS JOIN: check cartesian product size
        if ($joinType === 'CROSS') {
            $crossSize = count($leftRows) * count($rightRows);
            if ($crossSize > self::MAX_CROSS_ROWS) {
                throw new \RuntimeException(
                    "CROSS JOIN produciria {$crossSize} filas (limite: " . self::MAX_CROSS_ROWS . "). " .
                    "Reduzca los datos de origen."
                );
            }
        }

        // For non-CROSS joins, validate keys exist
        if ($joinType !== 'CROSS') {
            if (!empty($leftRows) && !array_key_exists($leftKey, $leftRows[0])) {
                throw new \RuntimeException("Columna '{$leftKey}' no encontrada en '{$leftAlias}'");
            }
            if (!empty($rightRows) && !array_key_exists($rightKey, $rightRows[0])) {
                throw new \RuntimeException("Columna '{$rightKey}' no encontrada en '{$rightAlias}'");
            }
        }

        // Build merged column names
        $mergedColumns = [];
        foreach ($leftColumns as $col) {
            $mergedColumns[] = $leftAlias . '.' . $col;
        }
        foreach ($rightColumns as $col) {
            $mergedColumns[] = $rightAlias . '.' . $col;
        }

        // Null templates
        $nullRight = [];
        foreach ($rightColumns as $col) {
            $nullRight[$rightAlias . '.' . $col] = null;
        }
        $nullLeft = [];
        foreach ($leftColumns as $col) {
            $nullLeft[$leftAlias . '.' . $col] = null;
        }

        $mergedRows = [];

        if ($joinType === 'CROSS') {
            foreach ($leftRows as $leftRow) {
                $prefixedLeft = self::prefixRow($leftRow, $leftAlias);
                foreach ($rightRows as $rightRow) {
                    $prefixedRight = self::prefixRow($rightRow, $rightAlias);
                    $mergedRows[] = array_merge($prefixedLeft, $prefixedRight);
                }
            }
        } elseif ($joinType === 'INNER' || $joinType === 'LEFT') {
            $rightIndex = self::buildIndex($rightRows, $rightKey);

            foreach ($leftRows as $leftRow) {
                $keyVal = (string)($leftRow[$leftKey] ?? '');
                $prefixedLeft = self::prefixRow($leftRow, $leftAlias);

                if (isset($rightIndex[$keyVal])) {
                    foreach ($rightIndex[$keyVal] as $rightRow) {
                        $mergedRows[] = array_merge($prefixedLeft, self::prefixRow($rightRow, $rightAlias));
                    }
                } elseif ($joinType === 'LEFT') {
                    $mergedRows[] = array_merge($prefixedLeft, $nullRight);
                }
            }
        } elseif ($joinType === 'RIGHT') {
            $leftIndex = self::buildIndex($leftRows, $leftKey);

            foreach ($rightRows as $rightRow) {
                $keyVal = (string)($rightRow[$rightKey] ?? '');
                $prefixedRight = self::prefixRow($rightRow, $rightAlias);

                if (isset($leftIndex[$keyVal])) {
                    foreach ($leftIndex[$keyVal] as $leftRow) {
                        $mergedRows[] = array_merge(self::prefixRow($leftRow, $leftAlias), $prefixedRight);
                    }
                } else {
                    $mergedRows[] = array_merge($nullLeft, $prefixedRight);
                }
            }
        }

        return [
            'columns' => $mergedColumns,
            'rows' => $mergedRows,
            'row_count' => count($mergedRows),
            'left_count' => count($leftRows),
            'right_count' => count($rightRows)
        ];
    }

    /**
     * Execute a chain of JOINs across multiple sources.
     *
     * @param array $sources Array of ['alias'=>..., 'columns'=>..., 'rows'=>...]
     * @param array $joins Array of ['left_alias'=>, 'right_alias'=>, 'type'=>, 'left_key'=>, 'right_key'=>]
     * @return array Final merged result
     */
    public static function chainJoin(array $sources, array $joins): array
    {
        if (empty($sources)) {
            throw new \RuntimeException("Se requiere al menos una fuente de datos");
        }
        if (count($sources) === 1) {
            $s = reset($sources);
            $alias = key($sources);
            $cols = [];
            foreach ($s['columns'] as $c) {
                $cols[] = $alias . '.' . $c;
            }
            $rows = [];
            foreach ($s['rows'] as $row) {
                $rows[] = self::prefixRow($row, $alias);
            }
            return ['columns' => $cols, 'rows' => $rows, 'row_count' => count($rows)];
        }

        if (empty($joins)) {
            throw new \RuntimeException("Se requiere al menos un JOIN cuando hay multiples fuentes");
        }

        // Start with the first join's left source
        $firstJoin = $joins[0];
        $leftAlias = $firstJoin['left_alias'];

        if (!isset($sources[$leftAlias])) {
            throw new \RuntimeException("Fuente '{$leftAlias}' no encontrada");
        }

        // Initialize accumulator with the first source (prefixed)
        $accum = [
            'columns' => [],
            'rows' => []
        ];
        foreach ($sources[$leftAlias]['columns'] as $c) {
            $accum['columns'][] = $leftAlias . '.' . $c;
        }
        foreach ($sources[$leftAlias]['rows'] as $row) {
            $accum['rows'][] = self::prefixRow($row, $leftAlias);
        }

        // Apply each join sequentially
        foreach ($joins as $i => $join) {
            $rightAlias = $join['right_alias'];
            if (!isset($sources[$rightAlias])) {
                throw new \RuntimeException("Fuente '{$rightAlias}' no encontrada en JOIN #" . ($i + 1));
            }

            $rightSource = $sources[$rightAlias];
            $joinType = strtoupper(trim($join['type']));
            $leftKey = $join['left_key'] ?? '';
            $rightKey = $join['right_key'] ?? '';

            // For the accumulated result, the left_key is already prefixed
            // The right side needs to be joined fresh
            $result = self::joinAccumulated(
                $accum['columns'],
                $accum['rows'],
                $rightSource['rows'],
                $rightSource['columns'],
                $joinType,
                $leftKey,
                $rightKey,
                $rightAlias
            );

            $accum = $result;
        }

        return [
            'columns' => $accum['columns'],
            'rows' => $accum['rows'],
            'row_count' => count($accum['rows'])
        ];
    }

    /**
     * Join an accumulated (already prefixed) left result with a raw right source.
     */
    private static function joinAccumulated(
        array $accumColumns,
        array $accumRows,
        array $rightRows,
        array $rightColumns,
        string $joinType,
        string $leftKey,
        string $rightKey,
        string $rightAlias
    ): array {
        if (count($accumRows) > self::MAX_ROWS) {
            throw new \RuntimeException("El resultado acumulado excede el limite de " . self::MAX_ROWS . " filas");
        }
        if (count($rightRows) > self::MAX_ROWS) {
            throw new \RuntimeException("La fuente '{$rightAlias}' excede el limite de " . self::MAX_ROWS . " filas");
        }

        // Build new columns
        $newColumns = $accumColumns;
        foreach ($rightColumns as $col) {
            $newColumns[] = $rightAlias . '.' . $col;
        }

        // Null templates
        $nullRight = [];
        foreach ($rightColumns as $col) {
            $nullRight[$rightAlias . '.' . $col] = null;
        }
        $nullLeft = [];
        foreach ($accumColumns as $col) {
            $nullLeft[$col] = null;
        }

        $mergedRows = [];

        if ($joinType === 'CROSS') {
            $crossSize = count($accumRows) * count($rightRows);
            if ($crossSize > self::MAX_CROSS_ROWS) {
                throw new \RuntimeException(
                    "CROSS JOIN produciria {$crossSize} filas (limite: " . self::MAX_CROSS_ROWS . ")"
                );
            }
            foreach ($accumRows as $leftRow) {
                foreach ($rightRows as $rightRow) {
                    $mergedRows[] = array_merge($leftRow, self::prefixRow($rightRow, $rightAlias));
                }
            }
        } elseif ($joinType === 'INNER' || $joinType === 'LEFT') {
            // leftKey is already prefixed (e.g. "orders.client_id")
            $rightIndex = self::buildIndex($rightRows, $rightKey);

            foreach ($accumRows as $leftRow) {
                $keyVal = (string)($leftRow[$leftKey] ?? '');

                if (isset($rightIndex[$keyVal])) {
                    foreach ($rightIndex[$keyVal] as $rightRow) {
                        $mergedRows[] = array_merge($leftRow, self::prefixRow($rightRow, $rightAlias));
                    }
                } elseif ($joinType === 'LEFT') {
                    $mergedRows[] = array_merge($leftRow, $nullRight);
                }
            }
        } elseif ($joinType === 'RIGHT') {
            // Build index on accumulated rows by leftKey
            $leftIndex = [];
            foreach ($accumRows as $leftRow) {
                $val = (string)($leftRow[$leftKey] ?? '');
                $leftIndex[$val][] = $leftRow;
            }

            foreach ($rightRows as $rightRow) {
                $keyVal = (string)($rightRow[$rightKey] ?? '');
                $prefixedRight = self::prefixRow($rightRow, $rightAlias);

                if (isset($leftIndex[$keyVal])) {
                    foreach ($leftIndex[$keyVal] as $leftRow) {
                        $mergedRows[] = array_merge($leftRow, $prefixedRight);
                    }
                } else {
                    $mergedRows[] = array_merge($nullLeft, $prefixedRight);
                }
            }
        }

        return [
            'columns' => $newColumns,
            'rows' => $mergedRows
        ];
    }

    /**
     * Apply set operations (UNION, INTERSECT, EXCEPT) across multiple result sets.
     * All sources must have the same number of columns.
     */
    public static function setOperation(array $sources, string $operation): array
    {
        $operation = strtoupper(trim($operation));
        if (!in_array($operation, ['UNION', 'UNION_ALL', 'INTERSECT', 'EXCEPT'])) {
            throw new \RuntimeException("Operacion no soportada: {$operation}");
        }
        if (count($sources) < 2) {
            throw new \RuntimeException("Se requieren al menos 2 fuentes para una operacion de conjuntos");
        }

        // Validate all sources have same column count
        $firstCols = null;
        $firstAlias = null;
        foreach ($sources as $alias => $src) {
            $colCount = count($src['columns'] ?? []);
            if ($firstCols === null) {
                $firstCols = $colCount;
                $firstAlias = $alias;
            } elseif ($colCount !== $firstCols) {
                throw new \RuntimeException(
                    "Las fuentes deben tener el mismo numero de columnas. " .
                    "'{$firstAlias}' tiene {$firstCols} columnas, '{$alias}' tiene {$colCount}."
                );
            }
        }

        // Use first source's column names as the result columns
        $firstSource = reset($sources);
        $columns = $firstSource['columns'];

        // Normalize all rows to use positional keys (column index) for comparison
        $allSets = [];
        foreach ($sources as $alias => $src) {
            $normalized = [];
            foreach ($src['rows'] as $row) {
                $values = array_values($row);
                $normalized[] = $values;
            }
            $allSets[$alias] = $normalized;
        }

        $resultValues = [];

        if ($operation === 'UNION_ALL') {
            // Just concatenate all rows
            foreach ($allSets as $rows) {
                foreach ($rows as $vals) {
                    $resultValues[] = $vals;
                }
            }
        } elseif ($operation === 'UNION') {
            // Concatenate + deduplicate
            $seen = [];
            foreach ($allSets as $rows) {
                foreach ($rows as $vals) {
                    $key = serialize($vals);
                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $resultValues[] = $vals;
                    }
                }
            }
        } elseif ($operation === 'INTERSECT') {
            // Rows present in ALL sources
            $aliases = array_keys($allSets);
            $firstSet = $allSets[$aliases[0]];
            foreach ($firstSet as $vals) {
                $key = serialize($vals);
                $inAll = true;
                for ($i = 1; $i < count($aliases); $i++) {
                    $found = false;
                    foreach ($allSets[$aliases[$i]] as $otherVals) {
                        if (serialize($otherVals) === $key) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) { $inAll = false; break; }
                }
                if ($inAll) $resultValues[] = $vals;
            }
        } elseif ($operation === 'EXCEPT') {
            // Rows in first source but NOT in any other source
            $aliases = array_keys($allSets);
            $firstSet = $allSets[$aliases[0]];
            // Build hash set of all other rows
            $otherKeys = [];
            for ($i = 1; $i < count($aliases); $i++) {
                foreach ($allSets[$aliases[$i]] as $vals) {
                    $otherKeys[serialize($vals)] = true;
                }
            }
            foreach ($firstSet as $vals) {
                if (!isset($otherKeys[serialize($vals)])) {
                    $resultValues[] = $vals;
                }
            }
        }

        // Convert back to associative rows
        $resultRows = [];
        foreach ($resultValues as $vals) {
            $row = [];
            foreach ($columns as $i => $col) {
                $row[$col] = $vals[$i] ?? null;
            }
            $resultRows[] = $row;
        }

        return [
            'columns' => $columns,
            'rows' => $resultRows,
            'row_count' => count($resultRows)
        ];
    }

    private static function buildIndex(array $rows, string $key): array
    {
        $index = [];
        foreach ($rows as $row) {
            $val = (string)($row[$key] ?? '');
            $index[$val][] = $row;
        }
        return $index;
    }

    private static function prefixRow(array $row, string $alias): array
    {
        $prefixed = [];
        foreach ($row as $col => $val) {
            $prefixed[$alias . '.' . $col] = $val;
        }
        return $prefixed;
    }
}
