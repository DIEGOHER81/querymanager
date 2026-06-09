<?php

namespace Services;

class SchemaCompareService
{
    private SchemaService $sourceSchema;
    private SchemaService $targetSchema;
    private string $sourceDriver;
    private string $targetDriver;

    public function __construct(
        SchemaService $source,
        string $sourceDriver,
        SchemaService $target,
        string $targetDriver
    ) {
        $this->sourceSchema = $source;
        $this->sourceDriver = $sourceDriver;
        $this->targetSchema = $target;
        $this->targetDriver = $targetDriver;
    }

    /**
     * Compara todos los objetos de esquema entre las bases de datos origen y destino.
     * Retorna un diff estructurado con: tables, views, procedures, functions y resumen.
     */
    public function compareAll(string $dbA, string $dbB): array
    {
        $tables = $this->compareTables($dbA, $dbB);
        $views = $this->compareViews($dbA, $dbB);
        $procedures = $this->compareProcedures($dbA, $dbB);
        $functions = $this->compareFunctions($dbA, $dbB);

        $summary = [
            'tables' => [
                'only_in_source' => count($tables['onlyInSource']),
                'only_in_target' => count($tables['onlyInTarget']),
                'different'      => count(array_filter($tables['common'], fn($t) => !$t['identical'])),
                'identical'      => count(array_filter($tables['common'], fn($t) => $t['identical'])),
            ],
            'views' => [
                'only_in_source' => count($views['onlyInSource']),
                'only_in_target' => count($views['onlyInTarget']),
                'different'      => count($views['different']),
                'identical'      => count($views['identical']),
            ],
            'procedures' => [
                'only_in_source' => count($procedures['onlyInSource']),
                'only_in_target' => count($procedures['onlyInTarget']),
                'different'      => count($procedures['different']),
                'identical'      => count($procedures['identical']),
            ],
            'functions' => [
                'only_in_source' => count($functions['onlyInSource']),
                'only_in_target' => count($functions['onlyInTarget']),
                'different'      => count($functions['different']),
                'identical'      => count($functions['identical']),
            ],
        ];

        return [
            'tables'     => $tables,
            'views'      => $views,
            'procedures' => $procedures,
            'functions'  => $functions,
            'summary'    => $summary,
        ];
    }

    // -------------------------------------------------------------------------
    //  Tablas
    // -------------------------------------------------------------------------

    /**
     * Compara las tablas entre origen y destino.
     * Para las tablas presentes en ambos, compara las columnas.
     */
    /** @var array Cache: lowercase tableName => columns */
    private array $sourceColumnsCache = [];
    private array $targetColumnsCache = [];

    private function compareTables(string $dbA, string $dbB): array
    {
        $sourceTables = $this->extractNames($this->sourceSchema->getTables($dbA));
        $targetTables = $this->extractNames($this->targetSchema->getTables($dbB));

        // Pre-load all columns in a single query each side (huge perf gain)
        $this->sourceColumnsCache = $this->sourceSchema->getAllColumns($dbA);
        $this->targetColumnsCache = $this->targetSchema->getAllColumns($dbB);

        // Case-insensitive comparison (SQL Server is CI by default)
        $sourceLower = array_map('strtolower', $sourceTables);
        $targetLower = array_map('strtolower', $targetTables);

        $onlyInSource = [];
        $onlyInTarget = [];
        $inBothSource = []; // names from source for tables in both

        foreach ($sourceTables as $i => $name) {
            if (!in_array($sourceLower[$i], $targetLower, true)) {
                $onlyInSource[] = $name;
            } else {
                $inBothSource[$sourceLower[$i]] = $name;
            }
        }
        foreach ($targetTables as $i => $name) {
            if (!in_array($targetLower[$i], $sourceLower, true)) {
                $onlyInTarget[] = $name;
            }
        }

        $common = [];
        foreach ($inBothSource as $table) {
            $colDiff   = $this->compareTableColumns($table, $dbA, $dbB);
            $identical = empty($colDiff['onlyInSource'])
                      && empty($colDiff['onlyInTarget'])
                      && empty($colDiff['different']);

            $common[] = [
                'name'      => $table,
                'identical' => $identical,
                'columns'   => $colDiff,
            ];
        }

        return [
            'onlyInSource' => $onlyInSource,
            'onlyInTarget' => $onlyInTarget,
            'common'       => $common,
        ];
    }

