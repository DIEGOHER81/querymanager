<?php

namespace Services;

use Models\Connection;

/**
 * Genera backups (.sql) de bases de datos en PHP puro, vía PDO.
 *
 * Soporta MySQL, PostgreSQL y SQL Server. El volcado se transmite directamente
 * a la salida (php://output) para no acumular todo en memoria.
 *
 * Para MySQL se usan las sentencias nativas SHOW CREATE (máxima fidelidad y
 * rutinas envueltas en DELIMITER, compatibles con el importador del editor).
 * Para PostgreSQL y SQL Server la estructura se reconstruye desde el catálogo
 * (tablas, columnas, PK, vistas y rutinas best-effort).
 */
class BackupService
{
    private const INSERT_BATCH = 200; // filas por sentencia INSERT

    /**
     * Transmite un backup .sql como descarga. No retorna: termina el proceso.
     *
     * @param int      $connectionId
     * @param string[] $databases   Bases de datos a incluir (al menos una)
     * @param array    $options     ['structure'=>bool, 'data'=>bool, 'drop'=>bool]
     */
    public static function stream(int $connectionId, array $databases, array $options): void
    {
        $config = Connection::getById($connectionId);
        if (!$config) {
            throw new \RuntimeException('Conexión no encontrada');
        }
        if (empty($databases)) {
            throw new \RuntimeException('Debe seleccionar al menos una base de datos');
        }

        $structure = $options['structure'] ?? true;
        $data = $options['data'] ?? true;
        $drop = $options['drop'] ?? false;
        if (!$structure && !$data) {
            throw new \RuntimeException('Seleccione estructura, datos, o ambos');
        }

        $driver = $config['driver'];
        @set_time_limit(0);

        $filename = self::buildFilename($config['name'], $databases);

        if (function_exists('ob_get_level')) {
            while (ob_get_level() > 0) { ob_end_clean(); }
        }
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: public');

        $w = function (string $s) { echo $s; };

        self::writeHeader($w, $config, $databases, $structure, $data);

        foreach ($databases as $database) {
            $pdo = DatabaseService::connect($connectionId, $database);
            $schema = new SchemaService($pdo, $driver);
            self::dumpDatabase($w, $pdo, $schema, $driver, $database, $structure, $data, $drop);
        }

        self::writeFooter($w, $driver);

        // Auditoría (no bloquea la descarga si falla)
        try {
            AuditService::log([
                'connection_id' => $connectionId,
                'connection_name' => $config['name'],
                'database_name' => implode(', ', $databases),
                'query_text' => 'BACKUP [' . ($structure ? 'estructura' : '') . ($structure && $data ? '+' : '') . ($data ? 'datos' : '') . ']: ' . implode(', ', $databases),
                'execution_mode' => 'script',
                'execution_time_ms' => 0,
                'row_count' => 0,
                'status' => 'success'
            ]);
        } catch (\Throwable $e) { /* ignore */ }

        exit;
    }

    // ---------------------------------------------------------------------
    // Volcado por base de datos
    // ---------------------------------------------------------------------

