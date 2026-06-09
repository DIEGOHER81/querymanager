<?php

namespace Services;

class SchemaService
{
    private \PDO $pdo;
    private string $driver;

    public function __construct(\PDO $pdo, string $driver)
    {
        $this->pdo = $pdo;
        $this->driver = $driver;
    }

    public function getDatabases(): array
    {
        if ($this->driver === 'mysql') {
            $stmt = $this->pdo->query("SHOW DATABASES");
            return array_column($stmt->fetchAll(), 'Database');
        }

        $stmt = $this->pdo->query("SELECT name FROM sys.databases WHERE state = 0 ORDER BY name");
        return array_column($stmt->fetchAll(), 'name');
    }

    public function getTables(?string $database = null): array
    {
        if ($this->driver === 'mysql') {
            $sql = "SELECT TABLE_NAME as name, TABLE_ROWS as row_count, TABLE_COMMENT as comment
                    FROM INFORMATION_SCHEMA.TABLES
                    WHERE TABLE_TYPE = 'BASE TABLE'";
            if ($database) {
                $sql .= " AND TABLE_SCHEMA = ?";
                $stmt = $this->pdo->prepare($sql . " ORDER BY TABLE_NAME");
                $stmt->execute([$database]);
            } else {
                $sql .= " AND TABLE_SCHEMA = DATABASE()";
                $stmt = $this->pdo->query($sql . " ORDER BY TABLE_NAME");
            }
            return $stmt->fetchAll();
        }

        // SQL Server
        $sql = "SELECT t.name,
                       SUM(p.rows) as row_count,
                       ISNULL(ep.value, '') as comment
                FROM sys.tables t
                LEFT JOIN sys.partitions p ON t.object_id = p.object_id AND p.index_id IN (0,1)
                LEFT JOIN sys.extended_properties ep ON ep.major_id = t.object_id AND ep.minor_id = 0 AND ep.name = 'MS_Description'
                GROUP BY t.name, ep.value
                ORDER BY t.name";
        return $this->pdo->query($sql)->fetchAll();
    }

    public function getViews(?string $database = null): array
    {
        if ($this->driver === 'mysql') {
            $sql = "SELECT TABLE_NAME as name
                    FROM INFORMATION_SCHEMA.VIEWS";
            if ($database) {
                $sql .= " WHERE TABLE_SCHEMA = ?";
                $stmt = $this->pdo->prepare($sql . " ORDER BY TABLE_NAME");
                $stmt->execute([$database]);
            } else {
                $sql .= " WHERE TABLE_SCHEMA = DATABASE()";
                $stmt = $this->pdo->query($sql . " ORDER BY TABLE_NAME");
            }
            return $stmt->fetchAll();
        }

        return $this->pdo->query("SELECT name FROM sys.views ORDER BY name")->fetchAll();
    }

    public function getProcedures(?string $database = null): array
    {
        if ($this->driver === 'mysql') {
            $sql = "SELECT ROUTINE_NAME as name, ROUTINE_COMMENT as comment
                    FROM INFORMATION_SCHEMA.ROUTINES
                    WHERE ROUTINE_TYPE = 'PROCEDURE'";
            if ($database) {
                $sql .= " AND ROUTINE_SCHEMA = ?";
                $stmt = $this->pdo->prepare($sql . " ORDER BY ROUTINE_NAME");
                $stmt->execute([$database]);
            } else {
                $sql .= " AND ROUTINE_SCHEMA = DATABASE()";
                $stmt = $this->pdo->query($sql . " ORDER BY ROUTINE_NAME");
            }
            return $stmt->fetchAll();
        }

        return $this->pdo->query("SELECT name, ISNULL(OBJECT_DEFINITION(object_id), '') as definition FROM sys.procedures ORDER BY name")->fetchAll();
    }

    public function getFunctions(?string $database = null): array
    {
        if ($this->driver === 'mysql') {
            $sql = "SELECT ROUTINE_NAME as name, ROUTINE_COMMENT as comment, DATA_TYPE as return_type
                    FROM INFORMATION_SCHEMA.ROUTINES
                    WHERE ROUTINE_TYPE = 'FUNCTION'";
            if ($database) {
                $sql .= " AND ROUTINE_SCHEMA = ?";
                $stmt = $this->pdo->prepare($sql . " ORDER BY ROUTINE_NAME");
                $stmt->execute([$database]);
            } else {
                $sql .= " AND ROUTINE_SCHEMA = DATABASE()";
                $stmt = $this->pdo->query($sql . " ORDER BY ROUTINE_NAME");
            }
            return $stmt->fetchAll();
        }

        $sql = "SELECT o.name, o.type_desc as type,
                       ISNULL(TYPE_NAME(c.system_type_id), '') as return_type
                FROM sys.objects o
                LEFT JOIN sys.parameters c ON o.object_id = c.object_id AND c.parameter_id = 0
                WHERE o.type IN ('FN', 'IF', 'TF', 'FS', 'FT')
                ORDER BY o.name";
        return $this->pdo->query($sql)->fetchAll();
    }