    /**
     * Compara las columnas de una tabla entre origen y destino.
     */
    private function compareTableColumns(string $table, string $dbA, string $dbB): array
    {
        $key = strtolower($table);
        // Use cache if available, fallback to direct query
        $srcRaw = $this->sourceColumnsCache[$key] ?? $this->sourceSchema->getColumns($table, $dbA);
        $tgtRaw = $this->targetColumnsCache[$key] ?? $this->targetSchema->getColumns($table, $dbB);
        $srcCols = $this->indexByName($srcRaw);
        $tgtCols = $this->indexByName($tgtRaw);

        $srcNames = array_keys($srcCols);
        $tgtNames = array_keys($tgtCols);

        $onlyInSource = [];
        $onlyInTarget = [];
        $different    = [];
        $identical    = [];

        foreach (array_diff($srcNames, $tgtNames) as $name) {
            $onlyInSource[] = $srcCols[$name];
        }
        foreach (array_diff($tgtNames, $srcNames) as $name) {
            $onlyInTarget[] = $tgtCols[$name];
        }

        foreach (array_intersect($srcNames, $tgtNames) as $name) {
            $src = $srcCols[$name];
            $tgt = $tgtCols[$name];

            $diffs = $this->diffColumn($src, $tgt);
            if (!empty($diffs)) {
                $different[] = [
                    'name'   => $name,
                    'source' => $src,
                    'target' => $tgt,
                    'diffs'  => $diffs,
                ];
            } else {
                $identical[] = $name;
            }
        }

        return [
            'onlyInSource' => $onlyInSource,
            'onlyInTarget' => $onlyInTarget,
            'different'    => $different,
            'identical'    => $identical,
        ];
    }

    /**
     * Compara dos columnas y retorna las diferencias encontradas.
     */
    private function diffColumn(array $src, array $tgt): array
    {
        $diffs = [];

        // Tipo de dato (normalizado para comparar entre drivers)
        $srcType = $this->normalizeType($src['full_type'] ?? $src['data_type'] ?? '', $this->sourceDriver);
        $tgtType = $this->normalizeType($tgt['full_type'] ?? $tgt['data_type'] ?? '', $this->targetDriver);
        if (strtolower($srcType) !== strtolower($tgtType)) {
            $diffs[] = [
                'attribute' => 'type',
                'source'    => $src['full_type'] ?? $src['data_type'] ?? '',
                'target'    => $tgt['full_type'] ?? $tgt['data_type'] ?? '',
            ];
        }

        // Nullable
        $srcNullable = strtoupper($src['nullable'] ?? 'YES');
        $tgtNullable = strtoupper($tgt['nullable'] ?? 'YES');
        if ($srcNullable !== $tgtNullable) {
            $diffs[] = [
                'attribute' => 'nullable',
                'source'    => $srcNullable,
                'target'    => $tgtNullable,
            ];
        }

        // Valor por defecto
        $srcDefault = $this->normalizeDefault($src['default_value'] ?? null);
        $tgtDefault = $this->normalizeDefault($tgt['default_value'] ?? null);
        if ($srcDefault !== $tgtDefault) {
            $diffs[] = [
                'attribute' => 'default_value',
                'source'    => $src['default_value'] ?? null,
                'target'    => $tgt['default_value'] ?? null,
            ];
        }

        // Extra (auto_increment, etc.)
        $srcExtra = strtolower(trim($src['extra'] ?? ''));
        $tgtExtra = strtolower(trim($tgt['extra'] ?? ''));
        if ($srcExtra !== $tgtExtra) {
            $diffs[] = [
                'attribute' => 'extra',
                'source'    => $src['extra'] ?? '',
                'target'    => $tgt['extra'] ?? '',
            ];
        }

        // Key
        $srcKey = strtoupper(trim($src['key_type'] ?? ''));
        $tgtKey = strtoupper(trim($tgt['key_type'] ?? ''));
        if ($srcKey !== $tgtKey) {
            $diffs[] = [
                'attribute' => 'key',
                'source'    => $src['key_type'] ?? '',
                'target'    => $tgt['key_type'] ?? '',
            ];
        }

        return $diffs;
    }

    // -------------------------------------------------------------------------
    //  Vistas
    // -------------------------------------------------------------------------

    /**
     * Compara las vistas entre origen y destino.
     */
    /**
     * Diff case-insensitive de nombres. Devuelve [onlyInSource, onlyInTarget, inBoth].
     */
    private function diffNamesCi(array $sourceNames, array $targetNames): array
    {
        $sourceLower = array_map('strtolower', $sourceNames);
        $targetLower = array_map('strtolower', $targetNames);

        $onlyInSource = [];
        $onlyInTarget = [];
        $inBoth       = []; // names from source

        foreach ($sourceNames as $i => $name) {
            if (in_array($sourceLower[$i], $targetLower, true)) {
                $inBoth[] = $name;
            } else {
                $onlyInSource[] = $name;
            }
        }
        foreach ($targetNames as $i => $name) {
            if (!in_array($targetLower[$i], $sourceLower, true)) {
                $onlyInTarget[] = $name;
            }
        }
        return [$onlyInSource, $onlyInTarget, $inBoth];
    }

