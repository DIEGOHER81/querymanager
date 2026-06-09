<?php

namespace Services;

use Models\Connection;

class DatabaseService
{
    private static array $connections = [];

    /**
     * Get a PDO connection to a target database
     */
    public static function connect(int $connectionId, ?string $database = null): \PDO
    {
        $cacheKey = $connectionId . ':' . ($database ?? '_default');

        if (isset(self::$connections[$cacheKey])) {
            return self::$connections[$cacheKey];
        }

        $config = Connection::getById($connectionId);
        if (!$config) {
            throw new \RuntimeException('Conexión no encontrada con ID: ' . $connectionId);
        }

        $password = EncryptionService::decrypt($config['password_encrypted']);
        $db = $database ?? $config['database_name'];

        $pdo = self::createPdo($config['driver'], $config['host'], $config['port'], $db, $config['username'], $password, $config['charset']);

        self::$connections[$cacheKey] = $pdo;
        return $pdo;
    }

    /**
     * Test a connection with given parameters (without saving)
     */
    public static function testConnection(string $driver, string $host, int $port, ?string $database, string $username, string $password, string $charset = 'utf8mb4'): array
    {
        try {
            $pdo = self::createPdo($driver, $host, $port, $database, $username, $password, $charset);

            // Get server version
            $version = $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
            $serverInfo = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

            return [
                'success' => true,
                'message' => 'Conexión exitosa'
            ];
        } catch (\PDOException $e) {
            return [
                'success' => false,
                'message' => 'Error de conexión: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get available database drivers on this server.
     */
    public static function getAvailableDrivers(): array
    {
        return \PDO::getAvailableDrivers();
    }

    private static function createPdo(string $driver, string $host, ?int $port, ?string $database, string $username, string $password, string $charset = 'utf8mb4'): \PDO
    {
        // Validate driver is available before attempting connection
        $available = \PDO::getAvailableDrivers();
        if ($driver === 'mysql' && !in_array('mysql', $available)) {
            throw new \RuntimeException('El driver PDO MySQL no esta disponible en este servidor. Extensiones disponibles: ' . implode(', ', $available));
        }
        if ($driver === 'sqlsrv' && !in_array('sqlsrv', $available)) {
            throw new \RuntimeException('El driver PDO SQL Server no esta disponible en este servidor (requiere pdo_sqlsrv + Microsoft ODBC Driver). Este hosting solo soporta conexiones MySQL. Extensiones disponibles: ' . implode(', ', $available));
        }

        $dsn = self::buildDsn($driver, $host, $port, $database, $charset);

        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];

        // ATTR_TIMEOUT not supported by sqlsrv; use LoginTimeout in DSN instead
        if ($driver === 'mysql') {
            $options[\PDO::ATTR_TIMEOUT] = DEFAULT_QUERY_TIMEOUT;
        }

        return new \PDO($dsn, $username, $password, $options);
    }

    private static function buildDsn(string $driver, string $host, ?int $port, ?string $database, string $charset): string
    {
        if ($driver === 'mysql') {
            $dsn = "mysql:host={$host}";
            if ($port) $dsn .= ";port={$port}";
            if ($database) $dsn .= ";dbname={$database}";
            $dsn .= ";charset={$charset}";
            return $dsn;
        }

        if ($driver === 'sqlsrv') {
            $dsn = "sqlsrv:Server={$host}";
            if ($port) $dsn .= ",{$port}";
            if ($database) $dsn .= ";Database={$database}";
            $dsn .= ";LoginTimeout=" . DEFAULT_QUERY_TIMEOUT;
            $dsn .= ";TrustServerCertificate=1";
            return $dsn;
        }

        throw new \RuntimeException("Driver no soportado: {$driver}");
    }
}
