/**
 * Query Editor UI - SQL editor, visual builder, execution, results
 */
const QueryUI = {
    mode: 'editor', // 'editor' or 'builder'
    executionMode: 'direct', // 'direct' or 'json'
    resultView: 'table', // 'table' or 'json'
    lastResult: null,
    connections: [],
    builderTables: [], // tables added to visual builder

    async load() {
        try {
            const resp = await API.getConnections();
            this.connections = resp.data || [];
        } catch (e) { /* ignore */ }

        this.render();

        // Attach intellisense
        const sqlEditor = document.getElementById('sql-editor');
        if (sqlEditor && typeof SqlIntellisense !== 'undefined') {
            SqlIntellisense.attach(sqlEditor, {
                getConnectionId: () => document.getElementById('query-conn-select')?.value,
                getDatabase: () => document.getElementById('query-db-select')?.value
            });
            SqlIntellisense.activeTextarea = sqlEditor;
        }

        // Sync from App state
        if (App.currentConnection) {
            const connSelect = document.getElementById('query-conn-select');
            if (connSelect) {
                connSelect.value = App.currentConnection.id;
                await this.restoreConnectionState(App.currentConnection.id, App.currentDatabase);
            }
        }
    },

    render() {
        const panel = document.getElementById('panel-query-content');
        panel.innerHTML = `
            <div class="query-layout">
                <!-- Sidebar: object browser mini -->
                <div class="query-sidebar">
                    <h4 style="margin-bottom:12px;font-size:14px;">Objetos de BD</h4>
                    <div class="form-group">
                        <select class="form-control" id="query-conn-select" onchange="QueryUI.onConnChange(this.value)" style="font-size:12px;">
                            <option value="">-- Conexión --</option>
                            ${this.connections.map(c => `<option value="${c.id}">${c.name}</option>`).join('')}
                        </select>
                    </div>
                    <div class="form-group">
                        <select class="form-control" id="query-db-select" onchange="QueryUI.onDbChange(this.value)" style="font-size:12px;">
                            <option value="">-- Base de Datos --</option>
                        </select>
                    </div>
                    <!-- Favorites section -->
                    <div style="margin-top:8px;padding-bottom:8px;border-bottom:1px solid var(--border);">
                        <div class="tree-section-header" onclick="this.nextElementSibling.classList.toggle('collapsed')" style="margin:0;">
                            <span style="color:#f59e0b;">&#9733;</span> Consultas Favoritas
                        </div>
                        <div id="query-favorites-list" class="tree-section-items">
                            <div style="padding:8px 0;font-size:11px;color:var(--text-light);font-style:italic;">Selecciona conexión y BD</div>
                        </div>
                    </div>

                    <div id="query-objects-tree" style="margin-top:8px;"></div>
                </div>

                <!-- Main: editor + results -->
                <div class="query-main">
                    <!-- Toolbar -->
                    <div class="toolbar" style="border-radius:12px 12px 0 0;">
                        <button class="query-sidebar-toggle" onclick="App.toggleQuerySidebar()" title="Mostrar/Ocultar objetos de BD">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px;">
                                <rect x="3" y="3" width="18" height="18" rx="2"/><line x1="9" y1="3" x2="9" y2="21"/>
                            </svg>BD
                        </button>
                        <div class="mode-switch">
                            <button class="${this.mode === 'editor' ? 'active' : ''}" onclick="QueryUI.setMode('editor')">Editor SQL</button>
                            <button class="${this.mode === 'builder' ? 'active' : ''}" onclick="QueryUI.setMode('builder')">Constructor Visual</button>
                        </div>
                        <div style="flex:1"></div>
                        <label style="font-size:12px;display:flex;align-items:center;gap:6px;">
                            Modo:
                            <div class="mode-switch" style="margin:0;">
                                <button class="${this.executionMode === 'direct' ? 'active' : ''}" onclick="QueryUI.setExecMode('direct')">Directo</button>
                                <button class="${this.executionMode === 'json' ? 'active' : ''}" onclick="QueryUI.setExecMode('json')">JSON SP</button>
                            </div>
                        </label>
                        <button class="btn btn-success" id="btn-execute" onclick="QueryUI.execute()">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                            Ejecutar
                        </button>
                        <button class="btn btn-danger" id="btn-cancel" onclick="QueryUI.cancelExecution()" style="display:none;">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><rect x="6" y="6" width="12" height="12" rx="1"/></svg>
                            Cancelar
                        </button>
                        <button class="btn btn-sm btn-outline" onclick="QueryUI.clearResults()" title="Limpiar resultados">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>

                    <!-- Editor area -->
                    <div id="editor-area" style="${this.mode === 'editor' ? '' : 'display:none'}">
                        <div class="sql-editor-container" style="border-radius:0;">
                            <textarea id="sql-editor" class="sql-editor" placeholder="-- Escribe tu consulta SQL aquí...\n-- Ejemplo: SELECT * FROM tabla WHERE campo = 'valor'\n-- Ctrl+Enter para ejecutar" spellcheck="false"></textarea>
                        </div>
                    </div>

                    <!-- Visual Builder -->
                    <div id="builder-area" style="${this.mode === 'builder' ? '' : 'display:none'}">
                        <div style="padding:12px;background:var(--bg-main);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;">
                            <span style="font-size:13px;color:var(--secondary);">Arrastra tablas desde el panel lateral o haz clic para agregar columnas</span>
                            <div style="flex:1"></div>
                            <button class="btn btn-sm btn-primary" onclick="QueryUI.generateFromBuilder()">Generar SQL</button>
                            <button class="btn btn-sm btn-outline" onclick="QueryUI.clearBuilder()">Limpiar</button>
                        </div>
                        <div class="query-builder-canvas" id="builder-canvas">
                            <div style="text-align:center;width:100%;color:var(--text-light);padding:40px;">
                                Haz doble clic en las tablas del panel izquierdo para agregarlas aquí
                            </div>
                        </div>
                        <div class="form-group" style="padding:12px;">
                            <label>WHERE (condición opcional)</label>
                            <input type="text" class="form-control" id="builder-where" placeholder="Ej: campo1 = 'valor' AND campo2 > 10">
                        </div>
                        <div class="form-row" style="padding:0 12px 12px;">
                            <div class="form-group">
                                <label>ORDER BY</label>
                                <input type="text" class="form-control" id="builder-orderby" placeholder="Ej: campo1 ASC, campo2 DESC">
                            </div>
                            <div class="form-group">
                                <label>LIMIT</label>
                                <input type="number" class="form-control" id="builder-limit" value="100" min="1">
                            </div>
                        </div>
                    </div>

                    <!-- JSON mode panel - Step by step -->
                    <div id="json-mode-panel" style="display:${this.executionMode === 'json' ? 'block' : 'none'};">
                        <div class="json-steps">
                            <!-- Step 1 -->
                            <div class="json-step">
                                <div class="json-step-header">
                                    <span class="json-step-number">1</span>
                                    <span class="json-step-title">Escribe la consulta SQL arriba</span>
                                </div>
                                <p class="json-step-desc">Usa <code>?</code> en el WHERE como placeholder para cada valor variable. Ejemplo: <code>WHERE nombre LIKE ? AND estado = ?</code></p>
                            </div>

                            <!-- Step 2 -->
                            <div class="json-step">
                                <div class="json-step-header">
                                    <span class="json-step-number">2</span>
                                    <span class="json-step-title">Define los valores de cada parámetro</span>
                                    <span id="json-param-count" class="badge badge-info" style="margin-left:8px;">0 parámetros</span>
                                </div>
                                <p class="json-step-desc">Cada campo corresponde a un <code>?</code> en la consulta, en orden de aparición.</p>
                                <div id="json-params-list" style="margin-top:8px;"></div>
                                <button class="btn btn-sm btn-outline" onclick="QueryUI.addParam()" style="margin-top:8px;">+ Agregar parámetro manualmente</button>
                            </div>

                            <!-- Step 3 -->
                            <div class="json-step">
                                <div class="json-step-header">
                                    <span class="json-step-number">3</span>
                                    <span class="json-step-title">Revisa el JSON resultante</span>
                                    <div style="margin-left:auto;display:flex;gap:6px;">
                                        <button class="btn btn-sm btn-outline" onclick="QueryUI.copyJsonPreview()">Copiar</button>
                                    </div>
                                </div>
                                <p class="json-step-desc">Este es el JSON que se enviará al procedimiento almacenado. Puedes editarlo directamente aquí.</p>
                                <textarea id="json-preview-editor" spellcheck="false" style="width:100%;min-height:130px;max-height:250px;font-size:12px;resize:vertical;background:#1e293b;color:#e2e8f0;font-family:var(--mono);border-radius:8px;padding:12px;border:1px solid #334155;margin-top:8px;line-height:1.5;" oninput="QueryUI.onJsonDirectEdit()"></textarea>
                                <div id="json-edit-status" style="display:none;margin-top:6px;font-size:12px;"></div>
                            </div>

                            <!-- Step 4 -->
                            <div class="json-step" style="border-bottom:none;">
                                <div class="json-step-header">
                                    <span class="json-step-number">4</span>
                                    <span class="json-step-title">Ejecuta la consulta</span>
                                </div>
                                <p class="json-step-desc">Haz clic en el botón <strong>Ejecutar</strong> de la barra superior o presiona <kbd>Ctrl+Enter</kbd> en el editor SQL.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Results -->
                    <div style="padding:16px;">
                        <div id="query-results"></div>
                    </div>
                </div>
            </div>`;

        // Bind Ctrl+Enter and update JSON preview on input
        const editor = document.getElementById('sql-editor');
        if (editor) {
            editor.addEventListener('keydown', (e) => {
                if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                    e.preventDefault();
                    QueryUI.execute();
                }
                // Tab indentation
                if (e.key === 'Tab') {
                    e.preventDefault();
                    const start = editor.selectionStart;
                    const end = editor.selectionEnd;
                    editor.value = editor.value.substring(0, start) + '    ' + editor.value.substring(end);
                    editor.selectionStart = editor.selectionEnd = start + 4;
                }
            });
            // Auto-sync params and JSON preview when SQL changes
            editor.addEventListener('input', () => {
                if (QueryUI.executionMode === 'json') {
                    QueryUI._jsonDirectlyEdited = false;
                    QueryUI.syncParamsFromSql();
                    QueryUI.updateJsonPreview();
                }
            });
        }
    },

    setMode(mode) {
        const previousMode = this.mode;
        this.mode = mode;

        // Sync between modes
        if (previousMode === 'builder' && mode === 'editor') {
            this.syncBuilderToEditor();
        } else if (previousMode === 'editor' && mode === 'builder') {
            this.syncEditorToBuilder();
        }

        document.getElementById('editor-area').style.display = mode === 'editor' ? '' : 'none';
        document.getElementById('builder-area').style.display = mode === 'builder' ? '' : 'none';

        // Update mode switch buttons
        const buttons = document.querySelectorAll('.toolbar .mode-switch:first-child button');
        buttons.forEach((btn, i) => {
            btn.classList.toggle('active', (i === 0 && mode === 'editor') || (i === 1 && mode === 'builder'));
        });

        if (mode === 'json') this.updateJsonPreview();
    },

    /**
     * Builder → Editor: generate SQL from visual builder state
     */
    syncBuilderToEditor() {
        if (this.builderTables.length === 0) return;

        let columns = [];
        let tables = [];

        this.builderTables.forEach(table => {
            tables.push(table.name);
            if (table.selectedColumns.length > 0) {
                table.selectedColumns.forEach(col => {
                    columns.push(this.builderTables.length > 1 ? `${table.name}.${col}` : col);
                });
            }
        });

        if (columns.length === 0) return; // no columns selected, don't overwrite

        let sql = `SELECT ${columns.join(',\n       ')}\nFROM ${tables.join(',\n     ')}`;

        const where = document.getElementById('builder-where')?.value?.trim();
        if (where) sql += `\nWHERE ${where}`;

        const orderby = document.getElementById('builder-orderby')?.value?.trim();
        if (orderby) sql += `\nORDER BY ${orderby}`;

        const limit = document.getElementById('builder-limit')?.value;
        if (limit) sql += `\nLIMIT ${limit}`;

        sql += ';';

        document.getElementById('sql-editor').value = sql;
        if (this.executionMode === 'json') this.updateJsonPreview();
    },

    /**
     * Editor → Builder: parse SQL and populate the visual builder
     */
    async syncEditorToBuilder() {
        const sql = document.getElementById('sql-editor')?.value?.trim();
        if (!sql) return;

        // Only parse SELECT statements
        if (!/^\s*SELECT\b/i.test(sql)) {
            Toast.info('El constructor visual solo soporta consultas SELECT');
            return;
        }

        try {
            const parsed = this.parseSqlSelect(sql);
            if (!parsed) return;

            // Set WHERE, ORDER BY, LIMIT fields
            document.getElementById('builder-where').value = parsed.where || '';
            document.getElementById('builder-orderby').value = parsed.orderBy || '';
            document.getElementById('builder-limit').value = parsed.limit || '100';

            // Load tables into builder if they changed
            const connId = document.getElementById('query-conn-select')?.value;
            const db = document.getElementById('query-db-select')?.value;

            if (!connId) {
                Toast.warning('Selecciona una conexión para cargar las columnas en el constructor');
                return;
            }

            // Determine which tables we need to load
            const newTableNames = parsed.tables;
            const existingNames = this.builderTables.map(t => t.name);
            const needsReload = newTableNames.length !== existingNames.length ||
                !newTableNames.every(n => existingNames.includes(n));

            if (needsReload) {
                // Load column info for each table
                this.builderTables = [];
                for (const tableName of newTableNames) {
                    try {
                        const resp = await API.getColumns(connId, tableName, db);
                        const columns = resp.data || [];
                        const selectedCols = parsed.columnsByTable[tableName] || [];
                        this.builderTables.push({
                            name: tableName,
                            columns,
                            selectedColumns: selectedCols
                        });
                    } catch (e) {
                        // Table might not exist, add with empty columns
                        this.builderTables.push({
                            name: tableName,
                            columns: [],
                            selectedColumns: []
                        });
                    }
                }
            } else {
                // Tables are the same, just update selected columns
                this.builderTables.forEach(table => {
                    table.selectedColumns = parsed.columnsByTable[table.name] || [];
                });
            }

            this.renderBuilder();
        } catch (e) {
            // Parsing failed silently, user can still use the builder manually
        }
    },

    /**
     * Simple SQL SELECT parser - extracts tables, columns, where, order by, limit
     */
    parseSqlSelect(sql) {
        // Remove trailing semicolon and normalize whitespace
        let s = sql.replace(/;\s*$/, '').trim();

        // Extract LIMIT
        let limit = '';
        const limitMatch = s.match(/\bLIMIT\s+(\d+)\s*$/i);
        if (limitMatch) {
            limit = limitMatch[1];
            s = s.substring(0, limitMatch.index).trim();
        }

        // Extract ORDER BY
        let orderBy = '';
        const orderMatch = s.match(/\bORDER\s+BY\s+(.+)$/i);
        if (orderMatch) {
            orderBy = orderMatch[1].trim();
            s = s.substring(0, orderMatch.index).trim();
        }

        // Extract WHERE
        let where = '';
        const whereMatch = s.match(/\bWHERE\s+(.+)$/i);
        if (whereMatch) {
            where = whereMatch[1].trim();
            s = s.substring(0, whereMatch.index).trim();
        }

        // Now s should be "SELECT ... FROM ..."
        const fromMatch = s.match(/\bFROM\s+(.+)$/i);
        if (!fromMatch) return null;

        const selectPart = s.substring(0, fromMatch.index).replace(/^\s*SELECT\s+/i, '').trim();
        const fromPart = fromMatch[1].trim();

        // Parse tables from FROM
        const tables = fromPart.split(',').map(t => {
            // Handle aliases: "tabla AS t" or "tabla t"
            const parts = t.trim().split(/\s+(?:AS\s+)?/i);
            return parts[0].trim();
        }).filter(Boolean);

        // Parse columns from SELECT
        const columnsByTable = {};
        tables.forEach(t => { columnsByTable[t] = []; });

        if (selectPart === '*') {
            // Select all - don't set specific columns
        } else {
            // Split columns by comma (respecting parentheses)
            const cols = this.splitColumns(selectPart);
            cols.forEach(colExpr => {
                const col = colExpr.trim();

                // "table.column" format
                const dotMatch = col.match(/^(\w+)\.(\w+)$/);
                if (dotMatch) {
                    const [, tbl, colName] = dotMatch;
                    if (columnsByTable[tbl]) {
                        columnsByTable[tbl].push(colName);
                    }
                    return;
                }

                // "table.*" format
                const starMatch = col.match(/^(\w+)\.\*$/);
                if (starMatch) {
                    // All columns for this table - leave empty (means all)
                    return;
                }

                // Plain column name (no table prefix) - assign to first table
                const plainMatch = col.match(/^(\w+)$/);
                if (plainMatch && tables.length === 1) {
                    columnsByTable[tables[0]].push(plainMatch[1]);
                }
            });
        }

        return { tables, columnsByTable, where, orderBy, limit };
    },

    /**
     * Split SELECT column list respecting parentheses (for functions)
     */
    splitColumns(selectPart) {
        const result = [];
        let depth = 0;
        let current = '';

        for (const char of selectPart) {
            if (char === '(') depth++;
            else if (char === ')') depth--;
            else if (char === ',' && depth === 0) {
                result.push(current.trim());
                current = '';
                continue;
            }
            current += char;
        }
        if (current.trim()) result.push(current.trim());
        return result;
    },

    jsonParams: [],
    _jsonDirectlyEdited: false, // flag: user edited JSON textarea directly

    setExecMode(mode) {
        this.executionMode = mode;
        document.getElementById('json-mode-panel').style.display = mode === 'json' ? 'block' : 'none';
        if (mode === 'json') {
            this._jsonDirectlyEdited = false;
            this.syncParamsFromSql();
            this.updateJsonPreview();
        }
    },

    addParam(value = '') {
        this.jsonParams.push(value);
        this._jsonDirectlyEdited = false;
        this.renderParams();
        this.updateJsonPreview();
    },

    removeParam(index) {
        this.jsonParams.splice(index, 1);
        this._jsonDirectlyEdited = false;
        this.renderParams();
        this.updateJsonPreview();
    },

    updateParamValue(index, value) {
        this.jsonParams[index] = value;
        this._jsonDirectlyEdited = false;
        this.updateJsonPreview();
    },

    /**
     * Auto-detect ? placeholders in SQL and adjust params array
     */
    syncParamsFromSql() {
        const sql = document.getElementById('sql-editor')?.value || '';
        const count = (sql.match(/\?/g) || []).length;

        // Only add new empty slots, never remove existing values
        while (this.jsonParams.length < count) this.jsonParams.push('');
        // Remove excess only if they are empty
        while (this.jsonParams.length > count && this.jsonParams[this.jsonParams.length - 1] === '') {
            this.jsonParams.pop();
        }

        this.renderParams();
    },

    renderParams() {
        const container = document.getElementById('json-params-list');
        const countBadge = document.getElementById('json-param-count');

        if (countBadge) {
            const sql = document.getElementById('sql-editor')?.value || '';
            const qCount = (sql.match(/\?/g) || []).length;
            countBadge.textContent = qCount > 0
                ? `${qCount} detectado${qCount > 1 ? 's' : ''} en SQL`
                : 'sin placeholders ?';
        }

        if (this.jsonParams.length === 0) {
            container.innerHTML = '<div class="json-no-params">Sin parámetros. Si tu consulta no tiene valores variables, puedes ejecutarla directamente.</div>';
            return;
        }

        container.innerHTML = this.jsonParams.map((val, i) => `
            <div class="json-param-row">
                <span class="json-param-label">?${i + 1}</span>
                <span class="json-param-arrow">&rarr;</span>
                <input type="text" class="json-param-input" value="${this.escHtml(val)}"
                    placeholder="Escribe el valor para el parámetro ?${i + 1}"
                    onchange="QueryUI.updateParamValue(${i}, this.value)"
                    oninput="QueryUI.updateParamValue(${i}, this.value)">
                <button class="json-param-remove" onclick="QueryUI.removeParam(${i})" title="Eliminar parámetro">&times;</button>
            </div>`).join('');
    },

    updateJsonPreview() {
        if (this._jsonDirectlyEdited) return; // don't overwrite user's direct edits

        const sql = document.getElementById('sql-editor')?.value?.trim() || '';
        const limit = parseInt(document.getElementById('builder-limit')?.value) || 10;
        const cleanSql = sql.replace(/\n/g, ' ').replace(/\s+/g, ' ').trim();

        const jsonObj = {
            query: cleanSql,
            params: this.jsonParams,
            limit: limit
        };

        const editor = document.getElementById('json-preview-editor');
        if (editor) {
            editor.value = JSON.stringify(jsonObj, null, 2);
        }

        this.setJsonStatus('', '');
    },

    /**
     * Called when user types directly in the JSON textarea
     */
    onJsonDirectEdit() {
        this._jsonDirectlyEdited = true;
        const editor = document.getElementById('json-preview-editor');
        if (!editor) return;

        try {
            const parsed = JSON.parse(editor.value);

            // Auto-sync back: update SQL editor and params silently
            if (parsed.query !== undefined) {
                document.getElementById('sql-editor').value = parsed.query;
            }
            if (Array.isArray(parsed.params)) {
                this.jsonParams = parsed.params.map(p => String(p));
                this.renderParams();
            }

            this.setJsonStatus('JSON válido', 'success');
        } catch (e) {
            this.setJsonStatus('JSON inválido: revisa la sintaxis', 'error');
        }
    },

    setJsonStatus(message, type) {
        const el = document.getElementById('json-edit-status');
        if (!el) return;
        if (!message) {
            el.style.display = 'none';
            return;
        }
        el.style.display = 'block';
        el.style.color = type === 'error' ? 'var(--danger)' : 'var(--success)';
        el.textContent = message;
    },

    getJsonPayload() {
        // If user edited JSON directly, try to use that first
        if (this._jsonDirectlyEdited) {
            const editor = document.getElementById('json-preview-editor');
            if (editor) {
                try {
                    return JSON.parse(editor.value);
                } catch (e) {
                    Toast.error('El JSON editado no es válido. Corrige la sintaxis antes de ejecutar.');
                    throw e;
                }
            }
        }
        const sql = document.getElementById('sql-editor')?.value?.trim() || '';
        const cleanSql = sql.replace(/\n/g, ' ').replace(/\s+/g, ' ').trim();
        return { query: cleanSql, params: this.jsonParams, limit: 10 };
    },

    copyJsonPreview() {
        const editor = document.getElementById('json-preview-editor');
        const text = editor ? editor.value : '';
        navigator.clipboard.writeText(text)
            .then(() => Toast.success('JSON copiado al portapapeles'))
            .catch(() => Toast.error('No se pudo copiar'));
    },

    /**
     * Called when user manually changes connection dropdown
     */
    async onConnChange(connId) {
        if (!connId) return;
        const conn = this.connections.find(c => c.id == connId);
        const db = conn?.database_name || null;
        App.setConnection(conn, db);
        await this.restoreConnectionState(connId, db);
    },

    /**
     * Called when user manually changes database dropdown
     */
    async onDbChange(db) {
        const connId = document.getElementById('query-conn-select').value;
        if (!connId || !db) return;
        const conn = this.connections.find(c => c.id == connId);
        App.setConnection(conn, db);
        await this.loadObjectsTree(connId, db);
        this.loadFavorites(connId, db);
        // Pre-load schema for intellisense
        if (typeof SqlIntellisense !== 'undefined') SqlIntellisense.loadSchema(connId, db || '');
    },

    /**
     * Restore connection + database selection (used on panel load and connection change)
     */
    async restoreConnectionState(connId, targetDb) {
        try {
            const resp = await API.getDatabases(connId);
            const dbs = resp.data || [];
            const select = document.getElementById('query-db-select');
            select.innerHTML = `<option value="">-- Base de Datos --</option>` +
                dbs.map(db => `<option value="${db}">${db}</option>`).join('');

            const db = targetDb || null;
            if (db && dbs.includes(db)) {
                select.value = db;
                await this.loadObjectsTree(connId, db);
                this.loadFavorites(connId, db);
            }
        } catch (e) {
            Toast.error(e.message);
        }
    },

    async loadFavorites(connId, db) {
        const container = document.getElementById('query-favorites-list');
        if (!container) return;

        try {
            const params = { connection_id: connId };
            if (db) params.database = db;
            const resp = await API.getAuditFavorites(params);
            const favs = resp.data || [];

            if (favs.length === 0) {
                container.innerHTML = '<div style="padding:8px 0;font-size:11px;color:var(--text-light);font-style:italic;">Sin consultas favoritas para esta conexión</div>';
                return;
            }

            container.innerHTML = favs.map(f => {
                const shortSql = f.query_text.replace(/^JSON_SP\[.*?\]:\s*/, '').substring(0, 50);
                const mode = f.execution_mode === 'json_sp' ? 'JSON' : 'SQL';
                return `<div class="tree-leaf fav-item" onclick="QueryUI.useFavorite(${f.id})" title="${f.query_text.replace(/"/g, '&quot;').substring(0, 200)}">
                    <span style="color:#f59e0b;font-size:12px;">&#9733;</span>
                    <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${shortSql}${f.query_text.length > 50 ? '...' : ''}</span>
                    <span class="badge ${f.execution_mode === 'direct' ? 'badge-info' : 'badge-warning'}" style="font-size:9px;">${mode}</span>
                </div>`;
            }).join('');
        } catch (e) {
            container.innerHTML = '<div style="padding:8px 0;font-size:11px;color:var(--text-light);">Error al cargar favoritas</div>';
        }
    },

    async useFavorite(auditId) {
        // Fetch full audit log to get the query
        try {
            const resp = await API.getAuditLogs({ search: '', page: 1, per_page: 1 });
            // Since we can't get by ID directly, search all favorites
            const favsResp = await API.getAuditFavorites({});
            const fav = (favsResp.data || []).find(f => f.id === auditId);
            if (!fav) { Toast.error('Favorita no encontrada'); return; }

            let sql = fav.query_text;
            sql = sql.replace(/^JSON_SP\[.*?\]:\s*/, '');

            const editor = document.getElementById('sql-editor');
            if (editor) editor.value = sql;

            if (fav.execution_mode === 'json_sp') {
                this.setExecMode('json');
            } else {
                this.setExecMode('direct');
            }

            this._jsonDirectlyEdited = false;
            this.syncParamsFromSql();
            this.updateJsonPreview();

            Toast.info('Consulta favorita cargada en el editor');
        } catch (e) {
            Toast.error(e.message);
        }
    },

    async loadObjectsTree(connId, db) {
        const container = document.getElementById('query-objects-tree');
        container.innerHTML = '<div class="spinner" style="margin:10px auto"></div>';

        try {
            const [tablesResp, viewsResp, procsResp, funcsResp] = await Promise.all([
                API.getTables(connId, db),
                API.getViews(connId, db),
                API.getProcedures(connId, db),
                API.getFunctions(connId, db)
            ]);

            const tables = tablesResp.data || [];
            const views = viewsResp.data || [];
            const procs = procsResp.data || [];
            const funcs = funcsResp.data || [];

            let html = '';

            // Icon SVGs
            const iconTable = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px;opacity:0.5;"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="3" x2="9" y2="21"/></svg>';
            const iconView = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px;opacity:0.5;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
            const iconProc = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px;opacity:0.5;"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>';
            const iconFunc = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="var(--secondary)" stroke-width="2" style="vertical-align:middle;margin-right:4px;opacity:0.5;"><text x="4" y="18" font-size="16" fill="var(--secondary)" font-style="italic">f</text></svg>';

            if (tables.length > 0) {
                html += `<div class="tree-section-header" onclick="this.nextElementSibling.classList.toggle('collapsed')">
                    ${iconTable} Tablas <span class="badge badge-info" style="margin-left:auto;">${tables.length}</span>
                </div><div class="tree-section-items">`;
                tables.forEach(t => {
                    html += `<div class="tree-item-expandable">
                        <div class="tree-leaf tree-leaf-parent" onclick="QueryUI.toggleTreeItem(this, 'table', '${t.name}')" ondblclick="QueryUI.addTableToBuilder('${t.name}')" title="Clic: ver columnas | Doble clic: constructor visual">
                            <span class="tree-expand-arrow">&#9654;</span>${iconTable}<span class="tree-leaf-name">${t.name}</span>
                        </div>
                        <div class="tree-item-children collapsed"></div>
                    </div>`;
                });
                html += '</div>';
            }

            if (views.length > 0) {
                html += `<div class="tree-section-header" onclick="this.nextElementSibling.classList.toggle('collapsed')">
                    ${iconView} Vistas <span class="badge badge-info" style="margin-left:auto;">${views.length}</span>
                </div><div class="tree-section-items">`;
                views.forEach(v => {
                    html += `<div class="tree-item-expandable">
                        <div class="tree-leaf tree-leaf-parent" onclick="QueryUI.toggleTreeItem(this, 'view', '${v.name}')" ondblclick="QueryUI.addTableToBuilder('${v.name}')" title="Clic: ver columnas | Doble clic: constructor visual">
                            <span class="tree-expand-arrow">&#9654;</span>${iconView}<span class="tree-leaf-name">${v.name}</span>
                        </div>
                        <div class="tree-item-children collapsed"></div>
                    </div>`;
                });
                html += '</div>';
            }

            if (procs.length > 0) {
                html += `<div class="tree-section-header" onclick="this.nextElementSibling.classList.toggle('collapsed')">
                    ${iconProc} Procedimientos <span class="badge badge-info" style="margin-left:auto;">${procs.length}</span>
                </div><div class="tree-section-items">`;
                procs.forEach(p => {
                    html += `<div class="tree-item-expandable">
                        <div class="tree-leaf tree-leaf-parent" onclick="QueryUI.toggleTreeItem(this, 'procedure', '${p.name}')" title="Clic: ver parámetros y definición">
                            <span class="tree-expand-arrow">&#9654;</span>${iconProc}<span class="tree-leaf-name">${p.name}</span>
                        </div>
                        <div class="tree-item-children collapsed"></div>
                    </div>`;
                });
                html += '</div>';
            }

            if (funcs.length > 0) {
                html += `<div class="tree-section-header" onclick="this.nextElementSibling.classList.toggle('collapsed')">
                    ${iconFunc} Funciones <span class="badge badge-info" style="margin-left:auto;">${funcs.length}</span>
                </div><div class="tree-section-items">`;
                funcs.forEach(f => {
                    html += `<div class="tree-item-expandable">
                        <div class="tree-leaf tree-leaf-parent" onclick="QueryUI.toggleTreeItem(this, 'function', '${f.name}')" title="Clic: ver parámetros y definición">
                            <span class="tree-expand-arrow">&#9654;</span>${iconFunc}<span class="tree-leaf-name">${f.name}</span>
                        </div>
                        <div class="tree-item-children collapsed"></div>
                    </div>`;
                });
                html += '</div>';
            }

            container.innerHTML = html || '<p style="color:var(--text-light);font-size:12px;">Sin objetos</p>';
        } catch (e) {
            container.innerHTML = `<p style="color:var(--danger);font-size:12px;">${e.message}</p>`;
        }
    },

    /**
     * Insert text at cursor position in SQL editor
     */
    insertAtCursor(text) {
        const editor = document.getElementById('sql-editor');
        if (!editor) return;

        if (this.mode !== 'editor') {
            this.setMode('editor');
            setTimeout(() => {
                const ed = document.getElementById('sql-editor');
                ed.value = text;
                ed.focus();
                this._syncJsonIfNeeded();
            }, 50);
            return;
        }

        const pos = editor.selectionStart;
        editor.value = editor.value.substring(0, pos) + text + editor.value.substring(editor.selectionEnd);
        editor.selectionStart = editor.selectionEnd = pos + text.length;
        editor.focus();
        this._syncJsonIfNeeded();
    },

    _syncJsonIfNeeded() {
        if (this.executionMode === 'json') {
            this._jsonDirectlyEdited = false;
            this.syncParamsFromSql();
            this.updateJsonPreview();
        }
    },

    insertTableName(name) {
        this.insertAtCursor(name);
    },

    insertColumnName(table, column) {
        this.insertAtCursor(table + '.' + column);
    },

    insertProcCall(name) {
        const connId = document.getElementById('query-conn-select')?.value;
        const conn = this.connections.find(c => c.id == connId);
        const driver = conn?.driver || 'mysql';
        const sql = driver === 'sqlsrv' ? `EXEC ${name} ;` : `CALL ${name}();`;

        const editor = document.getElementById('sql-editor');
        if (this.mode === 'editor' && editor) {
            editor.value = sql;
            editor.focus();
            this._syncJsonIfNeeded();
        } else {
            this.insertAtCursor(sql);
        }
    },

    insertFuncCall(name) {
        const connId = document.getElementById('query-conn-select')?.value;
        const conn = this.connections.find(c => c.id == connId);
        const driver = conn?.driver || 'mysql';
        const sql = driver === 'sqlsrv' ? `SELECT dbo.${name}();` : `SELECT ${name}();`;

        const editor = document.getElementById('sql-editor');
        if (this.mode === 'editor' && editor) {
            editor.value = sql;
            editor.focus();
            this._syncJsonIfNeeded();
        } else {
            this.insertAtCursor(sql);
        }
    },

    /**
     * Toggle expand/collapse for a tree item, loading details on first expand
     */
    async toggleTreeItem(el, type, name) {
        const childrenDiv = el.nextElementSibling;
        if (!childrenDiv) return;

        const arrow = el.querySelector('.tree-expand-arrow');
        const toggleArrow = (open) => {
            if (arrow) arrow.innerHTML = open ? '&#9660;' : '&#9654;';
        };

        // Already loaded? just toggle
        if (childrenDiv.dataset.loaded === 'true') {
            const isCollapsed = childrenDiv.classList.toggle('collapsed');
            toggleArrow(!isCollapsed);
            return;
        }

        const connId = document.getElementById('query-conn-select')?.value;
        const db = document.getElementById('query-db-select')?.value;
        if (!connId) return;

        childrenDiv.innerHTML = '<div style="padding:4px 8px;font-size:11px;color:var(--text-light);">Cargando...</div>';
        childrenDiv.classList.remove('collapsed');
        toggleArrow(true);

        try {
            if (type === 'table' || type === 'view') {
                const resp = await API.getColumns(connId, name, db);
                const cols = resp.data || [];
                childrenDiv.innerHTML = cols.map(c =>
                    `<div class="tree-col-item" onclick="QueryUI.insertColumnName('${name}','${c.name}')" title="${c.full_type || c.data_type}${c.key_type === 'PRI' ? ' [PK]' : ''}">
                        <span class="tree-col-name">${c.name}</span>
                        <span class="tree-col-type">${c.data_type}</span>
                        ${c.key_type === 'PRI' ? '<span class="tree-col-pk">PK</span>' : ''}
                    </div>`
                ).join('') || '<div style="padding:4px 8px;font-size:11px;color:var(--text-light);">Sin columnas</div>';
            } else {
                // procedure or function
                const [paramsResp, defResp] = await Promise.all([
                    API.getRoutineParams(connId, name, db),
                    API.getRoutineDefinition(connId, name, db)
                ]);
                const params = paramsResp.data || [];
                const definition = defResp.data?.definition || '';

                let html = '';
                if (params.length > 0) {
                    html += '<div style="padding:4px 8px 2px;font-size:10px;font-weight:600;color:var(--secondary);text-transform:uppercase;">Parámetros</div>';
                    html += params.map(p =>
                        `<div class="tree-col-item" onclick="QueryUI.insertAtCursor('${p.name || '?'}')" title="${p.mode || 'IN'} ${p.data_type}">
                            <span class="tree-col-badge-mode">${p.mode || 'IN'}</span>
                            <span class="tree-col-name">${p.name || '(retorno)'}</span>
                            <span class="tree-col-type">${p.data_type}</span>
                        </div>`
                    ).join('');
                } else {
                    html += '<div style="padding:4px 8px;font-size:11px;color:var(--text-light);">Sin parámetros</div>';
                }

                if (definition) {
                    const shortDef = definition.length > 500 ? definition.substring(0, 500) + '...' : definition;
                    html += `<div style="padding:4px 8px 2px;font-size:10px;font-weight:600;color:var(--secondary);text-transform:uppercase;margin-top:4px;">Definición</div>`;
                    html += `<pre class="tree-routine-def">${this.escHtml(shortDef)}</pre>`;
                }

                childrenDiv.innerHTML = html;
            }
            childrenDiv.dataset.loaded = 'true';
        } catch (e) {
            childrenDiv.innerHTML = `<div style="padding:4px 8px;font-size:11px;color:var(--danger);">${e.message}</div>`;
        }
    },

    async addTableToBuilder(tableName) {
        // Check if already added
        if (this.builderTables.find(t => t.name === tableName)) {
            Toast.warning('La tabla ya está en el constructor');
            return;
        }

        const connId = document.getElementById('query-conn-select').value;
        const db = document.getElementById('query-db-select').value;
        if (!connId) { Toast.warning('Selecciona una conexión primero'); return; }

        try {
            const resp = await API.getColumns(connId, tableName, db);
            const columns = resp.data || [];
            this.builderTables.push({ name: tableName, columns, selectedColumns: [] });
            this.renderBuilder();

            if (this.mode !== 'builder') this.setMode('builder');
        } catch (e) {
            Toast.error(e.message);
        }
    },

    renderBuilder() {
        const canvas = document.getElementById('builder-canvas');

        if (this.builderTables.length === 0) {
            canvas.innerHTML = '<div style="text-align:center;width:100%;color:var(--text-light);padding:40px;">Haz doble clic en las tablas del panel izquierdo para agregarlas aquí</div>';
            return;
        }

        canvas.innerHTML = this.builderTables.map((table, ti) => `
            <div class="table-card-builder" draggable="true">
                <div class="table-card-builder-header">
                    <span>${table.name}</span>
                    <button class="remove-table" onclick="QueryUI.removeBuilderTable(${ti})">&times;</button>
                </div>
                <div class="table-card-builder-body">
                    <div class="column-check">
                        <input type="checkbox" id="bt-${ti}-all" onchange="QueryUI.toggleAllColumns(${ti}, this.checked)">
                        <label for="bt-${ti}-all" style="font-weight:600;cursor:pointer;">Todas</label>
                    </div>
                    ${table.columns.map((col, ci) => `
                        <div class="column-check">
                            <input type="checkbox" id="bt-${ti}-${ci}"
                                ${table.selectedColumns.includes(col.name) ? 'checked' : ''}
                                onchange="QueryUI.toggleColumn(${ti}, '${col.name}', this.checked)">
                            <label for="bt-${ti}-${ci}" style="cursor:pointer;">${col.name}</label>
                            <span class="column-type">${col.data_type}</span>
                        </div>`).join('')}
                </div>
            </div>`).join('');
    },

    toggleColumn(tableIndex, columnName, checked) {
        const table = this.builderTables[tableIndex];
        if (checked) {
            if (!table.selectedColumns.includes(columnName)) table.selectedColumns.push(columnName);
        } else {
            table.selectedColumns = table.selectedColumns.filter(c => c !== columnName);
        }
    },

    toggleAllColumns(tableIndex, checked) {
        const table = this.builderTables[tableIndex];
        if (checked) {
            table.selectedColumns = table.columns.map(c => c.name);
        } else {
            table.selectedColumns = [];
        }
        this.renderBuilder();
    },

    removeBuilderTable(index) {
        this.builderTables.splice(index, 1);
        this.renderBuilder();
    },

    clearBuilder() {
        this.builderTables = [];
        this.renderBuilder();
    },

    generateFromBuilder() {
        if (this.builderTables.length === 0) {
            Toast.warning('Agrega al menos una tabla');
            return;
        }

        let columns = [];
        let tables = [];

        this.builderTables.forEach(table => {
            tables.push(table.name);
            if (table.selectedColumns.length > 0) {
                table.selectedColumns.forEach(col => {
                    columns.push(this.builderTables.length > 1 ? `${table.name}.${col}` : col);
                });
            }
        });

        if (columns.length === 0) {
            Toast.warning('Selecciona al menos una columna de alguna tabla');
            return;
        }

        let sql = `SELECT ${columns.join(',\n       ')}\nFROM ${tables.join(',\n     ')}`;

        const where = document.getElementById('builder-where').value.trim();
        if (where) sql += `\nWHERE ${where}`;

        const orderby = document.getElementById('builder-orderby').value.trim();
        if (orderby) sql += `\nORDER BY ${orderby}`;

        const limit = document.getElementById('builder-limit').value;
        if (limit) sql += `\nLIMIT ${limit}`;

        sql += ';';

        // Update the SQL editor (without switching mode)
        document.getElementById('sql-editor').value = sql;
        if (this.executionMode === 'json') this.updateJsonPreview();
        Toast.success('SQL generado y sincronizado con el editor');
    },

    _executing: false,
    _execTimer: null,
    _execStartTime: null,

    async execute() {
        const connId = document.getElementById('query-conn-select').value;
        const db = document.getElementById('query-db-select').value;
        const sql = document.getElementById('sql-editor')?.value?.trim();

        if (!connId) { Toast.warning('Selecciona una conexión'); return; }
        if (!sql) { Toast.warning('Escribe una consulta SQL'); return; }
        if (this._executing) { Toast.warning('Ya hay una consulta en ejecución'); return; }

        this.setExecutingState(true);

        const resultsDiv = document.getElementById('query-results');
        resultsDiv.innerHTML = `
            <div style="text-align:center;padding:40px;">
                <div class="spinner" style="margin:0 auto;width:28px;height:28px;"></div>
                <p style="margin-top:12px;color:var(--text-light)">Ejecutando consulta...</p>
                <p id="exec-timer" style="font-family:var(--mono);font-size:20px;color:var(--primary);margin-top:8px;">0.0s</p>
            </div>`;

        const abortCtrl = API.createAbortController();

        try {
            let resp;
            const payload = { connection_id: parseInt(connId), sql, database: db || undefined };

            if (this.executionMode === 'direct') {
                resp = await API.executeQuery(payload, abortCtrl.signal);
            } else {
                let jsonPayload;
                try {
                    jsonPayload = this.getJsonPayload();
                } catch (e) {
                    resultsDiv.innerHTML = '';
                    this.setExecutingState(false);
                    return;
                }
                payload.sql = jsonPayload.query || sql;
                payload.params = jsonPayload.params || [];
                resp = await API.executeQueryJson(payload, abortCtrl.signal);
            }

            this.setExecutingState(false);
            this.lastResult = { ...resp.data, sql, connection_id: connId, database: db };
            this.renderResults(resp.data);
            Toast.success(resp.message);
        } catch (e) {
            this.setExecutingState(false);

            if (e.name === 'AbortError') {
                resultsDiv.innerHTML = `
                    <div class="card" style="border-color:var(--warning);">
                        <div style="text-align:center;padding:20px;">
                            <div style="font-size:40px;color:var(--warning);margin-bottom:8px;">&#9632;</div>
                            <h3 style="color:var(--warning);">Consulta cancelada</h3>
                            <p style="color:var(--secondary);margin-top:8px;">La petición fue cancelada por el usuario.</p>
                        </div>
                    </div>`;
                Toast.warning('Consulta cancelada');
                return;
            }

            resultsDiv.innerHTML = `
                <div class="card" style="border-color:var(--danger);">
                    <div class="card-header" style="border-bottom-color:var(--danger);">
                        <h3 style="color:var(--danger);">Error en la consulta</h3>
                    </div>
                    <div style="padding:16px;background:#fef2f2;border-radius:0 0 12px 12px;">
                        <pre style="font-family:var(--mono);font-size:13px;color:#991b1b;white-space:pre-wrap;">${this.escHtml(e.message)}</pre>
                    </div>
                </div>`;
            Toast.error('Error al ejecutar la consulta');
        }
    },

    cancelExecution() {
        API.cancelCurrentRequest();
    },

    setExecutingState(executing) {
        this._executing = executing;
        const btnExec = document.getElementById('btn-execute');
        const btnCancel = document.getElementById('btn-cancel');

        if (executing) {
            if (btnExec) btnExec.style.display = 'none';
            if (btnCancel) btnCancel.style.display = '';

            // Start timer
            this._execStartTime = Date.now();
            this._execTimer = setInterval(() => {
                const elapsed = ((Date.now() - this._execStartTime) / 1000).toFixed(1);
                const timerEl = document.getElementById('exec-timer');
                if (timerEl) timerEl.textContent = elapsed + 's';
            }, 100);
        } else {
            if (btnExec) btnExec.style.display = '';
            if (btnCancel) btnCancel.style.display = 'none';

            if (this._execTimer) {
                clearInterval(this._execTimer);
                this._execTimer = null;
            }
        }
    },

    renderResults(data) {
        const resultsDiv = document.getElementById('query-results');

        if (!data.is_select) {
            resultsDiv.innerHTML = `
                <div class="card">
                    <div style="text-align:center;padding:20px;">
                        <div style="font-size:48px;color:var(--success);margin-bottom:8px;">&#10004;</div>
                        <h3>Consulta ejecutada exitosamente</h3>
                        <p style="color:var(--secondary);margin-top:8px;">${data.message}</p>
                    </div>
                </div>`;
            return;
        }

        // For JSON SP mode, show the sent payload
        let jsonPayloadHtml = '';
        if (data.json_payload_sent) {
            jsonPayloadHtml = `
                <div class="card" style="margin-bottom:16px;">
                    <div class="card-header"><h3>JSON Enviado al SP: ${data.sp_name || ''}</h3></div>
                    <div class="json-viewer">${this.syntaxHighlightJson(JSON.stringify(data.json_payload_sent, null, 2))}</div>
                </div>`;
        }

        const rows = data.rows || data.result || [];
        const columns = data.columns || (rows.length > 0 ? Object.keys(rows[0]) : []);

        resultsDiv.innerHTML = `
            ${jsonPayloadHtml}
            <div class="card">
                <div class="card-header">
                    <h3>Resultados <span class="badge badge-info">${data.row_count} filas</span> <span class="badge badge-success">${data.execution_time_ms}ms</span></h3>
                    <div class="btn-group">
                        <button class="btn btn-sm btn-outline" onclick="QueryUI.exportCsv()">CSV</button>
                        <button class="btn btn-sm btn-outline" onclick="QueryUI.exportExcel()">Excel</button>
                        <button class="btn btn-sm btn-outline" onclick="QueryUI.exportJson()">JSON (${JSON.RESULT_LIMIT || 10})</button>
                    </div>
                </div>

                <!-- Result view tabs -->
                <div class="result-tabs">
                    <div class="result-tab ${this.resultView === 'table' ? 'active' : ''}" onclick="QueryUI.setResultView('table')">Tabla</div>
                    <div class="result-tab ${this.resultView === 'json' ? 'active' : ''}" onclick="QueryUI.setResultView('json')">JSON</div>
                </div>

                <!-- Table view -->
                <div id="result-table-view" style="display:${this.resultView === 'table' ? 'block' : 'none'};">
                    ${rows.length === 0
                        ? '<p style="padding:20px;text-align:center;color:var(--text-light);">La consulta no retornó resultados.</p>'
                        : `<div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>${columns.map(c => `<th>${this.escHtml(c)}</th>`).join('')}</tr>
                                </thead>
                                <tbody>
                                    ${rows.map(row => `<tr>${columns.map(c => `<td title="${this.escHtml(String(row[c] ?? 'NULL'))}">${this.escHtml(String(row[c] ?? 'NULL'))}</td>`).join('')}</tr>`).join('')}
                                </tbody>
                            </table>
                        </div>`}
                </div>

                <!-- JSON view (limited to 10) -->
                <div id="result-json-view" style="display:${this.resultView === 'json' ? 'block' : 'none'};">
                    <div style="padding:8px 0;font-size:12px;color:var(--secondary);">
                        Mostrando máximo 10 registros en formato JSON
                    </div>
                    <div class="json-viewer">${this.syntaxHighlightJson(JSON.stringify(rows.slice(0, 10), null, 2))}</div>
                </div>
            </div>`;
    },

    setResultView(view) {
        this.resultView = view;
        document.getElementById('result-table-view').style.display = view === 'table' ? 'block' : 'none';
        document.getElementById('result-json-view').style.display = view === 'json' ? 'block' : 'none';
        document.querySelectorAll('.result-tab').forEach(t => t.classList.remove('active'));
        document.querySelector(`.result-tab:${view === 'table' ? 'first-child' : 'last-child'}`).classList.add('active');
    },

    async exportCsv() {
        if (!this.lastResult) return;
        try {
            await API.exportCsv({ connection_id: this.lastResult.connection_id, sql: this.lastResult.sql, database: this.lastResult.database });
            Toast.success('CSV descargado');
        } catch (e) { Toast.error(e.message); }
    },

    async exportExcel() {
        if (!this.lastResult) return;
        try {
            await API.exportExcel({ connection_id: this.lastResult.connection_id, sql: this.lastResult.sql, database: this.lastResult.database });
            Toast.success('Excel descargado');
        } catch (e) { Toast.error(e.message); }
    },

    async exportJson() {
        if (!this.lastResult) return;
        try {
            await API.exportJson({ connection_id: this.lastResult.connection_id, sql: this.lastResult.sql, database: this.lastResult.database });
            Toast.success('JSON descargado (limitado a 10 registros)');
        } catch (e) { Toast.error(e.message); }
    },

    clearResults() {
        this.lastResult = null;
        const resultsDiv = document.getElementById('query-results');
        if (resultsDiv) resultsDiv.innerHTML = '';
    },

    syntaxHighlightJson(json) {
        return json.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"([^"]+)"(?=\s*:)/g, '<span class="json-key">"$1"</span>')
            .replace(/:\s*"([^"]*)"/g, ': <span class="json-string">"$1"</span>')
            .replace(/:\s*(\d+\.?\d*)/g, ': <span class="json-number">$1</span>')
            .replace(/:\s*(true|false)/g, ': <span class="json-boolean">$1</span>')
            .replace(/:\s*(null)/g, ': <span class="json-null">$1</span>');
    },

    escHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
};