    private function compareViews(string $dbA, string $dbB): array
    {
        $sourceViews = $this->extractNames($this->sourceSchema->getViews($dbA));
        $targetViews = $this->extractNames($this->targetSchema->getViews($dbB));

        [$onlyInSource, $onlyInTarget, $inBoth] = $this->diffNamesCi($sourceViews, $targetViews);

        $different = [];
        $identical = [];

        foreach ($inBoth as $viewName) {
            $srcDef = $this->normalizeDefinition(
                $this->sourceSchema->getRoutineDefinition($viewName, $dbA)
            );
            $tgtDef = $this->normalizeDefinition(
                $this->targetSchema->getRoutineDefinition($viewName, $dbB)
            );

            if ($srcDef !== $tgtDef) {
                $different[] = [
                    'name'             => $viewName,
                    'source_definition' => $srcDef,
                    'target_definition' => $tgtDef,
                ];
            } else {
                $identical[] = $viewName;
            }
        }

        return [
            'onlyInSource' => $onlyInSource,
            'onlyInTarget' => $onlyInTarget,
            'different'    => $different,
            'identical'    => $identical,
        ];
    }

    // -------------------------------------------------------------------------
    //  Procedimientos almacenados
    // -------------------------------------------------------------------------

    /**
     * Compara los procedimientos almacenados entre origen y destino.
     */
    private function compareProcedures(string $dbA, string $dbB): array
    {
        return $this->compareRoutines(
            $this->sourceSchema->getProcedures($dbA),
            $this->targetSchema->getProcedures($dbB),
            $dbA,
            $dbB
        );
    }

    // -------------------------------------------------------------------------
    //  Funciones
    // -------------------------------------------------------------------------

    /**
     * Compara las funciones entre origen y destino.
     */
    private function compareFunctions(string $dbA, string $dbB): array
    {
        return $this->compareRoutines(
            $this->sourceSchema->getFunctions($dbA),
            $this->targetSchema->getFunctions($dbB),
            $dbA,
            $dbB
        );
    }

    /**
     * Logica comun para comparar procedimientos o funciones.
     */
    private function compareRoutines(array $sourceList, array $targetList, string $dbA, string $dbB): array
    {
        $sourceNames = $this->extractNames($sourceList);
        $targetNames = $this->extractNames($targetList);

        [$onlyInSource, $onlyInTarget, $inBoth] = $this->diffNamesCi($sourceNames, $targetNames);

        $different = [];
        $identical = [];

        foreach ($inBoth as $name) {
            $srcDef = $this->normalizeDefinition(
                $this->sourceSchema->getRoutineDefinition($name, $dbA)
            );
            $tgtDef = $this->normalizeDefinition(
                $this->targetSchema->getRoutineDefinition($name, $dbB)
            );

            if ($srcDef !== $tgtDef) {
                $different[] = [
                    'name'             => $name,
                    'source_definition' => $srcDef,
                    'target_definition' => $tgtDef,
                ];
            } else {
                $identical[] = $name;
            }
        }

        return [
            'onlyInSource' => $onlyInSource,
            'onlyInTarget' => $onlyInTarget,
            'different'    => $different,
            'identical'    => $identical,
        ];
    }

    // =========================================================================
    //  Generacion de script de migracion
    // =========================================================================