    private static function dumpDatabase(callable $w, \PDO $pdo, SchemaService $schema, string $driver, string $database, bool $structure, bool $data, bool $drop): void
    {
        $w("\n-- ============================================================\n");
        $w("-- Base de datos: {$database}\n");
        $w("-- ============================================================\n\n");

        if ($driver === 'mysql') {
            $w("SET FOREIGN_KEY_CHECKS=0;\n");
            $w("SET NAMES utf8mb4;\n");
            $w("CREATE DATABASE IF NOT EXISTS " . self::qid($driver, $database) . " /*!40100 DEFAULT CHARACTER SET utf8mb4 */;\n");
            $w("USE " . self::qid($driver, $database) . ";\n\n");
        } elseif ($driver === 'sqlsrv') {
            $w("USE " . self::qid($driver, $database) . ";\nGO\n\n");
        }

        $tables = self::asNames($schema->getTables($database));

        // Estructura + datos por tabla
        foreach ($tables as $table) {
            if ($structure) {
                self::dumpTableStructure($w, $pdo, $schema, $driver, $database, $table, $drop);
            }
            if ($data) {
                self::dumpTableData($w, $pdo, $schema, $driver, $database, $table);
            }
        }

        // Claves foráneas al final (tras los datos) para no violar restricciones.
        // En MySQL ya vienen embebidas en SHOW CREATE TABLE.
        if ($structure && ($driver === 'pgsql' || $driver === 'sqlsrv')) {
            self::dumpForeignKeys($w, $pdo, $driver, $tables);
        }

        if ($structure) {
            self::dumpViews($w, $pdo, $schema, $driver, $database);
            self::dumpRoutines($w, $pdo, $schema, $driver, $database);
        }

        if ($driver === 'mysql') {
            $w("\nSET FOREIGN_KEY_CHECKS=1;\n");
        }
    }

    // ---------------------------------------------------------------------
    // Estructura de tabla
    // ---------------------------------------------------------------------

    private static function dumpTableStructure(callable $w, \PDO $pdo, SchemaService $schema, string $driver, string $database, string $table, bool $drop): void
    {
        $w("-- ----------------------------\n");
        $w("-- Estructura de tabla: {$table}\n");
        $w("-- ----------------------------\n");

        $qtable = self::qid($driver, $table);

        if ($drop) {
            if ($driver === 'sqlsrv') {
                $w("IF OBJECT_ID('{$table}', 'U') IS NOT NULL DROP TABLE {$qtable};\nGO\n");
            } else {
                $w("DROP TABLE IF EXISTS {$qtable};\n");
            }
        }

        if ($driver === 'mysql') {
            $row = $pdo->query("SHOW CREATE TABLE {$qtable}")->fetch(\PDO::FETCH_ASSOC);
            $ddl = $row['Create Table'] ?? '';
            $w($ddl . ";\n\n");
            return;
        }

        // PostgreSQL / SQL Server: reconstrucción desde el catálogo
        $columns = $schema->getColumns($table, $database);
        $w(self::buildCreateTable($driver, $table, $columns) . "\n");
        if ($driver === 'sqlsrv') $w("GO\n");
        $w("\n");

        // Índices secundarios (no-PK) tras la tabla
        self::dumpIndexes($w, $pdo, $driver, $table);
    }

    private static function buildCreateTable(string $driver, string $table, array $columns): string
    {
        $qtable = self::qid($driver, $table);
        $lines = [];
        $pk = [];

        foreach ($columns as $col) {
            $name = self::qid($driver, $col['name']);
            $type = $col['full_type'] ?: $col['data_type'];
            $def = "    {$name} {$type}";

            $isIdentity = (strpos((string)($col['extra'] ?? ''), 'auto_increment') !== false);
            if ($isIdentity) {
                if ($driver === 'sqlsrv') {
                    $def .= " IDENTITY(1,1)";
                } elseif ($driver === 'pgsql') {
                    // serial: el tipo y el default nextval lo cubren; se deja default abajo
                }
            }

            $nullable = strtoupper((string)$col['nullable']) === 'YES';
            $def .= $nullable ? " NULL" : " NOT NULL";

            $default = $col['default_value'] ?? null;
            if ($default !== null && $default !== '' && !($driver === 'sqlsrv' && $isIdentity)) {
                // En SQL Server los defaults vienen como '(expr)'
                $def .= " DEFAULT " . ($driver === 'sqlsrv' ? $default : $default);
            }

            $lines[] = $def;
            if (($col['key_type'] ?? '') === 'PRI') {
                $pk[] = self::qid($driver, $col['name']);
            }
        }

        if (!empty($pk)) {
            $lines[] = "    PRIMARY KEY (" . implode(', ', $pk) . ")";
        }

        $body = implode(",\n", $lines);

        if ($driver === 'sqlsrv') {
            return "CREATE TABLE {$qtable} (\n{$body}\n);";
        }
        // pgsql
        return "CREATE TABLE {$qtable} (\n{$body}\n);";
    }

