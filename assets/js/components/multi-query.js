/**
 * Multi-Query UI - Execute SQL queries against multiple connections simultaneously
 */
const MultiQueryUI = {
    connections: [],
    selectedConnections: {},  // { connId: { checked: true, databases: [], selectedDb: '' } }
    results: {},              // { connId: { success, data, error, time, rowCount } }
    activeTab: null,
    isExecuting: false,

    async load() {
        try {
            const resp = await API.getConnections();
            this.connections = resp.data || [];
        } catch (e) {
            Toast.error('Error al cargar conexiones: ' + e.message);
            this.connections = [];
        }
        this.render();
    },

    render() {
        const panel = document.getElementById('panel-multiquery-content');
        if (!panel) return;

        panel.innerHTML = `
            <div style="max-width:1400px;margin:0 auto;padding:20px;">
                <!-- Connection Selection Card -->
                <div class="card" style="margin-bottom:16px;">
                    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
                        <h3 style="margin:0;font-size:16px;">Multi-Query: Consulta Simult\u00e1nea</h3>
                        <div style="display:flex;gap:8px;">
                            <button class="btn btn-sm btn-outline" onclick="MultiQueryUI.selectAll()">Seleccionar todas</button>
                            <button class="btn btn-sm btn-outline" onclick="MultiQueryUI.deselectAll()">Deseleccionar todas</button>
                        </div>
                    </div>
                    <div class="card-body">
                        ${this.connections.length === 0 ? `
                            <div style="padding:20px;text-align:center;color:var(--text-light);font-style:italic;">
                                No hay conexiones configuradas. Crea conexiones primero.
                            </div>
                        ` : `
                            <div id="mq-connections-list" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(380px,1fr));gap:12px;">
                                ${this.connections.map(c => this.renderConnectionItem(c)).join('')}
                            </div>
                        `}
                    </div>
                </div>

                <!-- SQL Editor Card -->
                <div class="card" style="margin-bottom:16px;">
                    <div class="card-body" style="padding:0;">
                        <div class="sql-editor-container" style="border-radius:12px;">
                            <textarea
                                id="mq-sql-editor"
                                class="sql-editor"
                                style="background:#1e293b;color:#fff;font-family:'Fira Code','Cascadia Code','Consolas',monospace;font-size:13px;min-height:160px;border:none;width:100%;padding:16px;resize:vertical;box-sizing:border-box;outline:none;"
                                placeholder="-- Escribe tu consulta SQL aqu\u00ed...\n-- Se ejecutar\u00e1 en todas las conexiones seleccionadas\n-- Ctrl+Enter para ejecutar"
                                spellcheck="false"
                                onkeydown="MultiQueryUI.onEditorKeydown(event)"
                            ></textarea>
                        </div>
                    </div>
                    <div class="toolbar" style="border-radius:0 0 12px 12px;justify-content:flex-end;">
                        <div style="flex:1;font-size:12px;color:var(--text-light);">
                            <span id="mq-selected-count">0</span> conexi\u00f3n(es) seleccionada(s)
                        </div>
                        <button class="btn btn-sm btn-outline" onclick="MultiQueryUI.clearAll()">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                            </svg>
                            Limpiar
                        </button>
                        <button class="btn btn-primary" id="mq-btn-execute" onclick="MultiQueryUI.execute()">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
                                <polygon points="5 3 19 12 5 21 5 3"/>
                            </svg>
                            Ejecutar en Todas
                        </button>
                    </div>
                </div>

                <!-- Results Section -->
                <div id="mq-results-section" style="display:none;">
                    <!-- Summary Bar -->
                    <div id="mq-summary-bar" class="card" style="margin-bottom:12px;padding:12px 20px;display:flex;align-items:center;gap:16px;font-size:13px;"></div>

                    <!-- Tab Bar -->
                    <div class="card" style="overflow:hidden;">
                        <div id="mq-tab-bar" class="toolbar" style="border-radius:12px 12px 0 0;gap:0;padding:0;overflow-x:auto;flex-wrap:nowrap;"></div>
                        <div id="mq-tab-content" style="padding:0;"></div>
                    </div>
                </div>

                <!-- Loading Overlay -->
                <div id="mq-loading" style="display:none;">
                    <div class="card" style="padding:40px;text-align:center;">
                        <div class="spinner" style="margin:0 auto 16px;width:40px;height:40px;border:3px solid var(--border);border-top-color:var(--primary);border-radius:50%;animation:spin 0.8s linear infinite;"></div>
                        <div style="font-size:14px;color:var(--text-light);">Ejecutando consulta en m\u00faltiples conexiones...</div>
                        <div id="mq-loading-progress" style="font-size:12px;color:var(--text-light);margin-top:8px;"></div>
                    </div>
                </div>
            </div>

            <style>
                @keyframes spin { to { transform: rotate(360deg); } }
                .mq-tab {
                    padding: 10px 18px;
                    cursor: pointer;
                    border: none;
                    background: none;
                    font-size: 13px;
                    color: var(--text-light);
                    border-bottom: 2px solid transparent;
                    white-space: nowrap;
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    transition: all 0.15s ease;
                }
                .mq-tab:hover { background: var(--hover); color: var(--text); }
                .mq-tab.active { color: var(--primary); border-bottom-color: var(--primary); background: var(--hover); }
                .mq-dot {
                    width: 8px; height: 8px;
                    border-radius: 50%;
                    display: inline-block;
                    flex-shrink: 0;
                }
                .mq-dot.success { background: #22c55e; }
                .mq-dot.error { background: #ef4444; }
                .mq-conn-item {
                    display: flex;
                    align-items: flex-start;
                    gap: 10px;
                    padding: 10px 14px;
                    border: 1px solid var(--border);
                    border-radius: 8px;
                    transition: border-color 0.15s ease;
                }
                .mq-conn-item:hover { border-color: var(--primary); }
                .mq-conn-item.checked { border-color: var(--primary); background: rgba(59,130,246,0.05); }
                .mq-conn-item label {
                    cursor: pointer;
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    font-size: 13px;
                    flex: 1;
                    min-width: 0;
                }
                .mq-conn-item .mq-db-select {
                    margin-top: 8px;
                    width: 100%;
                }
            </style>
        `;

        this.updateSelectedCount();

        // Attach intellisense to SQL editor
        const editor = document.getElementById('mq-sql-editor');
        if (editor && typeof SqlIntellisense !== 'undefined') {
            SqlIntellisense.attach(editor, {
                getConnectionId: () => {
                    const checked = document.querySelector('.mq-conn-check:checked');
                    return checked ? checked.value : null;
                },
                getDatabase: () => {
                    const checked = document.querySelector('.mq-conn-check:checked');
                    if (!checked) return null;
                    const dbSel = document.getElementById(`mq-db-${checked.value}`);
                    return dbSel ? dbSel.value : null;
                }
            });
            SqlIntellisense.activeTextarea = editor;
        }
    },

    renderConnectionItem(conn) {
        const state = this.selectedConnections[conn.id];
        const isChecked = state && state.checked;
        const driverLabel = conn.driver === 'mysql' ? 'MySQL' : 'SQL Server';

        let dbSelector = '';
        if (isChecked && state.databases && state.databases.length > 0) {
            dbSelector = `
                <div class="mq-db-select">
                    <select class="form-control" style="font-size:11px;padding:4px 8px;height:auto;"
                            onchange="MultiQueryUI.onDbSelect(${conn.id}, this.value)">
                        <option value="">-- Base de datos por defecto --</option>
                        ${state.databases.map(db => `<option value="${this.escHtml(db)}" ${state.selectedDb === db ? 'selected' : ''}>${this.escHtml(db)}</option>`).join('')}
                    </select>
                </div>
            `;
        } else if (isChecked && state.loadingDbs) {
            dbSelector = `
                <div class="mq-db-select" style="font-size:11px;color:var(--text-light);padding:4px 0;">
                    Cargando bases de datos...
                </div>
            `;
        }

        return `
            <div class="mq-conn-item ${isChecked ? 'checked' : ''}" id="mq-conn-${conn.id}">
                <div style="flex:1;min-width:0;">
                    <label>
                        <input type="checkbox" ${isChecked ? 'checked' : ''}
                               onchange="MultiQueryUI.toggleConnection(${conn.id}, this.checked)">
                        <span style="font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${this.escHtml(conn.name)}</span>
                        <span class="driver-badge ${conn.driver}" style="font-size:10px;flex-shrink:0;">${driverLabel}</span>
                    </label>
                    <div style="font-size:11px;color:var(--text-light);margin-left:24px;margin-top:2px;">
                        ${this.escHtml(conn.host)}${conn.port ? ':' + conn.port : ''}
                        ${conn.database_name ? ' &bull; ' + this.escHtml(conn.database_name) : ''}
                    </div>
                    ${dbSelector}
                    ${isChecked ? `<button onclick="MultiQueryUI.exploreConnection(${conn.id})" style="margin-top:6px;margin-left:24px;font-size:10px;padding:3px 8px;background:none;border:1px solid var(--border);border-radius:4px;cursor:pointer;color:var(--text-light);" onmouseenter="this.style.borderColor='var(--primary)';this.style.color='var(--primary)'" onmouseleave="this.style.borderColor='var(--border)';this.style.color='var(--text-light)'">
                        <svg viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:3px;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>Explorar BD</button>` : ''}
                </div>
            </div>
        `;
    },

    exploreConnection(connId) {
        const state = this.selectedConnections[connId];
        const conn = this.connections.find(c => c.id === connId);
        const db = state?.selectedDb || '';
        if (typeof SqlIntellisense !== 'undefined') {
            SqlIntellisense.openSchemaDrawer(connId, db, conn?.name || '');
        }
    },

    async toggleConnection(connId, checked) {
        if (checked) {
            this.selectedConnections[connId] = {
                checked: true,
                databases: [],
                selectedDb: '',
                loadingDbs: true
            };
            this.refreshConnectionItem(connId);
            try {
                const resp = await API.getDatabases(connId);
                const dbs = resp.data || [];
                if (this.selectedConnections[connId]) {
                    this.selectedConnections[connId].databases = dbs;
                    this.selectedConnections[connId].loadingDbs = false;
                }
            } catch (e) {
                if (this.selectedConnections[connId]) {
                    this.selectedConnections[connId].loadingDbs = false;
                }
            }
            this.refreshConnectionItem(connId);
        } else {
            delete this.selectedConnections[connId];
            this.refreshConnectionItem(connId);
        }
        this.updateSelectedCount();
    },

    refreshConnectionItem(connId) {
        const conn = this.connections.find(c => c.id === connId);
        if (!conn) return;
        const el = document.getElementById(`mq-conn-${connId}`);
        if (!el) return;
        el.outerHTML = this.renderConnectionItem(conn);
    },

    onDbSelect(connId, db) {
        if (this.selectedConnections[connId]) {
            this.selectedConnections[connId].selectedDb = db;
        }
        // Pre-load schema for intellisense
        if (typeof SqlIntellisense !== 'undefined') {
            SqlIntellisense.loadSchema(connId, db || '');
        }
    },

    selectAll() {
        this.connections.forEach(c => {
            if (!this.selectedConnections[c.id]) {
                this.toggleConnection(c.id, true);
            }
        });
    },

    deselectAll() {
        this.selectedConnections = {};
        const listEl = document.getElementById('mq-connections-list');
        if (listEl) {
            listEl.innerHTML = this.connections.map(c => this.renderConnectionItem(c)).join('');
        }
        this.updateSelectedCount();
    },

    updateSelectedCount() {
        const countEl = document.getElementById('mq-selected-count');
        if (countEl) {
            const count = Object.keys(this.selectedConnections).filter(
                id => this.selectedConnections[id].checked
            ).length;
            countEl.textContent = count;
        }
    },

    onEditorKeydown(event) {
        if (event.ctrlKey && event.key === 'Enter') {
            event.preventDefault();
            this.execute();
        }
        // Tab key inserts spaces
        if (event.key === 'Tab') {
            event.preventDefault();
            const ta = event.target;
            const start = ta.selectionStart;
            const end = ta.selectionEnd;
            ta.value = ta.value.substring(0, start) + '    ' + ta.value.substring(end);
            ta.selectionStart = ta.selectionEnd = start + 4;
        }
    },

    getSelectedIds() {
        return Object.keys(this.selectedConnections)
            .filter(id => this.selectedConnections[id].checked)
            .map(id => parseInt(id, 10));
    },

    getDatabasesMap() {
        const map = {};
        for (const [id, state] of Object.entries(this.selectedConnections)) {
            if (state.checked && state.selectedDb) {
                map[id] = state.selectedDb;
            }
        }
        return map;
    },

    async execute() {
        const sql = document.getElementById('mq-sql-editor');
        if (!sql) return;

        const query = sql.value.trim();
        if (!query) {
            Toast.error('Escribe una consulta SQL antes de ejecutar.');
            return;
        }

        const connIds = this.getSelectedIds();
        if (connIds.length === 0) {
            Toast.error('Selecciona al menos una conexi\u00f3n.');
            return;
        }

        this.isExecuting = true;
        this.results = {};
        this.activeTab = null;

        // Show loading, hide previous results
        const loadingEl = document.getElementById('mq-loading');
        const resultsEl = document.getElementById('mq-results-section');
        if (loadingEl) loadingEl.style.display = 'block';
        if (resultsEl) resultsEl.style.display = 'none';

        const btnExec = document.getElementById('mq-btn-execute');
        if (btnExec) {
            btnExec.disabled = true;
            btnExec.textContent = 'Ejecutando...';
        }

        const progressEl = document.getElementById('mq-loading-progress');
        if (progressEl) progressEl.textContent = `0 de ${connIds.length} completadas`;

        const startTime = performance.now();
        const databases = this.getDatabasesMap();
        let completed = 0;

        try {
            const resp = await API.request('POST', '/query/multi-execute', {
                connection_ids: connIds,
                sql: query,
                databases: databases
            });

            // Process response - results is an array, each item has connection_id
            const resultsArray = (resp.data && resp.data.results) || [];
            for (const connResult of resultsArray) {
                const cid = connResult.connection_id;
                this.results[cid] = {
                    success: connResult.success !== false,
                    columns: connResult.columns || [],
                    rows: connResult.rows || [],
                    error: connResult.error || null,
                    time: connResult.execution_time_ms || 0,
                    rowCount: connResult.row_count || (connResult.rows ? connResult.rows.length : 0)
                };
            }
            // Mark any connection without a result
            for (const connId of connIds) {
                if (!this.results[connId]) {
                    this.results[connId] = {
                        success: false,
                        error: 'Sin respuesta del servidor para esta conexi\u00f3n.',
                        columns: [],
                        rows: [],
                        time: 0,
                        rowCount: 0
                    };
                }
            }
        } catch (e) {
            // If the entire request fails, mark all as failed
            for (const connId of connIds) {
                this.results[connId] = {
                    success: false,
                    error: e.message || 'Error desconocido',
                    columns: [],
                    rows: [],
                    time: 0,
                    rowCount: 0
                };
            }
        }

        const totalTime = ((performance.now() - startTime) / 1000).toFixed(2);

        this.isExecuting = false;
        if (loadingEl) loadingEl.style.display = 'none';
        if (btnExec) {
            btnExec.disabled = false;
            btnExec.innerHTML = `
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
                    <polygon points="5 3 19 12 5 21 5 3"/>
                </svg>
                Ejecutar en Todas
            `;
        }

        // Set first tab as active
        if (connIds.length > 0) {
            this.activeTab = connIds[0];
        }

        this.renderResults(connIds, totalTime);
    },

    renderResults(connIds, totalTime) {
        const resultsEl = document.getElementById('mq-results-section');
        if (!resultsEl) return;
        resultsEl.style.display = 'block';

        const successCount = connIds.filter(id => this.results[id] && this.results[id].success).length;
        const failCount = connIds.length - successCount;

        // Summary bar
        const summaryEl = document.getElementById('mq-summary-bar');
        if (summaryEl) {
            summaryEl.innerHTML = `
                <div style="flex:1;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                    <span><strong>${connIds.length}</strong> conexi\u00f3n(es) consultada(s)</span>
                    <span style="color:#22c55e;"><span class="mq-dot success"></span> <strong>${successCount}</strong> exitosa(s)</span>
                    ${failCount > 0 ? `<span style="color:#ef4444;"><span class="mq-dot error"></span> <strong>${failCount}</strong> fallida(s)</span>` : ''}
                </div>
                <div style="font-size:12px;color:var(--text-light);">Tiempo total: ${totalTime}s</div>
            `;
        }

        // Tab bar
        this.renderTabBar(connIds);
        // Tab content
        this.renderActiveTabContent();
    },

    renderTabBar(connIds) {
        const tabBar = document.getElementById('mq-tab-bar');
        if (!tabBar) return;

        tabBar.innerHTML = connIds.map(connId => {
            const conn = this.connections.find(c => c.id === connId);
            const result = this.results[connId];
            const isActive = this.activeTab === connId;
            const dotClass = result && result.success ? 'success' : 'error';
            const name = conn ? conn.name : 'Conexi\u00f3n ' + connId;

            return `
                <button class="mq-tab ${isActive ? 'active' : ''}"
                        onclick="MultiQueryUI.switchTab(${connId})">
                    <span class="mq-dot ${dotClass}"></span>
                    ${this.escHtml(name)}
                </button>
            `;
        }).join('');
    },

    switchTab(connId) {
        this.activeTab = connId;

        // Update tab bar active state
        const tabBar = document.getElementById('mq-tab-bar');
        if (tabBar) {
            tabBar.querySelectorAll('.mq-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            const activeBtn = tabBar.querySelector(`[onclick="MultiQueryUI.switchTab(${connId})"]`);
            if (activeBtn) activeBtn.classList.add('active');
        }

        this.renderActiveTabContent();
    },

    renderActiveTabContent() {
        const contentEl = document.getElementById('mq-tab-content');
        if (!contentEl || !this.activeTab) return;

        const connId = this.activeTab;
        const result = this.results[connId];
        const conn = this.connections.find(c => c.id === connId);

        if (!result) {
            contentEl.innerHTML = '<div style="padding:20px;color:var(--text-light);">Sin resultados.</div>';
            return;
        }

        if (!result.success) {
            contentEl.innerHTML = `
                <div style="padding:20px;">
                    <div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);border-radius:8px;padding:16px;color:#ef4444;">
                        <div style="font-weight:600;margin-bottom:6px;">Error en ${this.escHtml(conn ? conn.name : 'conexi\u00f3n')}</div>
                        <div style="font-size:13px;font-family:monospace;white-space:pre-wrap;">${this.escHtml(result.error)}</div>
                    </div>
                </div>
            `;
            return;
        }

        // Successful result
        const metaInfo = [];
        if (result.rowCount !== undefined) metaInfo.push(`${result.rowCount} fila(s)`);
        if (result.affectedRows) metaInfo.push(`${result.affectedRows} fila(s) afectada(s)`);
        if (result.time) metaInfo.push(`${parseFloat(result.time).toFixed(3)}s`);

        let tableHtml = '';
        if (result.columns && result.columns.length > 0 && result.rows && result.rows.length > 0) {
            const displayRows = result.rows.slice(0, 500);
            const truncMsg = result.rows.length > 500 ? `<p style="text-align:center;color:var(--warning);padding:8px;font-size:12px;">Mostrando 500 de ${result.rows.length} filas</p>` : '';
            tableHtml = `
                ${truncMsg}
                <div class="table-container" style="max-height:60vh;overflow:auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th style="width:50px;text-align:center;">#</th>
                                ${result.columns.map(col => `<th>${this.escHtml(col)}</th>`).join('')}
                            </tr>
                        </thead>
                        <tbody>
                            ${displayRows.map((row, idx) => `
                                <tr>
                                    <td style="text-align:center;color:var(--text-light);font-size:11px;">${idx + 1}</td>
                                    ${result.columns.map(col => `<td title="${this.escHtml(String(row[col] ?? 'NULL'))}">${this.formatCell(row[col])}</td>`).join('')}
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            `;
        } else if (result.affectedRows) {
            tableHtml = `
                <div style="padding:20px;text-align:center;color:var(--text-light);">
                    Consulta ejecutada correctamente. ${result.affectedRows} fila(s) afectada(s).
                </div>
            `;
        } else {
            tableHtml = `
                <div style="padding:20px;text-align:center;color:var(--text-light);">
                    Consulta ejecutada correctamente. Sin filas de resultado.
                </div>
            `;
        }

        contentEl.innerHTML = `
            <div style="padding:8px 16px;font-size:12px;color:var(--text-light);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <span class="mq-dot success"></span>
                <strong>${this.escHtml(conn ? conn.name : 'Conexi\u00f3n')}</strong>
                ${metaInfo.length > 0 ? ' &mdash; ' + metaInfo.join(' &bull; ') : ''}
                <div style="margin-left:auto;display:flex;gap:6px;">
                    <button class="btn btn-sm btn-outline" onclick="MultiQueryUI.exportActiveTab('csv')" style="font-size:11px;padding:3px 10px;">CSV</button>
                    <button class="btn btn-sm btn-outline" onclick="MultiQueryUI.exportActiveTab('excel')" style="font-size:11px;padding:3px 10px;">Excel</button>
                    <button class="btn btn-sm btn-outline" onclick="MultiQueryUI.exportActiveTab('json')" style="font-size:11px;padding:3px 10px;">JSON</button>
                </div>
            </div>
            ${tableHtml}
        `;
    },

    exportActiveTab(format) {
        const result = this.results[this.activeTab];
        if (!result || !result.success || !result.columns?.length) return;
        const conn = this.connections.find(c => c.id === this.activeTab);
        const name = conn ? conn.name : 'export';
        ClientExport.export(result.columns, result.rows, format, `multiquery_${name}`);
    },

    formatCell(value) {
        if (value === null || value === undefined) {
            return '<span style="color:var(--text-light);font-style:italic;">NULL</span>';
        }
        if (typeof value === 'object') {
            return '<span style="font-size:12px;font-family:monospace;">' + this.escHtml(JSON.stringify(value)) + '</span>';
        }
        const str = String(value);
        if (str.length > 200) {
            return this.escHtml(str.substring(0, 200)) + '<span style="color:var(--text-light);">... (' + str.length + ' chars)</span>';
        }
        return this.escHtml(str);
    },

    clearAll() {
        const editor = document.getElementById('mq-sql-editor');
        if (editor) editor.value = '';

        this.results = {};
        this.activeTab = null;

        const resultsEl = document.getElementById('mq-results-section');
        if (resultsEl) resultsEl.style.display = 'none';
    },

    escHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
};