    /**
     * Genera un script SQL de migracion basado en los resultados de la comparacion.
     *
     * @param array  $diff          Resultado de compareAll()
     * @param string $direction     'AtoB' (destino se convierte en origen) o 'BtoA' (origen se convierte en destino)
     * @param string $targetDriver  Driver del servidor destino del script ('mysql' o 'sqlsrv')
     */
    public function generateScript(array $diff, string $direction, string $targetDriver): string
    {
        if (!in_array($direction, ['AtoB', 'BtoA'], true)) {
            throw new \InvalidArgumentException("Direccion invalida: se espera 'AtoB' o 'BtoA'.");
        }

        $sep       = $targetDriver === 'sqlsrv' ? "\nGO\n" : ";\n";
        $lines     = [];
        $timestamp = date('Y-m-d H:i:s');

        $lines[] = $this->comment("Script de migracion generado el {$timestamp}", $targetDriver);
        $lines[] = $this->comment("Direccion: {$direction}", $targetDriver);
        $lines[] = '';

        // ------------------------------------------------------------------
        //  Tablas
        // ------------------------------------------------------------------
        $tables = $diff['tables'] ?? [];

        // Tablas que se deben crear en el destino
        $toCreate = $direction === 'AtoB'
            ? ($tables['onlyInSource'] ?? [])
            : ($tables['onlyInTarget'] ?? []);

        // Tablas que se deben eliminar del destino
        $toDrop = $direction === 'AtoB'
            ? ($tables['onlyInTarget'] ?? [])
            : ($tables['onlyInSource'] ?? []);

        if (!empty($toCreate)) {
            $lines[] = $this->comment('=== CREAR TABLAS FALTANTES ===', $targetDriver);
            // Use cache if populated by compareAll, otherwise fallback
            $srcCache = $this->sourceColumnsCache;
            $tgtCache = $this->targetColumnsCache;
            foreach ($toCreate as $tableName) {
                $key = strtolower($tableName);
                if ($direction === 'AtoB') {
                    $columns = $srcCache[$key] ?? $this->sourceSchema->getColumns($tableName);
                } else {
                    $columns = $tgtCache[$key] ?? $this->targetSchema->getColumns($tableName);
                }
                $lines[] = $this->buildCreateTable($tableName, $columns, $targetDriver) . $sep;
            }
        }

        if (!empty($toDrop)) {
            $lines[] = $this->comment('=== ELIMINAR TABLAS SOBRANTES ===', $targetDriver);
            foreach ($toDrop as $tableName) {
                $lines[] = "DROP TABLE " . $this->quoteIdentifier($tableName, $targetDriver) . $sep;
            }
        }

        // Columnas diferentes en tablas comunes
        $commonTables = $tables['common'] ?? [];
        $alteredTables = array_filter($commonTables, fn($t) => !$t['identical']);
        if (!empty($alteredTables)) {
            $lines[] = $this->comment('=== MODIFICAR COLUMNAS EN TABLAS COMUNES ===', $targetDriver);
            foreach ($alteredTables as $tableInfo) {
                $tableName = $tableInfo['name'];
                $cols      = $tableInfo['columns'];
                $lines[]   = $this->comment("Tabla: {$tableName}", $targetDriver);

                $lines = array_merge(
                    $lines,
                    $this->generateColumnAlterations($tableName, $cols, $direction, $targetDriver, $sep)
                );
            }
        }

        // ------------------------------------------------------------------
        //  Vistas
        // ------------------------------------------------------------------
        $lines = array_merge(
            $lines,
            $this->generateRoutineStatements(
                $diff['views'] ?? [],
                'VIEW',
                $direction,
                $targetDriver,
                $sep
            )
        );

        // ------------------------------------------------------------------
        //  Procedimientos
        // ------------------------------------------------------------------
        $lines = array_merge(
            $lines,
            $this->generateRoutineStatements(
                $diff['procedures'] ?? [],
                'PROCEDURE',
                $direction,
                $targetDriver,
                $sep
            )
        );

        // ------------------------------------------------------------------
        //  Funciones
        // ------------------------------------------------------------------
        $lines = array_merge(
            $lines,
            $this->generateRoutineStatements(
                $diff['functions'] ?? [],
                'FUNCTION',
                $direction,
                $targetDriver,
                $sep
            )
        );

        $script = implode("\n", $lines);

        // Limpiar lineas vacias consecutivas
        $script = preg_replace("/\n{3,}/", "\n\n", $script);

        return trim($script) . "\n";
    }

    // -------------------------------------------------------------------------
    //  Helpers del generador de scripts
    // -------------------------------------------------------------------------