    // ---------------------------------------------------------------------
    // Datos de tabla
    // ---------------------------------------------------------------------

    private static function dumpTableData(callable $w, \PDO $pdo, SchemaService $schema, string $driver, string $database, string $table): void
    {
        $qtable = self::qid($driver, $table);

        // Tipos por columna para decidir si se citan los valores
        $columns = $schema->getColumns($table, $database);
        if (empty($columns)) return;

        $numeric = [];
        $colNames = [];
        $hasIdentity = false;
        foreach ($columns as $col) {
            $colNames[] = $col['name'];
            $numeric[$col['name']] = self::isNumericType((string)$col['data_type']);
            if (strpos((string)($col['extra'] ?? ''), 'auto_increment') !== false) {
                $hasIdentity = true;
            }
        }

        $stmt = $pdo->query("SELECT * FROM {$qtable}");
        $colList = implode(', ', array_map(fn($c) => self::qid($driver, $c), $colNames));

        $identityOn = ($driver === 'sqlsrv' && $hasIdentity);
        $started = false;
        $batch = [];
        $count = 0;

        $flush = function () use (&$batch, &$started, $w, $driver, $qtable, $colList, $identityOn) {
            if (empty($batch)) return;
            if (!$started) {
                $w("-- ----------------------------\n");
                $w("-- Datos de tabla\n");
                $w("-- ----------------------------\n");
                if ($identityOn) $w("SET IDENTITY_INSERT {$qtable} ON;\n");
                $started = true;
            }
            $w("INSERT INTO {$qtable} ({$colList}) VALUES\n");
            $w(implode(",\n", $batch) . ";\n");
            $batch = [];
        };

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $vals = [];
            foreach ($colNames as $c) {
                $vals[] = self::quoteValue($pdo, $row[$c] ?? null, $numeric[$c]);
            }
            $batch[] = '(' . implode(', ', $vals) . ')';
            if (++$count % self::INSERT_BATCH === 0) {
                $flush();
            }
        }
        $flush();

