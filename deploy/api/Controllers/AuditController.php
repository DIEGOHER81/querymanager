<?php

namespace Controllers;

use Services\AuditService;

class AuditController
{
    public function index(): void
    {
        $page = (int)($_GET['page'] ?? 1);
        $perPage = min((int)($_GET['per_page'] ?? 50), 100);

        $filters = [
            'connection_id' => $_GET['connection_id'] ?? null,
            'status' => $_GET['status'] ?? null,
            'date_from' => $_GET['date_from'] ?? null,
            'date_to' => $_GET['date_to'] ?? null,
            'search' => $_GET['search'] ?? null,
        ];

        $result = AuditService::getAll($page, $perPage, $filters);
        successResponse($result);
    }

    public function stats(): void
    {
        $stats = AuditService::getStats();
        successResponse($stats);
    }

    public function clear(): void
    {
        $deleted = AuditService::clear();
        successResponse(['deleted' => $deleted], "Se eliminaron {$deleted} registros (las favoritas se conservaron)");
    }

    public function toggleFavorite(int $id): void
    {
        $isFav = AuditService::toggleFavorite($id);
        successResponse(['id' => $id, 'is_favorite' => $isFav], $isFav ? 'Marcada como favorita' : 'Desmarcada como favorita');
    }

    public function favorites(): void
    {
        $connId = isset($_GET['connection_id']) ? (int)$_GET['connection_id'] : null;
        $db = $_GET['database'] ?? null;
        $favs = AuditService::getFavorites($connId, $db);
        successResponse($favs);
    }
}
