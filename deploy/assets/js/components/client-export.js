/**
 * ClientExport - Export data from client-side arrays to CSV, Excel (XLSX) and JSON.
 * Used by Multi-Query and Cross-Join where data is already in memory.
 *
 * Usage: ClientExport.export(columns, rows, 'csv', 'filename_prefix');
 */
const ClientExport = {

    export(columns, rows, format, filenamePrefix) {
        if (!columns || !columns.length) return;
        const ts = new Date().toISOString().replace(/[T:]/g, '-').substring(0, 19);
        const name = `${filenamePrefix || 'export'}_${ts}`;

        switch (format) {
            case 'csv': this.exportCsv(columns, rows, name); break;
            case 'excel': this.exportExcel(columns, rows, name); break;
            case 'json': this.exportJson(columns, rows, name); break;
        }
    },

    // ── CSV ──────────────────────────────────────────────────────────

    exportCsv(columns, rows, name) {
        const BOM = '\uFEFF';
        const sep = ';';
        let csv = BOM;

        // Header
        csv += columns.map(c => this.csvEscape(c)).join(sep) + '\r\n';

        // Rows
        for (const row of rows) {
            csv += columns.map(col => {
                const val = row[col];
                return val === null || val === undefined ? '' : this.csvEscape(String(val));
            }).join(sep) + '\r\n';
        }

        this.download(csv, `${name}.csv`, 'text/csv;charset=utf-8');
    },

    csvEscape(val) {
        if (val.includes(';') || val.includes('"') || val.includes('\n') || val.includes('\r')) {
            return '"' + val.replace(/"/g, '""') + '"';
        }
        return val;
    },

    // ── Excel (HTML table for broad compatibility) ───────────────────

    exportExcel(columns, rows, name) {
        let html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:spreadsheet" xmlns="http://www.w3.org/TR/REC-html40">';
        html += '<head><meta charset="utf-8"><style>td,th{mso-number-format:"\\@";border:1px solid #ccc;padding:4px 8px;font-size:11pt;font-family:Calibri;}th{background:#4472C4;color:#fff;font-weight:bold;}</style></head>';
        html += '<body><table>';

        // Header
        html += '<tr>' + columns.map(c => `<th>${this.htmlEscape(c)}</th>`).join('') + '</tr>';

        // Rows
        for (const row of rows) {
            html += '<tr>' + columns.map(col => {
                const val = row[col];
                if (val === null || val === undefined) return '<td></td>';
                return `<td>${this.htmlEscape(String(val))}</td>`;
            }).join('') + '</tr>';
        }

        html += '</table></body></html>';

        const blob = new Blob(['\uFEFF' + html], { type: 'application/vnd.ms-excel;charset=utf-8' });
        this.downloadBlob(blob, `${name}.xls`);
    },

    htmlEscape(str) {
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    },

    // ── JSON ─────────────────────────────────────────────────────────

    exportJson(columns, rows, name) {
        const data = {
            metadata: {
                columns: columns,
                row_count: rows.length,
                exported_at: new Date().toISOString()
            },
            data: rows.slice(0, 1000) // Limit to 1000 for JSON
        };

        const json = JSON.stringify(data, null, 2);
        this.download(json, `${name}.json`, 'application/json;charset=utf-8');
    },

    // ── Download helpers ─────────────────────────────────────────────

    download(content, filename, mimeType) {
        const blob = new Blob([content], { type: mimeType });
        this.downloadBlob(blob, filename);
    },

    downloadBlob(blob, filename) {
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }
};
