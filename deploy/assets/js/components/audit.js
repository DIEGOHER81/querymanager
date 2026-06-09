/**
 * Audit UI - Query execution audit log viewer
 */
const AuditUI = {
    currentPage: 1,
    filters: {},

    async load() {
        await Promise.all([this.loadStats(), this.loadLogs()]);
    },

    async loadStats() {
        try {
            const resp = await API.getAuditStats();
            const stats = resp.data;
            document.getElementById('audit-stats').innerHTML = `
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
                    <div></div>
                    <button class="btn btn-sm btn-danger" onclick="AuditUI.confirmClear()">
                        Borrar todos los registros
                    </button>
                </div>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value">${stats.total_queries}</div>
                        <div class="stat-label">Total Consultas</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value" style="color:var(--success)">${stats.successful}</div>
                        <div class="stat-label">Exitosas</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value" style="color:var(--danger)">${stats.errors}</div>
                        <div class="stat-label">Con Error</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value">${stats.avg_execution_ms} ms</div>
                        <div class="stat-label">Tiempo Promedio</div>
                    </div>
                </div>`;
        } catch (e) {
            document.getElementById('audit-stats').innerHTML = '';
        }
    },

    async loadLogs() {
        const container = document.getElementById('audit-logs');
        container.innerHTML = '<div style="text-align:center;padding:20px;"><div class="spinner" style="margin:0 auto;"></div></div>';

        try {
            const params = {
                page: this.currentPage,
                per_page: 30,
                ...this.filters
            };

            const resp = await API.getAuditLogs(params);
            const data = resp.data;
            this.renderLogs(data);
        } catch (e) {
            container.innerHTML = `<div class="empty-state"><h3>Error</h3><p>${e.message}</p></div>`;
        }
    },

    renderLogs(data) {
        const container = document.getElementById('audit-logs');
        const items = data.items || [];

        if (items.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <h3>Sin registros de auditoría</h3>
                    <p>Los registros aparecerán cuando ejecutes consultas.</p>
                </div>`;
            return;
        }

        container.innerHTML = `
            <!-- Filters -->
            <div class="card" style="padding:16px;margin-bottom:16px;">
                <div class="form-row-3">
                    <div class="form-group" style="margin:0">
                        <input type="text" class="form-control" placeholder="Buscar en consultas..." id="audit-search"
                            value="${this.filters.search || ''}" onchange="AuditUI.applyFilter('search', this.value)">
                    </div>
                    <div class="form-group" style="margin:0">
                        <select class="form-control" id="audit-status-filter" onchange="AuditUI.applyFilter('status', this.value)">
                            <option value="">Todos los estados</option>
                            <option value="success" ${this.filters.status === 'success' ? 'selected' : ''}>Exitosas</option>
                            <option value="error" ${this.filters.status === 'error' ? 'selected' : ''}>Con Error</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0">
                        <button class="btn btn-sm btn-outline" onclick="AuditUI.clearFilters()">Limpiar Filtros</button>
                    </div>
                </div>
            </div>

            <!-- Table -->
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width:36px;"></th>
                            <th>Fecha/Hora</th>
                            <th>Conexión</th>
                            <th>BD</th>
                            <th>Consulta</th>
                            <th>Modo</th>
                            <th>Tiempo</th>
                            <th>Filas</th>
                            <th>Estado</th>
                            <th style="width:80px;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${items.map(item => `
                            <tr>
                                <td style="text-align:center;">
                                    <span class="audit-fav ${item.is_favorite ? 'active' : ''}" onclick="AuditUI.toggleFavorite(${item.id}, this)" title="${item.is_favorite ? 'Quitar de favoritas' : 'Marcar como favorita'}">&#9733;</span>
                                </td>
                                <td style="white-space:nowrap;">${this.formatDate(item.executed_at)}</td>
                                <td>${this.esc(item.connection_name)}</td>
                                <td>${this.esc(item.database_name || '')}</td>
                                <td title="${this.esc(item.query_text)}" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${this.esc(item.query_text.substring(0, 60))}${item.query_text.length > 60 ? '...' : ''}</td>
                                <td><span class="badge ${item.execution_mode === 'direct' ? 'badge-info' : 'badge-warning'}">${item.execution_mode}</span></td>
                                <td>${item.execution_time_ms}ms</td>
                                <td>${item.row_count}</td>
                                <td><span class="badge ${item.status === 'success' ? 'badge-success' : 'badge-error'}">${item.status === 'success' ? 'OK' : 'ERROR'}</span></td>
                                <td>
                                    <div style="display:flex;gap:4px;">
                                        <button class="btn btn-sm btn-outline" onclick='AuditUI.showDetail(${JSON.stringify(item).replace(/'/g, "&#39;")})' title="Ver consulta" style="padding:3px 6px;">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        </button>
                                        <button class="btn btn-sm btn-outline" onclick='AuditUI.reuseQuery(${JSON.stringify(item).replace(/'/g, "&#39;")})' title="Usar en consultas" style="padding:3px 6px;">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>`).join('')}
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="pagination">
                <button ${data.page <= 1 ? 'disabled' : ''} onclick="AuditUI.goToPage(${data.page - 1})">&laquo; Anterior</button>
                <span style="padding:6px 12px;font-size:13px;">Página ${data.page} de ${data.total_pages} (${data.total} registros)</span>
                <button ${data.page >= data.total_pages ? 'disabled' : ''} onclick="AuditUI.goToPage(${data.page + 1})">Siguiente &raquo;</button>
            </div>

            <!-- Detail modal area -->
            <div id="audit-detail-panel"></div>`;
    },

    showDetail(item) {
        const panel = document.getElementById('audit-detail-panel');
        panel.innerHTML = `
            <div class="card" style="margin-top:16px;border-color:var(--primary);">
                <div class="card-header">
                    <h3>Detalle de consulta #${item.id}</h3>
                    <div class="btn-group">
                        <button class="btn btn-sm btn-primary" onclick="AuditUI.copyQuery('${item.id}')">Copiar SQL</button>
                        <button class="btn btn-sm btn-success" onclick='AuditUI.reuseQuery(${JSON.stringify(item).replace(/'/g, "&#39;")})'>Ejecutar de nuevo</button>
                        <button class="btn btn-sm btn-outline" onclick="document.getElementById('audit-detail-panel').innerHTML=''">Cerrar</button>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                    <div><strong style="color:var(--secondary);font-size:12px;">Conexión:</strong><br>${this.esc(item.connection_name)}</div>
                    <div><strong style="color:var(--secondary);font-size:12px;">Base de datos:</strong><br>${this.esc(item.database_name || 'N/A')}</div>
                    <div><strong style="color:var(--secondary);font-size:12px;">Modo:</strong><br>${item.execution_mode}</div>
                    <div><strong style="color:var(--secondary);font-size:12px;">Fecha:</strong><br>${this.formatDate(item.executed_at)}</div>
                    <div><strong style="color:var(--secondary);font-size:12px;">Tiempo:</strong><br>${item.execution_time_ms}ms</div>
                    <div><strong style="color:var(--secondary);font-size:12px;">Filas:</strong><br>${item.row_count}</div>
                </div>
                <div><strong style="color:var(--secondary);font-size:12px;">Consulta SQL:</strong></div>
                <pre id="audit-detail-sql-${item.id}" style="background:#1e293b;color:#e2e8f0;padding:14px;border-radius:8px;font-family:var(--mono);font-size:13px;line-height:1.5;overflow:auto;max-height:300px;white-space:pre-wrap;margin-top:6px;">${this.esc(item.query_text)}</pre>
                ${item.error_message ? `<div style="margin-top:8px;padding:10px;background:#fef2f2;border-radius:6px;color:#991b1b;font-size:13px;"><strong>Error:</strong> ${this.esc(item.error_message)}</div>` : ''}
            </div>`;
        panel.scrollIntoView({ behavior: 'smooth' });
    },

    copyQuery(id) {
        const pre = document.getElementById('audit-detail-sql-' + id);
        if (pre) {
            navigator.clipboard.writeText(pre.textContent)
                .then(() => Toast.success('SQL copiado al portapapeles'))
                .catch(() => Toast.error('No se pudo copiar'));
        }
    },

    async reuseQuery(item) {
        // Find the connection and navigate to query editor
        // Try to get the full connection object for driver info
        let conn = { id: item.connection_id, name: item.connection_name, database_name: item.database_name };
        try {
            const resp = await API.getConnection(item.connection_id);
            if (resp.data) conn = resp.data;
        } catch (e) { /* use basic info */ }
        App.setConnection(conn, item.database_name);
        App.navigate('query');
        setTimeout(() => {
            // Set connection and database selects
            const connSelect = document.getElementById('query-conn-select');
            const dbSelect = document.getElementById('query-db-select');
            if (connSelect && item.connection_id) {
                connSelect.value = item.connection_id;
                QueryUI.onConnChange(item.connection_id).then(() => {
                    if (dbSelect && item.database_name) {
                        dbSelect.value = item.database_name;
                    }
                });
            }
            // Set SQL
            const editor = document.getElementById('sql-editor');
            if (editor) {
                // Clean JSON SP prefix if present
                let sql = item.query_text;
                sql = sql.replace(/^JSON_SP\[.*?\]:\s*/, '');
                editor.value = sql;
            }
            // Set execution mode
            if (item.execution_mode === 'json_sp') {
                QueryUI.setExecMode('json');
            } else {
                QueryUI.setExecMode('direct');
            }
        }, 200);
    },

    async toggleFavorite(id, el) {
        try {
            const resp = await API.toggleAuditFavorite(id);
            el.classList.toggle('active', resp.data.is_favorite);
            Toast.success(resp.message);
        } catch (e) {
            Toast.error(e.message);
        }
    },

    async confirmClear() {
        const ok = await Confirm.delete(
            'Limpiar auditoría',
            'Se eliminarán todos los registros excepto las consultas marcadas como favoritas.'
        );
        if (ok) this.clearAll();
    },

    async clearAll() {
        try {
            const resp = await API.clearAuditLogs();
            Toast.success(resp.message);
            this.load();
        } catch (e) {
            Toast.error(e.message);
        }
    },

    applyFilter(key, value) {
        if (value) {
            this.filters[key] = value;
        } else {
            delete this.filters[key];
        }
        this.currentPage = 1;
        this.loadLogs();
    },

    clearFilters() {
        this.filters = {};
        this.currentPage = 1;
        this.loadLogs();
    },

    goToPage(page) {
        this.currentPage = page;
        this.loadLogs();
    },

    formatDate(iso) {
        if (!iso) return '';
        const d = new Date(iso);
        return d.toLocaleString('es-CO', { dateStyle: 'short', timeStyle: 'medium' });
    },

    esc(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
};