    public function getColumns(string $table, ?string $database = null): array
    {
        if ($this->driver === 'mysql') {
            $sql = "SELECT COLUMN_NAME as name,
                           DATA_TYPE as data_type,
                           COLUMN_TYPE as full_type,
                           IS_NULLABLE as nullable,
                           COLUMN_KEY as key_type,
                           COLUMN_DEFAULT as default_value,
                           EXTRA as extra,
                           COLUMN_COMMENT as comment
                    FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_NAME = ?";
            if ($database) {
                $sql .= " AND TABLE_SCHEMA = ?";
                $stmt = $this->pdo->prepare($sql . " ORDER BY ORDINAL_POSITION");
                $stmt->execute([$table, $database]);
            } else {
                $sql .= " AND TABLE_SCHEMA = DATABASE()";
                $stmt = $this->pdo->prepare($sql . " ORDER BY ORDINAL_POSITION");
                $stmt->execute([$table]);
            }
            return $stmt->fetchAll();
        }

        $sql = "SELECT c.name,
                       t.name as data_type,
                       CASE
                            WHEN c.max_length = -1 AND t.name IN ('varchar','nvarchar','char','nchar','varbinary') THEN t.name + '(MAX)'
                            WHEN t.name IN ('nvarchar','nchar') THEN t.name + '(' + CAST(c.max_length / 2 AS VARCHAR) + ')'
                            WHEN t.name IN ('varchar','char','varbinary','binary') THEN t.name + '(' + CAST(c.max_length AS VARCHAR) + ')'
                            WHEN t.name IN ('decimal','numeric') THEN t.name + '(' + CAST(c.precision AS VARCHAR) + ',' + CAST(c.scale AS VARCHAR) + ')'
                            ELSE t.name END as full_type,
                       CASE WHEN c.is_nullable = 1 THEN 'YES' ELSE 'NO' END as nullable,
                       CASE WHEN pk.column_id IS NOT NULL THEN 'PRI' ELSE '' END as key_type,
                       dc.definition as default_value,
                       CASE WHEN c.is_identity = 1 THEN 'auto_increment' ELSE '' END as extra
                FROM sys.columns c
                JOIN sys.types t ON c.user_type_id = t.user_type_id
                JOIN sys.tables tbl ON c.object_id = tbl.object_id
                LEFT JOIN (
                    SELECT ic.object_id, ic.column_id
                    FROM sys.index_columns ic
                    JOIN sys.indexes i ON ic.object_id = i.object_id AND ic.index_id = i.index_id
                    WHERE i.is_primary_key = 1
                ) pk ON c.object_id = pk.object_id AND c.column_id = pk.column_id
                LEFT JOIN sys.default_constraints dc ON c.default_object_id = dc.object_id
                WHERE tbl.name = ?
                ORDER BY c.column_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$table]);
        return $stmt->fetchAll();
    }

    /**
     * Get ALL columns from all tables in a single query (for performance).
     * Returns array indexed by table name (lowercase) with arrays of columns.
     */
    public function getAllColumns(?string $database = null): array
    {
        $result = [];

        if ($this->driver === 'mysql') {
            $sql = "SELECT TABLE_NAME as table_name,
                           COLUMN_NAME as name,
                           DATA_TYPE as data_type,
                           COLUMN_TYPE as full_type,
                           IS_NULLABLE as nullable,
                           COLUMN_KEY as key_type,
                           COLUMN_DEFAULT as default_value,
                           EXTRA as extra,
                           COLUMN_COMMENT as comment
                    FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = " . ($database ? '?' : 'DATABASE()') . "
                    ORDER BY TABLE_NAME, ORDINAL_POSITION";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($database ? [$database] : []);
        } else {
            $sql = "SELECT tbl.name as table_name,
                           c.name,
                           t.name as data_type,
                           CASE
                                WHEN c.max_length = -1 AND t.name IN ('varchar','nvarchar','char','nchar','varbinary') THEN t.name + '(MAX)'
                                WHEN t.name IN ('nvarchar','nchar') THEN t.name + '(' + CAST(c.max_length / 2 AS VARCHAR) + ')'
                                WHEN t.name IN ('varchar','char','varbinary','binary') THEN t.name + '(' + CAST(c.max_length AS VARCHAR) + ')'
                                WHEN t.name IN ('decimal','numeric') THEN t.name + '(' + CAST(c.precision AS VARCHAR) + ',' + CAST(c.scale AS VARCHAR) + ')'
                                ELSE t.name END as full_type,
                           CASE WHEN c.is_nullable = 1 THEN 'YES' ELSE 'NO' END as nullable,
                           CASE WHEN pk.column_id IS NOT NULL THEN 'PRI' ELSE '' END as key_type,
                           dc.definition as default_value,
                           CASE WHEN c.is_identity = 1 THEN 'auto_increment' ELSE '' END as extra
                    FROM sys.columns c
                    JOIN sys.types t ON c.user_type_id = t.user_type_id
                    JOIN sys.tables tbl ON c.object_id = tbl.object_id
                    LEFT JOIN (
                        SELECT ic.object_id, ic.column_id
                        FROM sys.index_columns ic
                        JOIN sys.indexes i ON ic.object_id = i.object_id AND ic.index_id = i.index_id
                        WHERE i.is_primary_key = 1
                    ) pk ON c.object_id = pk.object_id AND c.column_id = pk.column_id
                    LEFT JOIN sys.default_constraints dc ON c.default_object_id = dc.object_id
                    ORDER BY tbl.name, c.column_id";
            $stmt = $this->pdo->query($sql);
        }

        foreach ($stmt->fetchAll() as $row) {
            $tableName = $row['table_name'];
            unset($row['table_name']);
            $key = strtolower($tableName);
            if (!isset($result[$key])) $result[$key] = [];
            $result[$key][] = $row;
        }

        return $result;
    }

    /**
     * Get parameters of a stored procedure or function
     */
    public function getRoutineParams(string $routine, ?string $database = null): array
    {
        if ($this->driver === 'mysql') {
            $sql = "SELECT PARAMETER_NAME as name,
                           DATA_TYPE as data_type,
                           PARAMETER_MODE as mode,
                           CHARACTER_MAXIMUM_LENGTH as max_length,
                           ORDINAL_POSITION as position
                    FROM INFORMATION_SCHEMA.PARAMETERS
                    WHERE SPECIFIC_NAME = ?";
            if ($database) {
                $sql .= " AND SPECIFIC_SCHEMA = ?";
                $stmt = $this->pdo->prepare($sql . " ORDER BY ORDINAL_POSITION");
                $stmt->execute([$routine, $database]);
            } else {
                $sql .= " AND SPECIFIC_SCHEMA = DATABASE()";
                $stmt = $this->pdo->prepare($sql . " ORDER BY ORDINAL_POSITION");
                $stmt->execute([$routine]);
            }
            return $stmt->fetchAll();
        }

        // SQL Server
        $sql = "SELECT p.name,
                       TYPE_NAME(p.user_type_id) as data_type,
                       CASE WHEN p.is_output = 1 THEN 'OUT' ELSE 'IN' END as mode,
                       p.max_length,
                       p.parameter_id as position
                FROM sys.parameters p
                JOIN sys.objects o ON p.object_id = o.object_id
                WHERE o.name = ? AND p.parameter_id > 0
                ORDER BY p.parameter_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$routine]);
        return $stmt->fetchAll();
    }

    /**
     * Get the source code / definition of a stored procedure or function
     */
    public function getRoutineDefinition(string $routine, ?string $database = null): ?string
    {
        if ($this->driver === 'mysql') {
            $sql = "SELECT ROUTINE_DEFINITION as definition
                    FROM INFORMATION_SCHEMA.ROUTINES
                    WHERE SPECIFIC_NAME = ?";
            if ($database) {
                $sql .= " AND ROUTINE_SCHEMA = ?";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([$routine, $database]);
            } else {
                $sql .= " AND ROUTINE_SCHEMA = DATABASE()";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([$routine]);
            }
            $row = $stmt->fetch();
            return $row ? $row['definition'] : null;
        }

        // SQL Server
        $sql = "SELECT OBJECT_DEFINITION(OBJECT_ID(?)) as definition";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$routine]);
        $row = $stmt->fetch();
        return $row ? $row['definition'] : null;
    }
}
