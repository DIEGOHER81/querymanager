<?php

namespace Controllers;

use Services\AuthService;

class AuthController
{
    public function login(): void
    {
        $data = getJsonBody();

        if (empty($data['username']) || empty($data['password'])) {
            errorResponse('Usuario y contraseña son requeridos', 422);
        }

        $user = AuthService::login($data['username'], $data['password']);
        if (!$user) {
            errorResponse('Credenciales inválidas', 401);
        }

        // Regenerate CSRF token on login
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        $mustChange = (bool)($user['must_change_password'] ?? false);

        successResponse([
            'user' => $user,
            'csrf_token' => $_SESSION['csrf_token'],
            'must_change_password' => $mustChange
        ], $mustChange ? 'Debe cambiar su contraseña' : 'Inicio de sesión exitoso');
    }

    public function logout(): void
    {
        AuthService::logout();
        successResponse(null, 'Sesión cerrada');
    }

    public function me(): void
    {
        $user = AuthService::getCurrentUser();
        if (!$user) {
            errorResponse('No autenticado', 401);
        }
        $user['must_change_password'] = $_SESSION['must_change_password'] ?? false;
        successResponse($user);
    }

    public function changePassword(): void
    {
        $data = getJsonBody();
        $user = AuthService::getCurrentUser();
        if (!$user) errorResponse('No autenticado', 401);

        if (empty($data['current_password']) || empty($data['new_password'])) {
            errorResponse('Contraseña actual y nueva son requeridas', 422);
        }
        if (strlen($data['new_password']) < 4) {
            errorResponse('La nueva contraseña debe tener al menos 4 caracteres', 422);
        }

        $ok = AuthService::changePassword($user['id'], $data['current_password'], $data['new_password']);
        if (!$ok) {
            errorResponse('La contraseña actual es incorrecta', 400);
        }
        successResponse(null, 'Contraseña actualizada');
    }

    // Admin: user management
    public function users(): void
    {
        $this->requireAdmin();
        successResponse(AuthService::getAll());
    }

    public function createUser(): void
    {
        $this->requireAdmin();
        $data = getJsonBody();

        if (empty($data['username']) || empty($data['password'])) {
            errorResponse('Usuario y contraseña son requeridos', 422);
        }

        try {
            $id = AuthService::create($data);
            successResponse(['id' => $id], 'Usuario creado');
        } catch (\Exception $e) {
            errorResponse('Error al crear usuario: ' . $e->getMessage(), 400);
        }
    }

    public function updateUser(int $id): void
    {
        $this->requireAdmin();
        AuthService::update($id, getJsonBody());
        successResponse(null, 'Usuario actualizado');
    }

    public function deleteUser(int $id): void
    {
        $this->requireAdmin();
        try {
            AuthService::delete($id);
            successResponse(null, 'Usuario eliminado');
        } catch (\RuntimeException $e) {
            errorResponse($e->getMessage(), 400);
        }
    }

    private function requireAdmin(): void
    {
        $user = AuthService::getCurrentUser();
        if (!$user || $user['role'] !== 'admin') {
            errorResponse('Se requieren permisos de administrador', 403);
        }
    }
}