    /**
     * Genera sentencias ALTER TABLE para las diferencias de columnas.
     */
    private function generateColumnAlterations(
        string $tableName,
        array $colDiff,
        string $direction,
        string $driver,
        string $sep
    ): array {
        $lines = [];

        // Columnas a agregar
        $toAdd = $direction === 'AtoB'
            ? ($colDiff['onlyInSource'] ?? [])
            : ($colDiff['onlyInTarget'] ?? []);

        // Columnas a eliminar
        $toRemove = $direction === 'AtoB'
            ? ($colDiff['onlyInTarget'] ?? [])
            : ($colDiff['onlyInSource'] ?? []);

        // Columnas a modificar
        $toModify = $colDiff['different'] ?? [];

        foreach ($toAdd as $col) {
            $colName = $col['name'];
            $colType = $col['full_type'] ?? $col['data_type'] ?? 'VARCHAR(255)';
            $nullable = (strtoupper($col['nullable'] ?? 'YES') === 'YES') ? 'NULL' : 'NOT NULL';
            $default  = $this->buildDefaultClause($col['default_value'] ?? null, $driver);
            $tbl = $this->quoteIdentifier($tableName, $driver);
            $cn  = $this->quoteIdentifier($colName, $driver);

            if ($driver === 'mysql') {
                $lines[] = "ALTER TABLE {$tbl} ADD COLUMN {$cn} {$colType} {$nullable}{$default}" . $sep;
            } else {
                $colType = $this->mapTypeForDriver($colType, $driver);
                $lines[] = "ALTER TABLE {$tbl} ADD {$cn} {$colType} {$nullable}{$default}" . $sep;
            }
        }

        foreach ($toRemove as $col) {
            $colName = $col['name'];
            $tbl = $this->quoteIdentifier($tableName, $driver);
            $cn  = $this->quoteIdentifier($colName, $driver);
            $lines[] = "ALTER TABLE {$tbl} DROP COLUMN {$cn}" . $sep;
        }

        foreach ($toModify as $mod) {
            $colName = $mod['name'];
            // Obtener la definicion deseada segun la direccion
            $desired = $direction === 'AtoB' ? $mod['source'] : $mod['target'];
            $colType = $desired['full_type'] ?? $desired['data_type'] ?? 'VARCHAR(255)';
            $nullable = (strtoupper($desired['nullable'] ?? 'YES') === 'YES') ? 'NULL' : 'NOT NULL';
            $default  = $this->buildDefaultClause($desired['default_value'] ?? null, $driver);
            $tbl = $this->quoteIdentifier($tableName, $driver);
            $cn  = $this->quoteIdentifier($colName, $driver);

            // Determine which attributes actually differ
            $diffAttrs = array_column($mod['diffs'], 'attribute');
            $diffDesc = implode(', ', $diffAttrs);
            $lines[] = $this->comment("Columna {$colName}: diferencias en [{$diffDesc}]", $driver);

            $needsTypeAlter = in_array('type', $diffAttrs, true) || in_array('nullable', $diffAttrs, true);
            $needsDefaultAlter = in_array('default_value', $diffAttrs, true);

            if ($driver === 'mysql') {
                // MySQL: MODIFY COLUMN cubre todo
                $lines[] = "ALTER TABLE {$tbl} MODIFY COLUMN {$cn} {$colType} {$nullable}{$default}" . $sep;
            } else {
                $colType = $this->mapTypeForDriver($colType, $driver);
                // SQL Server: solo emitir ALTER COLUMN si cambia tipo o nullable
                if ($needsTypeAlter) {
                    $lines[] = "ALTER TABLE {$tbl} ALTER COLUMN {$cn} {$colType} {$nullable}" . $sep;
                }
                // El DEFAULT en SQL Server requiere DROP/ADD CONSTRAINT separado
                if ($needsDefaultAlter) {
                    $constraintName = "DF_{$tableName}_{$colName}";
                    $lines[] = $this->comment("Reasignar valor por defecto", $driver);
                    // Eliminar constraint existente si hay
                    $lines[] = "IF EXISTS (SELECT 1 FROM sys.default_constraints WHERE parent_object_id = OBJECT_ID('{$tableName}') AND COL_NAME(parent_object_id, parent_column_id) = '{$colName}')\n"
                             . "BEGIN\n"
                             . "    DECLARE @dc NVARCHAR(256)\n"
                             . "    SELECT @dc = dc.name FROM sys.default_constraints dc\n"
                             . "        JOIN sys.columns c ON dc.parent_object_id = c.object_id AND dc.parent_column_id = c.column_id\n"
                             . "        WHERE dc.parent_object_id = OBJECT_ID('{$tableName}') AND c.name = '{$colName}'\n"
                             . "    EXEC('ALTER TABLE {$tbl} DROP CONSTRAINT ' + @dc)\n"
                             . "END" . $sep;

                    if ($default !== '') {
                        $defaultVal = trim(str_replace(' DEFAULT ', '', $default));
                        $lines[] = "ALTER TABLE {$tbl} ADD CONSTRAINT [{$constraintName}] DEFAULT {$defaultVal} FOR {$cn}" . $sep;
                    } else {
                        $lines[] = $this->comment("Sin DEFAULT en origen, solo se elimina la constraint", $driver);
                    }
                }
            }
        }

        return $lines;
    }

