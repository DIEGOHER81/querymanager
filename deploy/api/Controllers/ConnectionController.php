<?php

namespace Controllers;

use Models\Connection;
use Services\DatabaseService;
use Services\EncryptionService;

class ConnectionController
{
    public function index(): void
    {
        $connections = Connection::getAll();
        successResponse($connections);
    }

    public function show(int $id): void
    {
        $conn = Connection::getById($id);
        if (!$conn) {
            errorResponse('Conexión no encontrada', 404);
        }
        unset($conn['password_encrypted']);
        successResponse($conn);
    }

    public function store(): void
    {
        $data = getJsonBody();

        $errors = $this->validate($data);
        if (!empty($errors)) {
            errorResponse('Datos inválidos: ' . implode(', ', $errors), 422);
        }

        $id = Connection::create($data);
        $conn = Connection::getById($id);
        unset($conn['password_encrypted']);

        successResponse($conn, 'Conexión creada exitosamente');
    }

    public function update(int $id): void
    {
        $data = getJsonBody();

        $existing = Connection::getById($id);
        if (!$existing) {
            errorResponse('Conexión no encontrada', 404);
        }

        Connection::update($id, $data);
        $conn = Connection::getById($id);
        unset($conn['password_encrypted']);

        successResponse($conn, 'Conexión actualizada exitosamente');
    }

    public function destroy(int $id): void
    {
        $existing = Connection::getById($id);
        if (!$existing) {
            errorResponse('Conexión no encontrada', 404);
        }

        Connection::delete($id);
        successResponse(null, 'Conexión eliminada exitosamente');
    }

    public function test(int $id): void
    {
        $conn = Connection::getById($id);
        if (!$conn) {
            errorResponse('Conexión no encontrada', 404);
        }

        $password = EncryptionService::decrypt($conn['password_encrypted']);
        $result = DatabaseService::testConnection(
            $conn['driver'],
            $conn['host'],
            (int)$conn['port'],
            $conn['database_name'],
            $conn['username'],
            $password,
            $conn['charset'] ?? 'utf8mb4'
        );

        if ($result['success']) {
            successResponse($result, 'Conexión exitosa');
        } else {
            errorResponse($result['message'], 400);
        }
    }

    private function validate(array $data): array
    {
        $errors = [];
        if (empty($data['name'])) $errors[] = 'Nombre es requerido';
        if (empty($data['driver']) || !in_array($data['driver'], ['mysql', 'sqlsrv'])) {
            $errors[] = 'Driver debe ser mysql o sqlsrv';
        }
        if (empty($data['host'])) $errors[] = 'Host es requerido';
        if (empty($data['username'])) $errors[] = 'Usuario es requerido';
        return $errors;
    }
}
