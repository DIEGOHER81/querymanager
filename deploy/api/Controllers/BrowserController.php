<?php

namespace Controllers;

use Models\Connection;
use Services\DatabaseService;
use Services\SchemaService;

class BrowserController
{
    private function getSchemaService(int $connId): SchemaService
    {
        $config = Connection::getById($connId);
        if (!$config) {
            errorResponse('Conexión no encontrada', 404);
        }

        $database = $_GET['db'] ?? $config['database_name'] ?? null;
        $pdo = DatabaseService::connect($connId, $database);

        return new SchemaService($pdo, $config['driver']);
    }

    public function databases(int $connId): void
    {
        $service = $this->getSchemaService($connId);
        successResponse($service->getDatabases());
    }

    public function tables(int $connId): void
    {
        $database = $_GET['db'] ?? null;
        $service = $this->getSchemaService($connId);
        successResponse($service->getTables($database));
    }

    public function views(int $connId): void
    {
        $database = $_GET['db'] ?? null;
        $service = $this->getSchemaService($connId);
        successResponse($service->getViews($database));
    }

    public function procedures(int $connId): void
    {
        $database = $_GET['db'] ?? null;
        $service = $this->getSchemaService($connId);
        successResponse($service->getProcedures($database));
    }

    public function functions(int $connId): void
    {
        $database = $_GET['db'] ?? null;
        $service = $this->getSchemaService($connId);
        successResponse($service->getFunctions($database));
    }

    public function columns(int $connId, string $table): void
    {
        $database = $_GET['db'] ?? null;
        $service = $this->getSchemaService($connId);
        successResponse($service->getColumns(urldecode($table), $database));
    }

    public function routineParams(int $connId, string $routine): void
    {
        $database = $_GET['db'] ?? null;
        $service = $this->getSchemaService($connId);
        successResponse($service->getRoutineParams(urldecode($routine), $database));
    }

    public function routineDefinition(int $connId, string $routine): void
    {
        $database = $_GET['db'] ?? null;
        $service = $this->getSchemaService($connId);
        $definition = $service->getRoutineDefinition(urldecode($routine), $database);
        successResponse(['name' => urldecode($routine), 'definition' => $definition]);
    }
}
