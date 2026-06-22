<?php

namespace Database;

class SQLiteManager
{
    private static ?\PDO $pdo = null;

    public static function init(): void
    {
        if (self::$pdo !== null) return;

        $dbFile = SQLITE_DB;
        $isNew = !file_exists($dbFile);

        self::$pdo = new \PDO('sqlite:' . $dbFile);
        self::$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        self::$pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        self::$pdo->exec('PRAGMA journal_mode=WAL');
        self::$pdo->exec('PRAGMA foreign_keys=ON');

        if ($isNew) {
            self::runMigrations();
        } else {
            self::checkMigrations();
        }
    }

    public static function getConnection(): \PDO
    {
        if (self::$pdo === null) {
            self::init();
        }
        return self::$pdo;
    }

    private static function runMigrations(): void
    {
        $pdo = self::$pdo;

        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_version (
            version INTEGER PRIMARY KEY,
            applied_at TEXT NOT NULL
        )");

        $migrations = self::getMigrations();
        $currentVersion = self::getCurrentVersion();

        foreach ($migrations as $version => $sql) {
            if ($version > $currentVersion) {
                $pdo->exec($sql);
                $stmt = $pdo->prepare("INSERT INTO schema_version (version, applied_at) VALUES (?, ?)");
                $stmt->execute([$version, date('c')]);
            }
        }
    }

    private static function checkMigrations(): void
    {
        try {
            self::$pdo->query("SELECT 1 FROM schema_version LIMIT 1");
            self::runMigrations();
        } catch (\PDOException $e) {
            self::runMigrations();
        }
    }

    private static function getCurrentVersion(): int
    {
        try {
            $stmt = self::$pdo->query("SELECT MAX(version) as v FROM schema_version");
            $row = $stmt->fetch();
            return (int)($row['v'] ?? 0);
        } catch (\PDOException $e) {
            return 0;
        }
    }

    private static function getMigrations(): array
    {
        return [
            1 => "
                CREATE TABLE connections (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    driver TEXT NOT NULL CHECK(driver IN ('mysql', 'sqlsrv')),
                    host TEXT NOT NULL,
                    port INTEGER,
                    database_name TEXT,
                    username TEXT NOT NULL,
                    password_encrypted TEXT NOT NULL,
                    charset TEXT DEFAULT 'utf8mb4',
                    sp_name TEXT DEFAULT NULL,
                    options_json TEXT DEFAULT '{}',
                    is_active INTEGER DEFAULT 1,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                );

                CREATE TABLE audit_logs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    connection_id INTEGER,
                    connection_name TEXT,
                    database_name TEXT,
                    query_text TEXT NOT NULL,
                    execution_mode TEXT NOT NULL CHECK(execution_mode IN ('direct', 'json_sp')),
                    execution_time_ms INTEGER,
                    row_count INTEGER DEFAULT 0,
                    status TEXT NOT NULL CHECK(status IN ('success', 'error')),
                    error_message TEXT,
                    user_ip TEXT,
                    executed_at TEXT NOT NULL,
                    FOREIGN KEY (connection_id) REFERENCES connections(id) ON DELETE SET NULL
                );

                CREATE INDEX idx_audit_executed_at ON audit_logs(executed_at);
                CREATE INDEX idx_audit_connection ON audit_logs(connection_id);
                CREATE INDEX idx_audit_status ON audit_logs(status);
            ",
            2 => "
                CREATE TABLE saved_queries (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    connection_id INTEGER,
                    name TEXT NOT NULL,
                    sql_text TEXT NOT NULL,
                    description TEXT,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL,
                    FOREIGN KEY (connection_id) REFERENCES connections(id) ON DELETE CASCADE
                );
            ",
            3 => "
                ALTER TABLE audit_logs ADD COLUMN is_favorite INTEGER DEFAULT 0;
                CREATE INDEX idx_audit_favorite ON audit_logs(is_favorite);
            ",
            4 => "
                CREATE TABLE users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    username TEXT NOT NULL UNIQUE,
                    password_hash TEXT NOT NULL,
                    full_name TEXT,
                    role TEXT NOT NULL DEFAULT 'user' CHECK(role IN ('admin', 'user')),
                    is_active INTEGER DEFAULT 1,
                    must_change_password INTEGER DEFAULT 0,
                    last_login TEXT,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                );
            ",
            5 => "
                -- Solo aplica si la tabla users fue creada antes de v4 sin esta columna.
                -- Si ya existe (creada en v4), se ignora el error.
                SELECT 1;
            ",
            6 => "
                -- Ampliar drivers permitidos para incluir PostgreSQL ('pgsql').
                -- SQLite no permite modificar un CHECK existente, asi que se recrea la tabla.
                PRAGMA foreign_keys=OFF;

                CREATE TABLE connections_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    driver TEXT NOT NULL CHECK(driver IN ('mysql', 'sqlsrv', 'pgsql')),
                    host TEXT NOT NULL,
                    port INTEGER,
                    database_name TEXT,
                    username TEXT NOT NULL,
                    password_encrypted TEXT NOT NULL,
                    charset TEXT DEFAULT 'utf8mb4',
                    sp_name TEXT DEFAULT NULL,
                    options_json TEXT DEFAULT '{}',
                    is_active INTEGER DEFAULT 1,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                );

                INSERT INTO connections_new
                    SELECT id, name, driver, host, port, database_name, username,
                           password_encrypted, charset, sp_name, options_json,
                           is_active, created_at, updated_at
                    FROM connections;

                DROP TABLE connections;
                ALTER TABLE connections_new RENAME TO connections;

                PRAGMA foreign_keys=ON;
            ",
            7 => "
                -- Ampliar los CHECK de audit_logs:
                --   execution_mode: agregar 'script' (ejecucion multi-sentencia / importacion)
                --   status: agregar 'partial' (script con 'Continuar ante errores' y fallos)
                -- SQLite no permite modificar un CHECK existente, asi que se recrea la tabla.
                PRAGMA foreign_keys=OFF;

                CREATE TABLE audit_logs_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    connection_id INTEGER,
                    connection_name TEXT,
                    database_name TEXT,
                    query_text TEXT NOT NULL,
                    execution_mode TEXT NOT NULL CHECK(execution_mode IN ('direct', 'json_sp', 'script')),
                    execution_time_ms INTEGER,
                    row_count INTEGER DEFAULT 0,
                    status TEXT NOT NULL CHECK(status IN ('success', 'error', 'partial')),
                    error_message TEXT,
                    user_ip TEXT,
                    executed_at TEXT NOT NULL,
                    is_favorite INTEGER DEFAULT 0,
                    FOREIGN KEY (connection_id) REFERENCES connections(id) ON DELETE SET NULL
                );

                INSERT INTO audit_logs_new
                    SELECT id, connection_id, connection_name, database_name, query_text,
                           execution_mode, execution_time_ms, row_count, status,
                           error_message, user_ip, executed_at, is_favorite
                    FROM audit_logs;

                DROP TABLE audit_logs;
                ALTER TABLE audit_logs_new RENAME TO audit_logs;

                CREATE INDEX idx_audit_executed_at ON audit_logs(executed_at);
                CREATE INDEX idx_audit_connection ON audit_logs(connection_id);
                CREATE INDEX idx_audit_status ON audit_logs(status);
                CREATE INDEX idx_audit_favorite ON audit_logs(is_favorite);

                PRAGMA foreign_keys=ON;
            "
        ];
    }
}
