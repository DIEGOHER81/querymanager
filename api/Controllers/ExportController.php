<?php

namespace Controllers;

use Services\QueryExecutionService;

class ExportController
{
    public function csv(): void
    {
        $data = getJsonBody();
        $result = $this->executeQuery($data);

        $filename = 'export_' . date('Y-m-d_His') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');

        // BOM for Excel UTF-8 compatibility
        fwrite($output, "\xEF\xBB\xBF");

        // Headers
        if (!empty($result['columns'])) {
            fputcsv($output, $result['columns'], ';');
        }

        // Data
        foreach ($result['rows'] as $row) {
            fputcsv($output, array_values($row), ';');
        }

        fclose($output);
        exit;
    }

    public function excel(): void
    {
        $data = getJsonBody();
        $result = $this->executeQuery($data);

        $filename = 'export_' . date('Y-m-d_His') . '.xlsx';
        $columns = $result['columns'] ?? [];
        $rows = $result['rows'] ?? [];

        $tmpFile = \Services\XlsxService::generate($columns, $rows);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0, no-cache, must-revalidate');
        header('Pragma: public');

        ob_end_clean();
        header('Content-Length: ' . filesize($tmpFile));

        readfile($tmpFile);
        unlink($tmpFile);
        exit;
    }

    public function json(): void
    {
        $data = getJsonBody();
        $result = $this->executeQuery($data);

        // Limit to JSON_RESULT_LIMIT records
        $limitedRows = array_slice($result['rows'], 0, JSON_RESULT_LIMIT);

        $response = [
            'metadata' => [
                'total_rows' => $result['row_count'],
                'returned_rows' => count($limitedRows),
                'limit' => JSON_RESULT_LIMIT,
                'columns' => $result['columns'],
                'execution_time_ms' => $result['execution_time_ms'],
                'exported_at' => date('c')
            ],
            'data' => $limitedRows
        ];

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="export_' . date('Y-m-d_His') . '.json"');
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    private function executeQuery(array $data): array
    {
        if (empty($data['connection_id']) || empty($data['sql'])) {
            errorResponse('Conexión y consulta SQL son requeridas', 422);
        }

        return QueryExecutionService::execute(
            (int)$data['connection_id'],
            trim($data['sql']),
            $data['database'] ?? null
        );
    }
}