        if ($started) {
            if ($identityOn) $w("SET IDENTITY_INSERT {$qtable} OFF;\n");
            if ($driver === 'sqlsrv') $w("GO\n");
            $w("\n");
        }
    }

    // ---------------------------------------------------------------------
    // Vistas y rutinas
    // ---------------------------------------------------------------------

    private static function dumpViews(callable $w, \PDO $pdo, SchemaService $schema, string $driver, string $database): void
    {
        $views = self::asNames($schema->getViews($database));
        if (empty($views)) return;

        $w("\n-- ----------------------------\n-- Vistas\n-- ----------------------------\n");

        foreach ($views as $view) {
            $qview = self::qid($driver, $view);
            if ($driver === 'mysql') {
                $row = $pdo->query("SHOW CREATE VIEW {$qview}")->fetch(\PDO::FETCH_ASSOC);
                if (!empty($row['Create View'])) {
                    $w("DROP VIEW IF EXISTS {$qview};\n");
                    $w($row['Create View'] . ";\n\n");
                }
            } else {
                // information_schema.views.view_definition
                $stmt = $pdo->prepare("SELECT view_definition FROM information_schema.views WHERE table_name = ?");
                $stmt->execute([$view]);
                $def = $stmt->fetchColumn();
                if ($def) {
                    $w("CREATE VIEW {$qview} AS\n{$def}" . ($driver === 'sqlsrv' ? "\nGO\n\n" : ";\n\n"));
                }
            }
        }
    }

    private static function dumpRoutines(callable $w, \PDO $pdo, SchemaService $schema, string $driver, string $database): void
    {
        if ($driver === 'mysql') {
            self::dumpMysqlRoutines($w, $pdo, $schema, $database, 'PROCEDURE');
            self::dumpMysqlRoutines($w, $pdo, $schema, $database, 'FUNCTION');
            return;
        }

        // PostgreSQL / SQL Server: definición desde el catálogo (best-effort)
        $routines = array_merge(
            self::asNames($schema->getProcedures($database)),
            self::asNames($schema->getFunctions($database))
        );
        if (empty($routines)) return;

        $w("\n-- ----------------------------\n-- Rutinas (procedimientos y funciones)\n-- ----------------------------\n");
        foreach ($routines as $routine) {
            $def = $schema->getRoutineDefinition($routine, $database);
            if ($def) {
                $w($def . ($driver === 'sqlsrv' ? "\nGO\n\n" : ";\n\n"));
            }
        }
    }

    private static function dumpMysqlRoutines(callable $w, \PDO $pdo, SchemaService $schema, string $database, string $type): void
    {
        $list = $type === 'PROCEDURE'
            ? self::asNames($schema->getProcedures($database))
            : self::asNames($schema->getFunctions($database));
        if (empty($list)) return;

        $label = $type === 'PROCEDURE' ? 'Procedimientos' : 'Funciones';
        $w("\n-- ----------------------------\n-- {$label}\n-- ----------------------------\n");

        foreach ($list as $name) {
            $qname = self::qid('mysql', $name);
            try {
                $row = $pdo->query("SHOW CREATE {$type} {$qname}")->fetch(\PDO::FETCH_ASSOC);
            } catch (\PDOException $e) {
                continue;
            }
            $key = 'Create ' . ucfirst(strtolower($type)); // 'Create Procedure' / 'Create Function'
            $create = $row[$key] ?? '';
            if ($create === '') continue;

            $w("DROP {$type} IF EXISTS {$qname};\n");
            $w("DELIMITER ;;\n");
            $w($create . ";;\n");
            $w("DELIMITER ;\n\n");
        }
    }

    // ---------------------------------------------------------------------
    // Índices secundarios (PostgreSQL / SQL Server)
    // ---------------------------------------------------------------------

    private static function dumpIndexes(callable $w, \PDO $pdo, string $driver, string $table): void
    {
        if ($driver === 'pgsql') {
            // pg_get_indexdef devuelve la sentencia CREATE INDEX completa; se omiten los PK.
            $sql = "SELECT pg_get_indexdef(i.indexrelid) AS def
                    FROM pg_index i
                    JOIN pg_class c ON c.oid = i.indexrelid
                    JOIN pg_class t ON t.oid = i.indrelid
                    JOIN pg_namespace n ON n.oid = t.relnamespace
                    WHERE n.nspname = 'public' AND t.relname = ? AND NOT i.indisprimary
                    ORDER BY c.relname";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$table]);
            $defs = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            if (empty($defs)) return;

            $w("-- Índices de {$table}\n");
            foreach ($defs as $def) {
                $w($def . ";\n");
            }
            $w("\n");
            return;
        }

        if ($driver === 'sqlsrv') {
            // Índices no-PK y no respaldados por constraint UNIQUE; se reconstruyen.
            $sql = "SELECT i.name AS index_name, i.is_unique, i.type_desc,
                           c.name AS column_name, ic.is_descending_key
                    FROM sys.indexes i
                    JOIN sys.index_columns ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id
                    JOIN sys.columns c ON ic.object_id = c.object_id AND ic.column_id = c.column_id
                    JOIN sys.tables t ON i.object_id = t.object_id
                    WHERE t.name = ? AND i.is_primary_key = 0 AND i.is_unique_constraint = 0
                      AND i.type IN (1, 2) AND ic.is_included_column = 0
                    ORDER BY i.name, ic.key_ordinal";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$table]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if (empty($rows)) return;

            // Agrupar columnas por índice
            $indexes = [];
            foreach ($rows as $r) {
                $name = $r['index_name'];
                if (!isset($indexes[$name])) {
                    $indexes[$name] = [
                        'unique' => (int)$r['is_unique'] === 1,
                        'clustered' => stripos($r['type_desc'], 'CLUSTERED') !== false && stripos($r['type_desc'], 'NONCLUSTERED') === false,
                        'cols' => [],
                    ];
                }
                $indexes[$name]['cols'][] = self::qid('sqlsrv', $r['column_name'])
                    . ((int)$r['is_descending_key'] === 1 ? ' DESC' : '');
            }

            $w("-- Índices de {$table}\n");
            $qtable = self::qid('sqlsrv', $table);
            foreach ($indexes as $name => $idx) {
                $unique = $idx['unique'] ? 'UNIQUE ' : '';
                $clustered = $idx['clustered'] ? 'CLUSTERED ' : 'NONCLUSTERED ';
                $cols = implode(', ', $idx['cols']);
                $w("CREATE {$unique}{$clustered}INDEX " . self::qid('sqlsrv', $name) . " ON {$qtable} ({$cols});\nGO\n");
            }
            $w("\n");
        }
    }

    // ---------------------------------------------------------------------
    // Claves foráneas (PostgreSQL / SQL Server)
    // ---------------------------------------------------------------------

    private static function dumpForeignKeys(callable $w, \PDO $pdo, string $driver, array $tables): void
    {
        if (empty($tables)) return;

        $emitted = false;

        if ($driver === 'pgsql') {
            $sql = "SELECT con.conname AS name, pg_get_constraintdef(con.oid) AS def
                    FROM pg_constraint con
                    JOIN pg_class rel ON rel.oid = con.conrelid
                    JOIN pg_namespace n ON n.oid = rel.relnamespace
                    WHERE con.contype = 'f' AND n.nspname = 'public' AND rel.relname = ?
                    ORDER BY con.conname";
            foreach ($tables as $table) {
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$table]);
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    if (!$emitted) { $w("\n-- ----------------------------\n-- Claves foráneas\n-- ----------------------------\n"); $emitted = true; }
                    $w("ALTER TABLE " . self::qid('pgsql', $table)
                        . " ADD CONSTRAINT " . self::qid('pgsql', $r['name'])
                        . " " . $r['def'] . ";\n");
                }
            }
            if ($emitted) $w("\n");
            return;
        }

        if ($driver === 'sqlsrv') {
            $sql = "SELECT fk.name AS fk_name,
                           cpar.name AS parent_col,
                           rt.name AS ref_table,
                           cref.name AS ref_col,
                           fk.delete_referential_action_desc AS on_delete,
                           fk.update_referential_action_desc AS on_update
                    FROM sys.foreign_keys fk
                    JOIN sys.foreign_key_columns fkc ON fk.object_id = fkc.constraint_object_id
                    JOIN sys.tables pt ON fk.parent_object_id = pt.object_id
                    JOIN sys.columns cpar ON fkc.parent_object_id = cpar.object_id AND fkc.parent_column_id = cpar.column_id
                    JOIN sys.tables rt ON fk.referenced_object_id = rt.object_id
                    JOIN sys.columns cref ON fkc.referenced_object_id = cref.object_id AND fkc.referenced_column_id = cref.column_id
                    WHERE pt.name = ?
                    ORDER BY fk.name, fkc.constraint_column_id";
            foreach ($tables as $table) {
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$table]);
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                if (empty($rows)) continue;

                // Agrupar columnas por FK
                $fks = [];
                foreach ($rows as $r) {
                    $name = $r['fk_name'];
                    if (!isset($fks[$name])) {
                        $fks[$name] = [
                            'ref_table' => $r['ref_table'],
                            'cols' => [], 'ref_cols' => [],
                            'on_delete' => $r['on_delete'], 'on_update' => $r['on_update'],
                        ];
                    }
                    $fks[$name]['cols'][] = self::qid('sqlsrv', $r['parent_col']);
                    $fks[$name]['ref_cols'][] = self::qid('sqlsrv', $r['ref_col']);
                }

                foreach ($fks as $name => $fk) {
                    if (!$emitted) { $w("\n-- ----------------------------\n-- Claves foráneas\n-- ----------------------------\n"); $emitted = true; }
                    $stmtSql = "ALTER TABLE " . self::qid('sqlsrv', $table)
                        . " ADD CONSTRAINT " . self::qid('sqlsrv', $name)
                        . " FOREIGN KEY (" . implode(', ', $fk['cols']) . ")"
                        . " REFERENCES " . self::qid('sqlsrv', $fk['ref_table'])
                        . " (" . implode(', ', $fk['ref_cols']) . ")";
                    $stmtSql .= self::sqlsrvRefAction('ON DELETE', $fk['on_delete']);
                    $stmtSql .= self::sqlsrvRefAction('ON UPDATE', $fk['on_update']);
                    $w($stmtSql . ";\nGO\n");
                }
            }
            if ($emitted) $w("\n");
        }
    }

    /** Traduce la acción referencial de SQL Server (NO_ACTION se omite). */
    private static function sqlsrvRefAction(string $clause, ?string $action): string
    {
        $a = strtoupper(str_replace('_', ' ', (string)$action));
        if ($a === '' || $a === 'NO ACTION') return '';
        return " {$clause} {$a}";
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private static function writeHeader(callable $w, array $config, array $databases, bool $structure, bool $data): void
    {
        $content = [];
        if ($structure) $content[] = 'estructura';
        if ($data) $content[] = 'datos';

        $w("-- ============================================================\n");
        $w("-- Backup generado por PHPAdmin\n");
        $w("-- Conexión: {$config['name']} ({$config['driver']})\n");
        $w("-- Bases de datos: " . implode(', ', $databases) . "\n");
        $w("-- Contenido: " . implode(' + ', $content) . "\n");
        $w("-- Fecha: " . date('Y-m-d H:i:s') . "\n");
        $w("-- ============================================================\n");
    }

    private static function writeFooter(callable $w, string $driver): void
    {
        $w("\n-- Fin del backup\n");
    }

    /** Normaliza filas [{name:...}] o strings a un array plano de nombres. */
    private static function asNames(array $rows): array
    {
        $names = [];
        foreach ($rows as $r) {
            if (is_array($r)) {
                $names[] = $r['name'] ?? reset($r);
            } else {
                $names[] = $r;
            }
        }
        return $names;
    }

    /** Cita un identificador según el motor. */
    private static function qid(string $driver, string $name): string
    {
        if ($driver === 'mysql') return '`' . str_replace('`', '``', $name) . '`';
        if ($driver === 'pgsql') return '"' . str_replace('"', '""', $name) . '"';
        return '[' . str_replace(']', ']]', $name) . ']'; // sqlsrv
    }

    /** Cita un valor para INSERT; los numéricos no se entrecomillan. */
    private static function quoteValue(\PDO $pdo, $value, bool $isNumeric): string
    {
        if ($value === null) return 'NULL';
        if ($isNumeric && is_numeric($value)) return (string)$value;

        $q = false;
        try {
            $q = $pdo->quote((string)$value);
        } catch (\Throwable $e) {
            $q = false;
        }
        if ($q === false) {
            // Algunos drivers (sqlsrv) no implementan quote(): escape manual
            $q = "'" . str_replace("'", "''", (string)$value) . "'";
        }
        return $q;
    }

    private static function isNumericType(string $dataType): bool
    {
        $t = strtolower(trim($dataType));
        static $numeric = [
            'int', 'integer', 'tinyint', 'smallint', 'mediumint', 'bigint',
            'decimal', 'numeric', 'float', 'double', 'double precision', 'real',
            'bit', 'money', 'smallmoney', 'year',
        ];
        return in_array($t, $numeric, true);
    }

    private static function buildFilename(string $connName, array $databases): string
    {
        $base = count($databases) === 1 ? $databases[0] : $connName;
        $base = preg_replace('/[^A-Za-z0-9_\-]/', '_', $base);
        return 'backup_' . $base . '_' . date('Y-m-d_His') . '.sql';
    }
}