    /**
     * Genera sentencias CREATE/ALTER/DROP para vistas, procedimientos o funciones.
     */
    private function generateRoutineStatements(
        array $routineDiff,
        string $objectType,
        string $direction,
        string $driver,
        string $sep
    ): array {
        $lines = [];
        $label = strtoupper($objectType);
        $labelPlural = match ($objectType) {
            'VIEW'      => 'VISTAS',
            'PROCEDURE' => 'PROCEDIMIENTOS',
            'FUNCTION'  => 'FUNCIONES',
            default     => $objectType . 'S',
        };

        $toCreate = $direction === 'AtoB'
            ? ($routineDiff['onlyInSource'] ?? [])
            : ($routineDiff['onlyInTarget'] ?? []);

        $toDrop = $direction === 'AtoB'
            ? ($routineDiff['onlyInTarget'] ?? [])
            : ($routineDiff['onlyInSource'] ?? []);

        $toAlter = $routineDiff['different'] ?? [];

        if (empty($toCreate) && empty($toDrop) && empty($toAlter)) {
            return $lines;
        }

        $lines[] = '';
        $lines[] = $this->comment("=== {$labelPlural} ===", $driver);

        foreach ($toCreate as $name) {
            $definition = $direction === 'AtoB'
                ? $this->sourceSchema->getRoutineDefinition($name)
                : $this->targetSchema->getRoutineDefinition($name);

            $lines[] = $this->comment("Crear {$label}: {$name}", $driver);
            if ($definition) {
                if ($driver === 'sqlsrv') {
                    // SQL Server: drop if exists then create (idempotent)
                    $code = $this->objectTypeCode($objectType);
                    $qn = $this->quoteIdentifier($name, $driver);
                    $lines[] = "IF OBJECT_ID('{$name}', '{$code}') IS NOT NULL\n    DROP {$label} {$qn}" . $sep;
                    $lines[] = $definition . $sep;
                } else {
                    // MySQL: drop if exists then create
                    $qn = $this->quoteIdentifier($name, $driver);
                    $lines[] = "DROP {$label} IF EXISTS {$qn}" . $sep;
                    $lines[] = $definition . $sep;
                }
            } else {
                $qn = $this->quoteIdentifier($name, $driver);
                $lines[] = $this->comment("ADVERTENCIA: No se pudo obtener la definicion de {$name}. Crear manualmente.", $driver);
                $lines[] = "-- CREATE {$label} {$qn} AS ..." . $sep;
            }
        }

        foreach ($toDrop as $name) {
            $qn = $this->quoteIdentifier($name, $driver);
            $lines[] = $this->comment("Eliminar {$label}: {$name}", $driver);
            if ($driver === 'sqlsrv') {
                $lines[] = "IF OBJECT_ID('{$name}', '{$this->objectTypeCode($objectType)}') IS NOT NULL\n    DROP {$label} {$qn}" . $sep;
            } else {
                $lines[] = "DROP {$label} IF EXISTS {$qn}" . $sep;
            }
        }

        foreach ($toAlter as $item) {
            $name = $item['name'];
            $desiredDef = $direction === 'AtoB'
                ? ($item['source_definition'] ?? null)
                : ($item['target_definition'] ?? null);

            $lines[] = $this->comment("Modificar {$label}: {$name}", $driver);

            if ($desiredDef) {
                // Try to convert CREATE to ALTER (handles leading comments/whitespace)
                // Match CREATE skipping leading whitespace AND single-line/block comments
                $altered = preg_replace(
                    '/(\A(?:\s|--[^\n]*\n|\/\*.*?\*\/)*)\bCREATE\b/is',
                    '$1ALTER',
                    $desiredDef,
                    1,
                    $count
                );

                if ($driver === 'sqlsrv') {
                    if ($count > 0) {
                        // Successfully converted to ALTER
                        $lines[] = $altered . $sep;
                    } else {
                        // Fallback: DROP IF EXISTS + execute original CREATE definition
                        $code = $this->objectTypeCode($objectType);
                        $qn = $this->quoteIdentifier($name, $driver);
                        $lines[] = "IF OBJECT_ID('{$name}', '{$code}') IS NOT NULL\n    DROP {$label} {$qn}" . $sep;
                        $lines[] = $desiredDef . $sep;
                    }
                } else {
                    // MySQL: DROP y recrear
                    $qn = $this->quoteIdentifier($name, $driver);
                    $lines[] = "DROP {$label} IF EXISTS {$qn}" . $sep;
                    $lines[] = $desiredDef . $sep;
                }
            } else {
                $lines[] = $this->comment("ADVERTENCIA: No se pudo obtener la definicion de {$name}. Modificar manualmente.", $driver);
            }
        }

        return $lines;
    }

    // =========================================================================
    //  Utilidades
    // =========================================================================

    /**
     * Extrae los nombres de una lista de registros con clave 'name'.
     */
    private function extractNames(array $rows): array
    {
        return array_map(fn($r) => $r['name'] ?? $r['TABLE_NAME'] ?? '', $rows);
    }

    /**
     * Indexa una lista de columnas por nombre.
     */
    private function indexByName(array $columns): array
    {
        $indexed = [];
        foreach ($columns as $col) {
            $name = $col['name'] ?? $col['COLUMN_NAME'] ?? '';
            if ($name !== '') {
                // Normalize the stored name and use lowercase as key for CI compare
                $col['name'] = $name;
                $indexed[strtolower($name)] = $col;
            }
        }
        return $indexed;
    }

