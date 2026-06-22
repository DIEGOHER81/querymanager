<?php

namespace Controllers;

use Services\BackupService;

class BackupController
{
    /**
     * Genera y transmite un backup .sql como descarga.
     * Body: { connection_id, databases: string[], structure: bool, data: bool, drop: bool }
     */
    public function generate(): void
    {
        $data = getJsonBody();

        if (empty($data['connection_id'])) {
            errorResponse('Debe seleccionar una conexión', 422);
        }

        $databases = $data['databases'] ?? [];
        if (is_string($databases)) {
            $databases = [$databases];
        }
        $databases = array_values(array_filter(array_map('strval', (array)$databases), fn($d) => $d !== ''));
        if (empty($databases)) {
            errorResponse('Debe seleccionar al menos una base de datos', 422);
        }

        $options = [
            'structure' => !empty($data['structure']),
            'data'      => !empty($data['data']),
            'drop'      => !empty($data['drop']),
        ];
        if (!$options['structure'] && !$options['data']) {
            errorResponse('Seleccione estructura, datos, o ambos', 422);
        }

        try {
            // BackupService::stream valida, envía cabeceras y termina el proceso.
            BackupService::stream((int)$data['connection_id'], $databases, $options);
        } catch (\RuntimeException $e) {
            // Solo es seguro responder JSON si aún no se enviaron cabeceras del archivo
            if (!headers_sent()) {
                errorResponse($e->getMessage(), 400);
            }
            throw $e;
        }
    }
}
