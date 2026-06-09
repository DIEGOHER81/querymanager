<?php

namespace Controllers;

use Services\QueryExecutionService;

class QueryController
{
    public function execute(): void
    {
        $data = getJsonBody();

        if (empty($data['connection_id'])) {
            errorResponse('Debe seleccionar una conexión', 422);
        }
        if (empty($data['sql'])) {
            errorResponse('La consulta SQL es requerida', 422);
        }

        try {
            $result = QueryExecutionService::execute(
                (int)$data['connection_id'],
                trim($data['sql']),
                $data['database'] ?? null
            );
            successResponse($result, $result['message']);
        } catch (\RuntimeException $e) {
            errorResponse($e->getMessage(), 400);
        }
    }

    public function executeJson(): void
    {
        $data = getJsonBody();

        if (empty($data['connection_id'])) {
            errorResponse('Debe seleccionar una conexión', 422);
        }
        if (empty($data['sql'])) {
            errorResponse('La consulta SQL es requerida', 422);
        }

        try {
            $result = QueryExecutionService::executeViaJson(
                (int)$data['connection_id'],
                trim($data['sql']),
                $data['database'] ?? null,
                $data['params'] ?? []
            );
            successResponse($result, $result['message']);
        } catch (\RuntimeException $e) {
            errorResponse($e->getMessage(), 400);
        }
    }
}