    /**
     * Normaliza un tipo de dato para comparacion entre drivers.
     */
    private function normalizeType(string $type, string $driver): string
    {
        $type = strtolower(trim($type));

        // Eliminar unsigned, zerofill para comparacion
        $type = preg_replace('/\s+(unsigned|zerofill)/i', '', $type);
        $type = trim($type);

        // Mapa de equivalencias MySQL <-> SQL Server
        $equivalences = [
            // Enteros
            'int(11)'       => 'int',
            'int(10)'       => 'int',
            'integer'       => 'int',
            'tinyint(1)'    => 'bit',
            'tinyint(3)'    => 'tinyint',
            'tinyint(4)'    => 'tinyint',
            'smallint(5)'   => 'smallint',
            'smallint(6)'   => 'smallint',
            'mediumint(8)'  => 'int',
            'mediumint(9)'  => 'int',
            'bigint(20)'    => 'bigint',
            // Texto
            'longtext'      => 'nvarchar(max)',
            'mediumtext'    => 'nvarchar(max)',
            'tinytext'      => 'nvarchar(255)',
            'text'          => 'nvarchar(max)',
            'longblob'      => 'varbinary(max)',
            'mediumblob'    => 'varbinary(max)',
            'blob'          => 'varbinary(max)',
            'tinyblob'      => 'varbinary(255)',
            // Fecha
            'datetime'      => 'datetime',
            'timestamp'     => 'datetime',
            // Bool
            'boolean'       => 'bit',
            'bool'          => 'bit',
            // Flotante
            'double'        => 'float',
            'real'          => 'float',
        ];

        return $equivalences[$type] ?? $type;
    }

    /**
     * Convierte un tipo MySQL a SQL Server o viceversa para generacion de DDL.
     */
    private function mapTypeForDriver(string $type, string $driver): string
    {
        if ($driver === 'sqlsrv') {
            $map = [
                'longtext'    => 'NVARCHAR(MAX)',
                'mediumtext'  => 'NVARCHAR(MAX)',
                'tinytext'    => 'NVARCHAR(255)',
                'text'        => 'NVARCHAR(MAX)',
                'longblob'    => 'VARBINARY(MAX)',
                'mediumblob'  => 'VARBINARY(MAX)',
                'blob'        => 'VARBINARY(MAX)',
                'tinyblob'    => 'VARBINARY(255)',
                'datetime'    => 'DATETIME',
                'timestamp'   => 'DATETIME',
                'boolean'     => 'BIT',
                'bool'        => 'BIT',
                'double'      => 'FLOAT',
                'enum'        => 'NVARCHAR(255)',
                'set'         => 'NVARCHAR(255)',
            ];
            $lower = strtolower($type);
            // Manejar enum(...) y set(...)
            if (preg_match('/^enum\(/', $lower) || preg_match('/^set\(/', $lower)) {
                return 'NVARCHAR(255)';
            }
            // Eliminar display width de enteros MySQL: int(11) -> INT
            if (preg_match('/^(tinyint|smallint|mediumint|int|bigint)\(\d+\)$/i', $type, $m)) {
                $base = strtoupper($m[1]);
                return $base === 'MEDIUMINT' ? 'INT' : $base;
            }
            return $map[$lower] ?? $type;
        }

        // driver === 'mysql'
        if (preg_match('/^nvarchar\(max\)$/i', $type)) return 'LONGTEXT';
        if (preg_match('/^nvarchar\((\d+)\)$/i', $type, $m)) return "VARCHAR({$m[1]})";
        if (preg_match('/^varbinary\(max\)$/i', $type)) return 'LONGBLOB';
        if (strtolower($type) === 'bit') return 'TINYINT(1)';
        if (strtolower($type) === 'uniqueidentifier') return 'CHAR(36)';
        if (strtolower($type) === 'money') return 'DECIMAL(19,4)';
        if (strtolower($type) === 'smallmoney') return 'DECIMAL(10,4)';
        if (strtolower($type) === 'datetime2') return 'DATETIME(6)';
        if (strtolower($type) === 'datetimeoffset') return 'VARCHAR(34)';

        return $type;
    }

    /**
     * Normaliza el valor por defecto para comparacion.
     */
    private function normalizeDefault(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim($value);

        // SQL Server envuelve defaults con parentesis (a veces multiples): ((0)), ('abc'), ((getdate()))
        // Quitar parentesis envolventes recursivamente mientras existan
        while (preg_match('/^\((.*)\)$/s', $value, $m)) {
            $value = trim($m[1]);
        }

        // Quitar prefijo N (Unicode literal): N'texto'
        $value = preg_replace("/^N'/", "'", $value);

        // Quitar comillas simples envolventes
        if (preg_match("/^'(.*)'$/s", $value, $m)) {
            $value = str_replace("''", "'", $m[1]);
        }

        // Funciones equivalentes: getdate() == current_timestamp
        $lower = strtolower($value);
        if (in_array($lower, ['getdate()', 'current_timestamp', 'now()', 'sysdatetime()'], true)) {
            return 'getdate()';
        }

        return $lower;
    }

    /**
     * Normaliza la definicion de una rutina para comparacion.
     * Elimina espacios en blanco excesivos y diferencias triviales.
     */
    private function normalizeDefinition(?string $def): string
    {
        if ($def === null) {
            return '';
        }

        // Eliminar BOM y caracteres especiales
        $def = preg_replace('/^\xEF\xBB\xBF/', '', $def);

        // Normalizar saltos de linea
        $def = str_replace(["\r\n", "\r"], "\n", $def);

        // Reducir espacios multiples a uno
        $def = preg_replace('/[ \t]+/', ' ', $def);

        // Eliminar lineas vacias multiples
        $def = preg_replace('/\n\s*\n/', "\n", $def);

        return trim($def);
    }

