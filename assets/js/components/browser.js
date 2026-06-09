/**
 * Browser UI - Database object explorer
 */
const BrowserUI = {
    selectedConn: null,
    selectedDb: null,
    objectData: {},

    async load() {
        const container = document.getElementById('browser-content');

        // Load connection selector
        try {
            const resp = await API.getConnections();
            const conns = resp.data || [];

            if (conns.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <h3>No hay conexiones disponibles</h3>
                        <p>Configura una conexión primero.</p>
                        <button class="btn btn-primary" onclick="App.navigate('connections')" style="margin-top:12px">
                            Ir a Conexiones
                        </button>
                    </div>`;
                return;
            }

            this.renderSelector(conns);

            // Sync from App state if a connection is active
            if (App.currentConnection) {
                document.getElementById('browser-conn-select').value = App.currentConnection.id;
                this.selectedConn = App.currentConnection;
                await this.loadDatabases(App.currentDatabase);
            }
        } catch (e) {
            Toast.error('Error: ' + e.message);
        }
    },

    renderSelector(conns) {
        document.getElementById('browser-conn-selector').innerHTML = `
            <div class="form-row" style="max-width:600px;">
                <div class="form-group">
                    <label>Conexión</label>
                    <select class="form-control" id="browser-conn-select" onchange="BrowserUI.onConnChange(this.value)">
                        <option value="">-- Seleccionar conexión --</option>
                        ${conns.map(c => `<option value="${c.id}" data-driver="${c.driver}">${c.name} (${c.driver === 'mysql' ? 'MySQL' : 'SQL Server'})</option>`).join('')}
                    </select>
                </div>
                <div class="form-group">
                    <label>Base de Datos</label>
                    <select class="form-control" id="browser-db-select" onchange="BrowserUI.onDbChange(this.value)">
                        <option value="">-- Seleccionar BD --</option>
                    </select>
                </div>
            </div>`;
    },

    async onConnChange(connId) {
        if (!connId) return;
        const resp = await API.getConnection(connId);
        this.selectedConn = resp.data;
        App.setConnection(this.selectedConn, this.selectedConn.database_name);
        await this.loadDatabases();
    },

    async loadDatabases(targetDb) {
        try {
            const resp = await API.getDatabases(this.selectedConn.id);
            const dbs = resp.data || [];
            const dbToSelect = targetDb || this.selectedConn.database_name || '';
            const select = document.getElementById('browser-db-select');
            select.innerHTML = `<option value="">-- Seleccionar BD --</option>` +
                dbs.map(db => `<option value="${db}" ${db === dbToSelect ? 'selected' : ''}>${db}</option>`).join('');

            if (dbToSelect && dbs.includes(dbToSelect)) {
                this.selectedDb = dbToSelect;
                await this.loadObjects();
            }
        } catch (e) {
            Toast.error('Error al cargar bases de datos: ' + e.message);
        }
    },

    async onDbChange(db) {
        this.selectedDb = db;
        if (this.selectedConn) {
            App.setConnection(this.selectedConn, db);
        }
        if (db) await this.loadObjects();
    },

    async loadObjects() {
        const tree = document.getElementById('browser-tree');
        tree.innerHTML = '<div style="padding:20px;text-align:center"><div class="spinner" style="margin:0 auto"></div><p style="margin-top:8px;color:var(--text-light)">Cargando objetos...</p></div>';

        try {
            const connId = this.selectedConn.id;
            const db = this.selectedDb;

            const [tablesResp, viewsResp, procsResp, funcsResp] = await Promise.all([
                API.getTables(connId, db),
                API.getViews(connId, db),
                API.getProcedures(connId, db),
                API.getFunctions(connId, db)
            ]);

            this.objectData = {
                tables: tablesResp.data || [],
                views: viewsResp.data || [],
                procedures: procsResp.data || [],
                functions: funcsResp.data || []
            };

            this.renderTree();
        } catch (e) {
            tree.innerHTML = `<div class="empty-state"><h3>Error</h3><p>${e.message}</p></div>`;
        }
    },

    renderTree() {
        const tree = document.getElementById('browser-tree');
        const { tables, views, procedures, functions } = this.objectData;

        tree.innerHTML = `
            <div class="tree-container">
                ${this.renderTreeSection('Tablas', 'tables', tables, this.iconTable())}
                ${this.renderTreeSection('Vistas', 'views', views, this.iconView())}
                ${this.renderTreeSection('Procedimientos', 'procedures', procedures, this.iconProc())}
                ${this.renderTreeSection('Funciones', 'functions', functions, this.iconFunc())}
            </div>`;
    },

    renderTreeSection(title, type, items, icon) {
        const count = items.length;
        return `
            <div class="tree-node">
                <div class="tree-node-header" onclick="BrowserUI.toggleNode(this)">
                    <span class="tree-arrow">&#9654;</span>
                    ${icon}
                    <strong>${title}</strong>
                    <span class="badge badge-info">${count}</span>
                </div>
                <div class="tree-children">
                    ${items.length === 0
                        ? '<div class="tree-leaf" style="color:var(--text-light);font-style:italic">Sin elementos</div>'
                        : items.map(item => `
                            <div class="tree-leaf" onclick="BrowserUI.selectObject('${type}', '${this.escAttr(item.name)}')">
                                ${icon} <span>${item.name}</span>
                                ${item.row_count !== undefined ? `<span style="margin-left:auto;color:var(--text-light);font-size:11px">${item.row_count || 0} filas</span>` : ''}
                            </div>`).join('')}
                </div>
            </div>`;
    },

    toggleNode(header) {
        const children = header.nextElementSibling;
        const arrow = header.querySelector('.tree-arrow');
        if (children.classList.contains('open')) {
            children.classList.remove('open');
            arrow.innerHTML = '&#9654;';
        } else {
            children.classList.add('open');
            arrow.innerHTML = '&#9660;';
        }
    },

    async selectObject(type, name) {
        const detailPanel = document.getElementById('browser-detail');

        if (type === 'tables' || type === 'views') {
            try {
                const resp = await API.getColumns(this.selectedConn.id, name, this.selectedDb);
                const columns = resp.data || [];

                detailPanel.innerHTML = `
                    <div class="card">
                        <div class="card-header">
                            <h3>${type === 'tables' ? 'Tabla' : 'Vista'}: ${name}</h3>
                            <div class="btn-group">
                                <button class="btn btn-sm btn-primary" onclick="BrowserUI.generateSelect('${this.escAttr(name)}')">SELECT</button>
                                ${type === 'tables' ? `
                                <button class="btn btn-sm btn-success" onclick="BrowserUI.generateInsert('${this.escAttr(name)}')">INSERT</button>
                                <button class="btn btn-sm btn-warning" onclick="BrowserUI.generateUpdate('${this.escAttr(name)}')">UPDATE</button>` : ''}
                                <button class="btn btn-sm btn-outline" onclick="BrowserUI.useInQuery('${this.escAttr(name)}')">Usar en Consulta</button>
                            </div>
                        </div>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Columna</th>
                                        <th>Tipo</th>
                                        <th>Nullable</th>
                                        <th>Key</th>
                                        <th>Default</th>
                                        <th>Extra</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${columns.map(c => `
                                        <tr>
                                            <td><strong>${c.name}</strong></td>
                                            <td><code>${c.full_type || c.data_type}</code></td>
                                            <td>${c.nullable === 'YES' ? '<span class="badge badge-warning">SI</span>' : '<span class="badge badge-info">NO</span>'}</td>
                                            <td>${c.key_type === 'PRI' ? '<span class="badge badge-success">PK</span>' : c.key_type || ''}</td>
                                            <td>${c.default_value || '<span style="color:var(--text-light)">NULL</span>'}</td>
                                            <td>${c.extra || ''}</td>
                                        </tr>`).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>`;

                // Store columns for query generation
                this._currentColumns = columns;
                this._currentTable = name;
            } catch (e) {
                Toast.error('Error al cargar columnas: ' + e.message);
            }
        } else {
            // Procedures and Functions
            detailPanel.innerHTML = `<div class="card"><div style="text-align:center;padding:20px;"><div class="spinner" style="margin:0 auto"></div></div></div>`;

            try {
                const [paramsResp, defResp] = await Promise.all([
                    API.getRoutineParams(this.selectedConn.id, name, this.selectedDb),
                    API.getRoutineDefinition(this.selectedConn.id, name, this.selectedDb)
                ]);
                const params = paramsResp.data || [];
                const definition = defResp.data?.definition || '';
                const isProc = type === 'procedures';
                const label = isProc ? 'Procedimiento' : 'Función';

                detailPanel.innerHTML = `
                    <div class="card">
                        <div class="card-header">
                            <h3>${label}: ${name}</h3>
                            <div class="btn-group">
                                <button class="btn btn-sm btn-primary" onclick="BrowserUI.useRoutineInQuery('${this.escAttr(name)}', '${type}')">Usar en Consulta</button>
                            </div>
                        </div>

                        <!-- Parameters -->
                        <h4 style="font-size:14px;margin-bottom:10px;">Parámetros ${params.length === 0 ? '<span style="color:var(--text-light);font-weight:normal;font-size:12px;">(ninguno)</span>' : `<span class="badge badge-info">${params.length}</span>`}</h4>
                        ${params.length > 0 ? `
                        <div class="table-container" style="margin-bottom:20px;">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Nombre</th>
                                        <th>Tipo</th>
                                        <th>Modo</th>
                                        <th>Longitud</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${params.map(p => `
                                        <tr>
                                            <td>${p.position}</td>
                                            <td><strong>${p.name || '(retorno)'}</strong></td>
                                            <td><code>${p.data_type}</code></td>
                                            <td><span class="badge ${p.mode === 'OUT' ? 'badge-warning' : 'badge-info'}">${p.mode || 'IN'}</span></td>
                                            <td>${p.max_length || ''}</td>
                                        </tr>`).join('')}
                                </tbody>
                            </table>
                        </div>` : ''}

                        <!-- Definition / Source code -->
                        <h4 style="font-size:14px;margin-bottom:10px;">Definición</h4>
                        ${definition
                            ? `<pre style="background:#1e293b;color:#e2e8f0;padding:16px;border-radius:8px;font-family:var(--mono);font-size:12px;line-height:1.5;overflow:auto;max-height:400px;white-space:pre-wrap;">${this.escHtml(definition)}</pre>`
                            : '<p style="color:var(--text-light);font-size:13px;">No se pudo obtener la definición (puede requerir permisos adicionales).</p>'}
                    </div>`;
            } catch (e) {
                detailPanel.innerHTML = `<div class="card"><p style="color:var(--danger);">Error: ${e.message}</p></div>`;
            }
        }
    },

    generateSelect(table) {
        const cols = (this._currentColumns || []).map(c => c.name).join(', ');
        const sql = `SELECT ${cols}\nFROM ${table}\nWHERE 1=1\nLIMIT 100;`;
        this.sendToQuery(sql);
    },

    generateInsert(table) {
        const cols = (this._currentColumns || []).filter(c => c.extra !== 'auto_increment').map(c => c.name);
        const values = cols.map(() => '?').join(', ');
        const sql = `INSERT INTO ${table} (${cols.join(', ')})\nVALUES (${values});`;
        this.sendToQuery(sql);
    },

    generateUpdate(table) {
        const cols = (this._currentColumns || []).filter(c => c.key_type !== 'PRI' && c.extra !== 'auto_increment');
        const pk = (this._currentColumns || []).find(c => c.key_type === 'PRI');
        const sets = cols.map(c => `${c.name} = ?`).join(',\n    ');
        const where = pk ? `${pk.name} = ?` : '1=1';
        const sql = `UPDATE ${table}\nSET ${sets}\nWHERE ${where};`;
        this.sendToQuery(sql);
    },

    sendToQuery(sql) {
        App.setConnection(this.selectedConn, this.selectedDb);
        App.navigate('query');
        setTimeout(() => {
            const editor = document.getElementById('sql-editor');
            if (editor) editor.value = sql;
        }, 100);
    },

    useInQuery(name) {
        this.sendToQuery(`SELECT * FROM ${name} LIMIT 100;`);
    },

    useRoutineInQuery(name, type) {
        const driver = this.selectedConn?.driver || 'mysql';
        if (type === 'procedures') {
            const sql = driver === 'sqlsrv' ? `EXEC ${name} ;` : `CALL ${name}();`;
            this.sendToQuery(sql);
        } else {
            const sql = driver === 'sqlsrv' ? `SELECT dbo.${name}();` : `SELECT ${name}();`;
            this.sendToQuery(sql);
        }
    },

    escHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    },

    iconTable() { return '<svg class="tree-node-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="9" y1="3" x2="9" y2="21"/></svg>'; },
    iconView() { return '<svg class="tree-node-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>'; },
    iconProc() { return '<svg class="tree-node-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>'; },
    iconFunc() { return '<svg class="tree-node-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 3h12l-6 18H6z" opacity="0.3"/><text x="7" y="16" font-size="12" fill="currentColor" font-weight="bold">f</text></svg>'; },

    escAttr(str) {
        return (str || '').replace(/'/g, "\\'").replace(/"/g, '&quot;');
    }
};