    /**
     * Entrecomilla un identificador segun el driver.
     */
    private function quoteIdentifier(string $name, string $driver): string
    {
        if ($driver === 'sqlsrv') {
            return '[' . str_replace(']', ']]', $name) . ']';
        }
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * Genera un comentario SQL segun el driver.
     */
    private function comment(string $text, string $driver): string
    {
        return "-- {$text}";
    }

    /**
     * Construye la clausula DEFAULT para una sentencia DDL.
     */
    private function buildDefaultClause(?string $value, string $driver): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        // SQL Server: limpiar parentesis envolventes del valor original
        // Ej: ((0)) -> 0, ('texto') -> 'texto', (getdate()) -> getdate()
        $clean = trim($value);
        if ($driver === 'sqlsrv') {
            // Quitar parentesis externos repetidamente
            while (preg_match('/^\((.*)\)$/s', $clean, $m)) {
                $clean = trim($m[1]);
            }
        }

        // Detectar y quitar comillas envolventes para inspeccionar el valor real
        $unquoted = $clean;
        $wasQuoted = false;
        if (preg_match("/^N?'(.*)'$/s", $clean, $m)) {
            $unquoted = str_replace("''", "'", $m[1]);
            $wasQuoted = true;
        }

        // Funciones conocidas no llevan comillas
        $functions = ['CURRENT_TIMESTAMP', 'NOW()', 'GETDATE()', 'GETUTCDATE()', 'SYSDATETIME()', 'NEWID()', 'NULL'];
        $upper = strtoupper($unquoted);
        if (!$wasQuoted && in_array($upper, $functions, true)) {
            return ' DEFAULT ' . $upper;
        }

        // Numeros (solo si no estaba entre comillas, o el valor es claramente numerico)
        if (!$wasQuoted && is_numeric($unquoted)) {
            return ' DEFAULT ' . $unquoted;
        }

        // Cadenas: con comillas simples y escape
        $escaped = str_replace("'", "''", $unquoted);
        return " DEFAULT '{$escaped}'";
    }

    /**
     * Construye una sentencia CREATE TABLE a partir de los metadatos de columnas.
     */
    private function buildCreateTable(string $table, array $columns, string $driver): string
    {
        $tbl = $this->quoteIdentifier($table, $driver);
        $lines = [];
        $primaryKeys = [];

        foreach ($columns as $col) {
            $colName  = $col['name'] ?? '';
            $colType  = $col['full_type'] ?? $col['data_type'] ?? 'VARCHAR(255)';
            $nullable = (strtoupper($col['nullable'] ?? 'YES') === 'YES') ? 'NULL' : 'NOT NULL';
            $extra    = strtolower(trim($col['extra'] ?? ''));
            $keyType  = strtoupper(trim($col['key_type'] ?? ''));
            $default  = $this->buildDefaultClause($col['default_value'] ?? null, $driver);

            $cn = $this->quoteIdentifier($colName, $driver);

            if ($driver === 'sqlsrv') {
                $colType = $this->mapTypeForDriver($colType, $driver);
                $identity = ($extra === 'auto_increment') ? ' IDENTITY(1,1)' : '';
                $lines[] = "    {$cn} {$colType}{$identity} {$nullable}{$default}";
            } else {
                $autoInc = ($extra === 'auto_increment') ? ' AUTO_INCREMENT' : '';
                $lines[] = "    {$cn} {$colType} {$nullable}{$default}{$autoInc}";
            }

            if ($keyType === 'PRI') {
                $primaryKeys[] = $cn;
            }
        }

        if (!empty($primaryKeys)) {
            $pkList = implode(', ', $primaryKeys);
            $lines[] = "    PRIMARY KEY ({$pkList})";
        }

        $colDef = implode(",\n", $lines);

        if ($driver === 'mysql') {
            return "CREATE TABLE IF NOT EXISTS {$tbl} (\n{$colDef}\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        }

        // SQL Server: protect against re-runs with OBJECT_ID check
        return "IF OBJECT_ID('{$table}', 'U') IS NULL\nCREATE TABLE {$tbl} (\n{$colDef}\n)";
    }

    /**
     * Retorna el codigo de tipo de objeto de SQL Server para OBJECT_ID().
     */
    private function objectTypeCode(string $objectType): string
    {
        return match (strtoupper($objectType)) {
            'VIEW'      => 'V',
            'PROCEDURE' => 'P',
            'FUNCTION'  => 'FN',
            default     => 'U',
        };
    }
}
