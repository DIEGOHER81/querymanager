/**
 * Cross-Join UI
 * Two modes:
 *   1. Constructor: visual builder with sources + JOINs + set operations
 *   2. Editor SQL:  free-form SQL against virtual tables from different connections
 */
const CrossJoinUI = {
    connections: [],
    sources: [],
    joins: [],
    sourceCounter: 0,
    executing: false,
    lastResult: null,
    lastPayload: null,
    mode: 'constructor', // 'constructor' | 'editor'
    combineMode: 'join', // 'join' | 'set'

    async load() {
        try {
            const resp = await API.getConnections();
            this.connections = resp.data || [];
        } catch (e) { this.connections = []; }
        this.sources = [];
        this.joins = [];
        this.sourceCounter = 0;
        this.lastResult = null;
        this.lastPayload = null;
        this.render();
    },

    render() {
        const panel = document.getElementById('panel-crossjoin-content');
        if (!panel) return;

        panel.innerHTML = `
            <!-- Mode tabs -->
            <div style="display:flex;gap:0;margin-bottom:16px;border:1px solid var(--border);border-radius:8px;overflow:hidden;width:fit-content;">
                <button id="cj-tab-constructor" onclick="CrossJoinUI.switchMode('constructor')"
                    style="border:none;border-radius:0;padding:10px 24px;font-size:13px;font-weight:600;cursor:pointer;
                           ${this.mode === 'constructor' ? 'background:var(--primary);color:#fff;' : 'background:var(--bg-card);color:var(--text);'}">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:6px;">
                        <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><line x1="17.5" y1="14" x2="17.5" y2="21"/><line x1="14" y1="17.5" x2="21" y2="17.5"/>
                    </svg>Constructor
                </button>
                <button id="cj-tab-editor" onclick="CrossJoinUI.switchMode('editor')"
                    style="border:none;border-radius:0;padding:10px 24px;font-size:13px;font-weight:600;cursor:pointer;
                           ${this.mode === 'editor' ? 'background:var(--primary);color:#fff;' : 'background:var(--bg-card);color:var(--text);'}">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:6px;">
                        <polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>
                    </svg>Editor SQL Libre
                </button>
            </div>

            <div id="cj-mode-content"></div>

            <!-- Drawer -->
            <div id="cj-drawer-overlay" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:999;" onclick="CrossJoinUI.closeDrawer()"></div>
            <div id="cj-drawer" style="display:none;position:fixed;top:0;right:0;width:560px;max-width:90vw;height:100vh;background:var(--bg-card);box-shadow:-4px 0 20px rgba(0,0,0,0.15);z-index:1000;flex-direction:column;">
                <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--border);">
                    <h3 style="margin:0;font-size:16px;font-weight:700;">Consulta Construida</h3>
                    <button onclick="CrossJoinUI.closeDrawer()" style="background:none;border:none;cursor:pointer;font-size:22px;color:var(--text-light);padding:4px 8px;">&times;</button>
                </div>
                <div id="cj-drawer-content" style="flex:1;overflow-y:auto;padding:20px;"></div>
            </div>
        `;

        if (this.mode === 'constructor') this.renderConstructor();
        else this.renderEditor();
    },

    switchMode(mode) {
        this.mode = mode;
        this.sources = [];
        this.joins = [];
        this.sourceCounter = 0;
        this.lastResult = null;
        this.lastPayload = null;
        this.render();
    },

    // ═══════════════════════════════════════════════════════════════════
    //  SHARED: Sources
    // ═══════════════════════════════════════════════════════════════════

    renderSourcesCard(containerId, showSql) {
        return `
            <div class="card" style="margin-bottom:16px;">
                <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--border);">
                    <h3 style="margin:0;font-size:16px;font-weight:700;">Fuentes de Datos</h3>
                    <button class="btn btn-primary" onclick="CrossJoinUI.addSource(${showSql})" style="font-size:13px;padding:6px 16px;">+ Agregar Fuente</button>
                </div>
                <div id="${containerId}" style="padding:16px;display:flex;flex-direction:column;gap:12px;">
                    <p style="color:var(--text-light);text-align:center;padding:16px 0;font-size:14px;">Agregue fuentes de datos.</p>
                </div>
            </div>`;
    },

    addSource(showSql = true) {
        this.sourceCounter++;
        const id = this.sourceCounter;
        const defaultAlias = (['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'])[(id - 1) % 8] || 's' + id;
        const colors = ['#3b82f6', '#8b5cf6', '#059669', '#d97706', '#dc2626', '#0891b2', '#7c3aed', '#f59e0b'];
        const color = colors[(id - 1) % colors.length];

        this.sources.push({ id, alias: defaultAlias, color });

        const containerId = this.mode === 'constructor' ? 'cj-sources' : 'cj-editor-sources';
        const container = document.getElementById(containerId);
        if (!container) return;
        const placeholder = container.querySelector('p');
        if (placeholder) placeholder.remove();

        const connOptions = this.connections.map(c => `<option value="${c.id}">${this.esc(c.name)} (${c.driver})</option>`).join('');
        const placeholders = [
            'SELECT id, nombre, email FROM usuarios WHERE activo = 1',
            'SELECT id, nombre, email FROM users WHERE status = \'active\'',
            'SELECT employee_id, full_name, mail FROM employees'
        ];
        const ph = placeholders[(id - 1) % placeholders.length];

        const div = document.createElement('div');
        div.id = `cj-source-${id}`;
        div.style.cssText = `border:1px solid var(--border);border-radius:10px;overflow:hidden;border-left:4px solid ${color};background:var(--bg-card);`;

        const sqlBlock = showSql ? `
            <div style="flex:1;min-width:280px;">
                <label style="font-size:11px;color:var(--text-light);display:block;margin-bottom:3px;">Consulta SQL <span style="font-style:italic;color:var(--text-light);">&mdash; datos que aporta esta fuente</span></label>
                <textarea id="cj-src-sql-${id}" rows="3" spellcheck="false" placeholder="${ph}"
                    style="width:100%;font-family:'Fira Code','Cascadia Code','Consolas',monospace;font-size:12px;background:#1e293b;color:#e2e8f0;border:1px solid #334155;border-radius:6px;padding:8px 10px;resize:vertical;line-height:1.5;"></textarea>
            </div>` : '';

        div.innerHTML = `
            <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 16px;background:${color}11;">
                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="width:10px;height:10px;border-radius:50%;background:${color};display:inline-block;"></span>
                    <span style="font-weight:700;font-size:14px;">Fuente</span>
                    <input type="text" class="form-control" id="cj-src-alias-${id}" value="${defaultAlias}"
                        onchange="CrossJoinUI.onAliasChange()" style="font-size:13px;padding:3px 8px;width:60px;font-weight:700;text-align:center;">
                </div>
                <div style="display:flex;align-items:center;gap:6px;">
                    <button onclick="CrossJoinUI.exploreSource(${id})" title="Explorar objetos de BD" class="btn" style="font-size:11px;padding:4px 10px;border:1px solid var(--border);">
                        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:2px;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>BD</button>
                    ${showSql ? `<button onclick="CrossJoinUI.previewSource(${id})" title="Probar (TOP 1)" class="btn" style="font-size:11px;padding:4px 10px;border:1px solid var(--border);">
                        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:2px;"><polygon points="5 3 19 12 5 21 5 3"/></svg>Probar</button>` : ''}
                    <button onclick="CrossJoinUI.removeSource(${id})" title="Eliminar" style="background:none;border:none;cursor:pointer;color:var(--danger);font-size:18px;padding:2px 8px;">&times;</button>
                </div>
            </div>
            <div style="padding:12px 16px;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start;">
                <div style="flex:0 0 180px;">
                    <label style="font-size:11px;color:var(--text-light);display:block;margin-bottom:3px;">Conexion</label>
                    <select class="form-control" id="cj-src-conn-${id}" onchange="CrossJoinUI.onConnChange(${id})" style="font-size:12px;">
                        <option value="">-- Seleccionar --</option>${connOptions}
                    </select>
                </div>
                <div style="flex:0 0 180px;">
                    <label style="font-size:11px;color:var(--text-light);display:block;margin-bottom:3px;">Base de datos</label>
                    <select class="form-control" id="cj-src-db-${id}" onchange="CrossJoinUI.onDbChange(${id})" style="font-size:12px;" disabled>
                        <option value="">-- BD --</option>
                    </select>
                </div>
                ${sqlBlock}
            </div>
            <div id="cj-preview-${id}" style="display:none;padding:0 16px 10px;"></div>
        `;
        container.appendChild(div);
        this.updateJoinSelectors();

        // Attach intellisense to SQL textarea
        if (showSql && typeof SqlIntellisense !== 'undefined') {
            const sqlTa = document.getElementById(`cj-src-sql-${id}`);
            if (sqlTa) {
                SqlIntellisense.attach(sqlTa, {
                    getConnectionId: () => document.getElementById(`cj-src-conn-${id}`)?.value,
                    getDatabase: () => document.getElementById(`cj-src-db-${id}`)?.value,
                    getSources: () => this.sources.map(s => ({
                        alias: this.getAlias(s.id),
                        connId: document.getElementById(`cj-src-conn-${s.id}`)?.value,
                        db: document.getElementById(`cj-src-db-${s.id}`)?.value
                    }))
                });
            }
        }
    },

    exploreSource(id) {
        const connId = document.getElementById(`cj-src-conn-${id}`)?.value;
        const db = document.getElementById(`cj-src-db-${id}`)?.value || '';
        const conn = this.connections.find(c => String(c.id) === String(connId));
        if (!connId) { this.showError('Seleccione una conexion primero'); return; }
        if (typeof SqlIntellisense !== 'undefined') {
            // Set active textarea to the source's SQL textarea if exists
            const ta = document.getElementById(`cj-src-sql-${id}`);
            if (ta) SqlIntellisense.activeTextarea = ta;
            SqlIntellisense.openSchemaDrawer(connId, db, conn?.name || '');
        }
    },

    removeSource(id) {
        if (this.sources.length <= 2) return;
        this.sources = this.sources.filter(s => s.id !== id);
        const el = document.getElementById(`cj-source-${id}`);
        if (el) el.remove();
        this.updateJoinSelectors();
    },

    getAlias(id) {
        const el = document.getElementById(`cj-src-alias-${id}`);
        return el ? el.value.trim() : '';
    },

    onAliasChange() { this.updateJoinSelectors(); },

    async onConnChange(id) {
        const connSelect = document.getElementById(`cj-src-conn-${id}`);
        const dbSelect = document.getElementById(`cj-src-db-${id}`);
        if (!connSelect || !dbSelect) return;
        dbSelect.innerHTML = '<option value="">-- BD --</option>';
        dbSelect.disabled = true;
        if (!connSelect.value) return;
        try {
            const resp = await API.getDatabases(connSelect.value);
            (resp.data || []).forEach(db => { const o = document.createElement('option'); o.value = db; o.textContent = db; dbSelect.appendChild(o); });
            dbSelect.disabled = false;
            // Pre-load schema for intellisense when first DB is auto-selected or available
            if (typeof SqlIntellisense !== 'undefined') {
                const firstDb = dbSelect.options[1]?.value || '';
                SqlIntellisense.loadSchema(connSelect.value, firstDb);
            }
        } catch (e) { dbSelect.disabled = false; }

        // Also pre-load default schema (no DB)
        if (typeof SqlIntellisense !== 'undefined') {
            SqlIntellisense.loadSchema(connSelect.value, '');
        }
    },

    onDbChange(id) {
        const connId = document.getElementById(`cj-src-conn-${id}`)?.value;
        const db = document.getElementById(`cj-src-db-${id}`)?.value || '';
        if (connId && typeof SqlIntellisense !== 'undefined') {
            SqlIntellisense.loadSchema(connId, db);
        }
    },

    async previewSource(id) {
        const connId = document.getElementById(`cj-src-conn-${id}`)?.value;
        const db = document.getElementById(`cj-src-db-${id}`)?.value || null;
        let sql = document.getElementById(`cj-src-sql-${id}`)?.value?.trim();
        const previewDiv = document.getElementById(`cj-preview-${id}`);
        if (!previewDiv) return;
        if (!connId) { this.showPreview(previewDiv, 'error', 'Seleccione una conexion'); return; }
        if (!sql) { this.showPreview(previewDiv, 'error', 'Escriba una consulta SQL'); return; }

        const sqlUp = sql.toUpperCase().replace(/\s+/g, ' ').trim();
        let previewSql = sql;
        if (sqlUp.startsWith('SELECT') && !sqlUp.includes(' TOP ') && !sqlUp.includes('LIMIT')) {
            const conn = this.connections.find(c => String(c.id) === String(connId));
            previewSql = (conn && conn.driver === 'sqlsrv')
                ? sql.replace(/^SELECT/i, 'SELECT TOP 1')
                : sql.replace(/;?\s*$/, '') + ' LIMIT 1';
        }
        this.showPreview(previewDiv, 'loading', 'Ejecutando preview...');
        try {
            const resp = await API.request('POST', '/query/execute', { connection_id: parseInt(connId), sql: previewSql, database: db });
            const data = resp.data || {};
            const cols = data.columns || [];
            const rows = data.rows || [];
            let html = `<div style="font-size:12px;color:var(--success);font-weight:600;margin-bottom:4px;">OK &mdash; ${cols.length} columnas</div>`;
            if (cols.length) {
                html += `<div style="overflow-x:auto;border:1px solid var(--border);border-radius:6px;"><table style="width:100%;border-collapse:collapse;font-size:11px;">
                    <thead><tr>${cols.map(c => `<th style="padding:4px 8px;background:#1e293b;color:#e2e8f0;white-space:nowrap;text-align:left;">${this.esc(c)}</th>`).join('')}</tr></thead>
                    <tbody>${rows.map(r => '<tr>' + cols.map(c => { const v = r[c]; return v === null
                        ? '<td style="padding:3px 8px;border-top:1px solid var(--border);color:#94a3b8;font-style:italic;">NULL</td>'
                        : `<td style="padding:3px 8px;border-top:1px solid var(--border);">${this.esc(String(v).substring(0,80))}</td>`;
                    }).join('') + '</tr>').join('')}</tbody></table></div>`;
            }
            this.showPreview(previewDiv, 'html', html);
        } catch (e) { this.showPreview(previewDiv, 'error', e.message || 'Error'); }
    },

    showPreview(div, type, content) {
        div.style.display = '';
        if (type === 'error') div.innerHTML = `<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:6px;padding:8px 12px;color:#991b1b;font-size:12px;">${this.esc(content)}</div>`;
        else if (type === 'loading') div.innerHTML = `<div style="color:var(--text-light);font-size:12px;padding:6px 0;"><span style="display:inline-block;width:12px;height:12px;border:2px solid #0001;border-top-color:var(--primary);border-radius:50%;animation:spin 0.8s linear infinite;vertical-align:middle;margin-right:6px;"></span>${this.esc(content)}</div>`;
        else div.innerHTML = content;
    },

    collectSources() {
        const sources = [];
        for (const s of this.sources) {
            const alias = this.getAlias(s.id);
            const connId = document.getElementById(`cj-src-conn-${s.id}`)?.value;
            const db = document.getElementById(`cj-src-db-${s.id}`)?.value || null;
            const sqlEl = document.getElementById(`cj-src-sql-${s.id}`);
            const sql = sqlEl ? sqlEl.value.trim() : null;
            if (!alias) throw new Error(`Fuente #${s.id}: falta el alias`);
            if (!connId) throw new Error(`Fuente "${alias}": seleccione una conexion`);
            if (sql !== null && !sql) throw new Error(`Fuente "${alias}": escriba una consulta SQL`);
            sources.push({ alias, connection_id: parseInt(connId), sql, database: db });
        }
        if (sources.length < 2) throw new Error('Se requieren al menos 2 fuentes');
        const aliases = sources.map(s => s.alias);
        const dupes = aliases.filter((a, i) => aliases.indexOf(a) !== i);
        if (dupes.length) throw new Error(`Alias duplicado: "${dupes[0]}"`);
        return sources;
    },

    // ═══════════════════════════════════════════════════════════════════
    //  MODE 1: CONSTRUCTOR (visual unified: JOINs + Set Operations)
    // ═══════════════════════════════════════════════════════════════════

    renderConstructor() {
        const content = document.getElementById('cj-mode-content');
        if (!content) return;

        content.innerHTML = `
            ${this.renderSourcesCard('cj-sources', true)}

            <!-- Combine section -->
            <div class="card" style="margin-bottom:16px;">
                <div style="padding:16px 20px;border-bottom:1px solid var(--border);">
                    <h3 style="margin:0;font-size:16px;font-weight:700;">Combinar Resultados</h3>
                </div>
                <div style="padding:16px 20px;">
                    <!-- Category toggle -->
                    <div style="display:flex;gap:0;border:1px solid var(--border);border-radius:8px;overflow:hidden;margin-bottom:14px;">
                        <button id="cj-cat-join" onclick="CrossJoinUI.setCombineMode('join')"
                            style="flex:1;border:none;padding:10px;font-size:13px;font-weight:600;cursor:pointer;background:var(--primary);color:#fff;text-align:center;">
                            JOINs
                        </button>
                        <button id="cj-cat-set" onclick="CrossJoinUI.setCombineMode('set')"
                            style="flex:1;border:none;border-left:1px solid var(--border);padding:10px;font-size:13px;font-weight:600;cursor:pointer;background:var(--bg-card);color:var(--text);text-align:center;">
                            Operaciones de Conjuntos
                        </button>
                    </div>

                    <!-- JOIN mode -->
                    <div id="cj-combine-join">
                        <div id="cj-joins" style="display:flex;flex-direction:column;gap:10px;margin-bottom:12px;">
                            <p style="color:var(--text-light);text-align:center;padding:8px 0;font-size:13px;">Los JOINs se aplican en orden secuencial.</p>
                        </div>
                        <button class="btn" onclick="CrossJoinUI.addJoin()" style="font-size:12px;padding:6px 14px;border:1px solid var(--border);">+ Agregar JOIN</button>
                    </div>

                    <!-- Set operation mode -->
                    <div id="cj-combine-set" style="display:none;">
                        <div style="display:flex;gap:0;border:1px solid var(--border);border-radius:8px;overflow:hidden;flex-wrap:wrap;">
                            <button class="cj-set-btn" data-op="EXCEPT" onclick="CrossJoinUI.selectSetOp('EXCEPT')" style="flex:1;min-width:100px;border:none;padding:10px 6px;font-size:12px;font-weight:600;cursor:pointer;background:var(--primary);color:#fff;text-align:center;">EXCEPT<br><span style="font-size:10px;opacity:0.8;">A sin B</span></button>
                            <button class="cj-set-btn" data-op="INTERSECT" onclick="CrossJoinUI.selectSetOp('INTERSECT')" style="flex:1;min-width:100px;border:none;border-left:1px solid var(--border);padding:10px 6px;font-size:12px;font-weight:600;cursor:pointer;background:var(--bg-card);color:var(--text);text-align:center;">INTERSECT<br><span style="font-size:10px;color:var(--text-light);">A y B</span></button>
                            <button class="cj-set-btn" data-op="UNION" onclick="CrossJoinUI.selectSetOp('UNION')" style="flex:1;min-width:100px;border:none;border-left:1px solid var(--border);padding:10px 6px;font-size:12px;font-weight:600;cursor:pointer;background:var(--bg-card);color:var(--text);text-align:center;">UNION<br><span style="font-size:10px;color:var(--text-light);">Unicos</span></button>
                            <button class="cj-set-btn" data-op="UNION_ALL" onclick="CrossJoinUI.selectSetOp('UNION_ALL')" style="flex:1;min-width:100px;border:none;border-left:1px solid var(--border);padding:10px 6px;font-size:12px;font-weight:600;cursor:pointer;background:var(--bg-card);color:var(--text);text-align:center;">UNION ALL<br><span style="font-size:10px;color:var(--text-light);">Todos</span></button>
                        </div>
                        <div style="margin-top:10px;font-size:12px;color:var(--text-light);line-height:1.6;" id="cj-set-hint">
                            <strong style="color:var(--text);">EXCEPT:</strong> Filas de la primera fuente que NO existen en las demas. Las columnas deben coincidir en cantidad.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action buttons -->
            <div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;">
                <button class="btn btn-primary" id="cj-btn-execute" onclick="CrossJoinUI.executeConstructor()" style="flex:1;padding:14px;font-size:15px;font-weight:700;">Ejecutar</button>
                <button class="btn" onclick="CrossJoinUI.openDrawer()" title="Ver consulta" style="padding:14px 18px;border:1px solid var(--border);font-size:13px;">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </button>
                <button class="btn" onclick="CrossJoinUI.clearAll()" style="padding:14px 18px;border:1px solid var(--border);font-size:13px;">Limpiar</button>
            </div>
            <div id="cj-error" style="display:none;"></div>
            <div id="cj-results" style="display:none;"></div>
        `;

        this.addSource(true);
        this.addSource(true);
        this.addJoin();
    },

    selectedSetOp: 'EXCEPT',

    setCombineMode(mode) {
        this.combineMode = mode;
        document.getElementById('cj-cat-join').style.cssText = `flex:1;border:none;padding:10px;font-size:13px;font-weight:600;cursor:pointer;text-align:center;${mode === 'join' ? 'background:var(--primary);color:#fff;' : 'background:var(--bg-card);color:var(--text);'}`;
        document.getElementById('cj-cat-set').style.cssText = `flex:1;border:none;border-left:1px solid var(--border);padding:10px;font-size:13px;font-weight:600;cursor:pointer;text-align:center;${mode === 'set' ? 'background:var(--primary);color:#fff;' : 'background:var(--bg-card);color:var(--text);'}`;
        document.getElementById('cj-combine-join').style.display = mode === 'join' ? '' : 'none';
        document.getElementById('cj-combine-set').style.display = mode === 'set' ? '' : 'none';
    },

    selectSetOp(op) {
        this.selectedSetOp = op;
        document.querySelectorAll('.cj-set-btn').forEach(btn => {
            const active = btn.dataset.op === op;
            btn.style.background = active ? 'var(--primary)' : 'var(--bg-card)';
            btn.style.color = active ? '#fff' : 'var(--text)';
        });
        const hints = {
            'EXCEPT': '<strong style="color:var(--text);">EXCEPT:</strong> Filas de la primera fuente que NO existen en las demas. Las columnas deben coincidir en cantidad.',
            'INTERSECT': '<strong style="color:var(--text);">INTERSECT:</strong> Solo filas que existen en TODAS las fuentes.',
            'UNION': '<strong style="color:var(--text);">UNION:</strong> Todas las filas, eliminando duplicados.',
            'UNION_ALL': '<strong style="color:var(--text);">UNION ALL:</strong> Todas las filas, incluyendo duplicados.'
        };
        document.getElementById('cj-set-hint').innerHTML = hints[op] || '';
    },

    // ── JOINs ────────────────────────────────────────────────────────

    addJoin() {
        const joinId = Date.now() + Math.random();
        this.joins.push({ id: joinId });
        const container = document.getElementById('cj-joins');
        const p = container.querySelector('p');
        if (p) p.style.display = 'none';

        const aliasOpts = this.getAliasOptions();
        const div = document.createElement('div');
        div.id = `cj-join-${joinId}`;
        div.style.cssText = 'border:1px solid var(--border);border-radius:8px;padding:12px 16px;background:var(--bg-card);border-left:4px solid #f59e0b;';
        div.innerHTML = `
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                <div style="flex:0 0 120px;"><label style="font-size:11px;color:var(--text-light);display:block;margin-bottom:3px;">Fuente izquierda</label>
                    <select class="form-control cj-join-left" style="font-size:12px;">${aliasOpts}</select></div>
                <div style="flex:0 0 140px;"><label style="font-size:11px;color:var(--text-light);display:block;margin-bottom:3px;">Tipo</label>
                    <select class="form-control cj-join-type" style="font-size:12px;font-weight:600;">
                        <option value="INNER">INNER JOIN</option><option value="LEFT">LEFT JOIN</option><option value="RIGHT">RIGHT JOIN</option><option value="CROSS">CROSS JOIN</option>
                    </select></div>
                <div style="flex:0 0 120px;"><label style="font-size:11px;color:var(--text-light);display:block;margin-bottom:3px;">Fuente derecha</label>
                    <select class="form-control cj-join-right" style="font-size:12px;">${aliasOpts}</select></div>
                <div style="flex:1;min-width:120px;" class="cj-join-keys"><label style="font-size:11px;color:var(--text-light);display:block;margin-bottom:3px;">Clave izq = der</label>
                    <div style="display:flex;gap:6px;align-items:center;">
                        <input type="text" class="form-control cj-join-lkey" placeholder="alias.col" style="font-size:12px;flex:1;">
                        <span style="color:var(--text-light);font-weight:700;">=</span>
                        <input type="text" class="form-control cj-join-rkey" placeholder="col" style="font-size:12px;flex:1;">
                    </div></div>
                <button onclick="CrossJoinUI.removeJoin('${joinId}')" style="background:none;border:none;cursor:pointer;color:var(--danger);font-size:18px;padding:4px 8px;margin-bottom:2px;">&times;</button>
            </div>`;
        container.appendChild(div);
        div.querySelector('.cj-join-type').addEventListener('change', function() {
            div.querySelector('.cj-join-keys').style.display = this.value === 'CROSS' ? 'none' : '';
        });
        const right = div.querySelector('.cj-join-right');
        if (right.options.length > 2) right.selectedIndex = 2;
    },

    removeJoin(joinId) {
        this.joins = this.joins.filter(j => String(j.id) !== String(joinId));
        const el = document.getElementById(`cj-join-${joinId}`);
        if (el) el.remove();
        if (!this.joins.length) { const p = document.querySelector('#cj-joins p'); if (p) p.style.display = ''; }
    },

    getAliasOptions() {
        return '<option value="">--</option>' + this.sources.map(s => {
            const a = this.getAlias(s.id); return `<option value="${this.esc(a)}">${this.esc(a || 'src' + s.id)}</option>`;
        }).join('');
    },

    updateJoinSelectors() {
        const opts = this.getAliasOptions();
        document.querySelectorAll('.cj-join-left, .cj-join-right').forEach(sel => { const c = sel.value; sel.innerHTML = opts; sel.value = c; });
    },

    async executeConstructor() {
        if (this.executing) return;
        this.hideError();
        let sources;
        try { sources = this.collectSources(); } catch (e) { this.showError(e.message); return; }

        let payload, endpoint;

        if (this.combineMode === 'set') {
            payload = { sources, operation: this.selectedSetOp };
            endpoint = '/query/set-operation';
        } else {
            // Build joins
            const joins = [];
            document.querySelectorAll('[id^="cj-join-"]').forEach(el => {
                const la = el.querySelector('.cj-join-left')?.value;
                const ra = el.querySelector('.cj-join-right')?.value;
                const type = el.querySelector('.cj-join-type')?.value;
                const lk = el.querySelector('.cj-join-lkey')?.value?.trim() || '';
                const rk = el.querySelector('.cj-join-rkey')?.value?.trim() || '';
                if (!la || !ra) return;
                const j = { left_alias: la, right_alias: ra, type };
                if (type !== 'CROSS') { j.left_key = lk; j.right_key = rk; }
                joins.push(j);
            });
            if (!joins.length) { this.showError('Agregue al menos un JOIN'); return; }
            payload = { sources, joins };
            endpoint = '/query/cross-join';
        }

        this.lastPayload = payload;
        this.setExecuting(true);
        try {
            const resp = await API.request('POST', endpoint, payload);
            this.lastResult = resp.data;
            this.renderResults(resp.data);
        } catch (e) { this.showError(e.message || 'Error desconocido'); }
        finally { this.setExecuting(false); }
    },

    // ═══════════════════════════════════════════════════════════════════
    //  MODE 2: EDITOR SQL LIBRE
    // ═══════════════════════════════════════════════════════════════════

    renderEditor() {
        const content = document.getElementById('cj-mode-content');
        if (!content) return;

        content.innerHTML = `
            ${this.renderSourcesCard('cj-editor-sources', true)}

            <div class="card" style="margin-bottom:16px;">
                <div style="padding:16px 20px;border-bottom:1px solid var(--border);">
                    <h3 style="margin:0;font-size:16px;font-weight:700;">Editor SQL Libre</h3>
                    <p style="margin:4px 0 0;font-size:12px;color:var(--text-light);">
                        Escriba un query referenciando las fuentes como tablas virtuales usando su alias.
                        El sistema trae los datos de cada fuente y ejecuta su query sobre ellos en memoria.
                    </p>
                </div>
                <div style="padding:16px 20px;">
                    <div style="background:#0f172a;border:1px solid #334155;border-radius:8px 8px 0 0;padding:10px 16px;font-size:11px;color:#64748b;line-height:1.7;">
                        <strong style="color:#93c5fd;">Como funciona:</strong> Cada fuente ejecuta su SQL en su servidor y trae datos.
                        Esos datos se convierten en <strong style="color:#86efac;">tablas virtuales</strong> con el nombre del alias (a, b, c...).
                        Aqui abajo escriba su query usando esos alias como si fueran tablas normales.
                        <strong style="color:#fbbf24;">IMPORTANTE:</strong> siempre incluya <code style="color:#f472b6;">FROM alias</code> en su consulta.
                    </div>
                    <textarea id="cj-editor-sql" rows="12" spellcheck="false"
                        placeholder="-- ============================================
-- EJEMPLO 1: INNER JOIN entre dos servidores
-- ============================================
-- Fuente 'a': SELECT product, precio FROM productos
-- Fuente 'b': SELECT product, stock FROM inventario
--
-- Query:
SELECT a.product, a.precio, b.stock
FROM a
INNER JOIN b ON a.product = b.product
WHERE b.stock > 0

-- ============================================
-- EJEMPLO 2: Registros que estan en A pero no en B
-- ============================================
-- Fuente 'a': SELECT id, nombre FROM usuarios  (SQL Server)
-- Fuente 'b': SELECT id, nombre FROM users     (MySQL)
--
-- Query:
SELECT * FROM a EXCEPT SELECT * FROM b

-- ============================================
-- EJEMPLO 3: LEFT JOIN + filtro
-- ============================================
SELECT a.nombre, a.email, b.total
FROM a
LEFT JOIN b ON a.id = b.cliente_id
WHERE a.activo = 1
ORDER BY b.total DESC
LIMIT 50"
                        style="width:100%;font-family:'Fira Code','Cascadia Code','Consolas',monospace;
                               font-size:13px;background:#1e293b;color:#e2e8f0;border:1px solid #334155;
                               border-radius:0 0 8px 8px;padding:14px 16px;resize:vertical;line-height:1.6;tab-size:4;border-top:1px solid #334155;"></textarea>
                </div>
            </div>

            <!-- Quick reference -->
            <details style="margin-bottom:16px;">
                <summary style="cursor:pointer;font-size:13px;font-weight:600;color:var(--text-light);padding:8px 0;">
                    Referencia rapida de sintaxis
                </summary>
                <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:8px;padding:14px 18px;margin-top:6px;font-size:12px;color:var(--text-secondary);line-height:1.8;">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 24px;">
                        <div><code style="color:var(--primary);">SELECT a.col, b.col FROM a</code></div>
                        <div>Seleccionar columnas de fuentes</div>
                        <div><code style="color:var(--primary);">INNER JOIN b ON a.id = b.id</code></div>
                        <div>Combinar por clave</div>
                        <div><code style="color:var(--primary);">LEFT JOIN b ON a.id = b.id</code></div>
                        <div>Todos de A + matches de B</div>
                        <div><code style="color:var(--primary);">WHERE a.col > 10 AND b.col LIKE '%abc%'</code></div>
                        <div>Filtrar resultados</div>
                        <div><code style="color:var(--primary);">SELECT * FROM a EXCEPT SELECT * FROM b</code></div>
                        <div>Registros en A que no estan en B</div>
                        <div><code style="color:var(--primary);">SELECT * FROM a UNION SELECT * FROM b</code></div>
                        <div>Combinar resultados sin duplicados</div>
                        <div><code style="color:var(--primary);">ORDER BY a.col DESC LIMIT 100</code></div>
                        <div>Ordenar y limitar filas</div>
                        <div><code style="color:var(--primary);">GROUP BY a.col HAVING COUNT(*) > 1</code></div>
                        <div>Agrupar con condicion</div>
                    </div>
                </div>
            </details>

            <div style="display:flex;gap:10px;margin-bottom:16px;">
                <button class="btn btn-primary" id="cj-btn-execute" onclick="CrossJoinUI.executeEditor()" style="flex:1;padding:14px;font-size:15px;font-weight:700;">Ejecutar SQL</button>
                <button class="btn" onclick="CrossJoinUI.openDrawer()" title="Ver consulta" style="padding:14px 18px;border:1px solid var(--border);font-size:13px;">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </button>
                <button class="btn" onclick="CrossJoinUI.clearAll()" style="padding:14px 18px;border:1px solid var(--border);font-size:13px;">Limpiar</button>
            </div>
            <div id="cj-error" style="display:none;"></div>
            <div id="cj-results" style="display:none;"></div>
        `;

        this.addSource(true);
        this.addSource(true);

        // Attach intellisense to the main SQL editor
        const editorTa = document.getElementById('cj-editor-sql');
        if (editorTa && typeof SqlIntellisense !== 'undefined') {
            SqlIntellisense.attach(editorTa, {
                getSources: () => this.sources.map(s => ({
                    alias: this.getAlias(s.id),
                    connId: document.getElementById(`cj-src-conn-${s.id}`)?.value,
                    db: document.getElementById(`cj-src-db-${s.id}`)?.value
                }))
            });
            SqlIntellisense.activeTextarea = editorTa;
        }
    },

    async executeEditor() {
        if (this.executing) return;
        this.hideError();

        let sources;
        try { sources = this.collectSources(); } catch (e) { this.showError(e.message); return; }

        const sql = document.getElementById('cj-editor-sql')?.value?.trim();
        if (!sql) { this.showError('Escriba un query SQL'); return; }

        this.lastPayload = { mode: 'editor', sources, sql };
        this.setExecuting(true);

        try {
            const resp = await API.request('POST', '/query/virtual-sql', { sources, sql });
            this.lastResult = resp.data;
            this.renderResults(resp.data);
        } catch (e) { this.showError(e.message || 'Error desconocido'); }
        finally { this.setExecuting(false); }
    },

    // ═══════════════════════════════════════════════════════════════════
    //  SHARED: Execute state, Results, Drawer, Error, etc.
    // ═══════════════════════════════════════════════════════════════════

    setExecuting(state) {
        this.executing = state;
        const btn = document.getElementById('cj-btn-execute');
        if (btn) {
            btn.disabled = state;
            if (state) btn.innerHTML = '<span style="display:inline-block;width:16px;height:16px;border:2px solid #fff3;border-top-color:#fff;border-radius:50%;animation:spin 0.8s linear infinite;vertical-align:middle;margin-right:8px;"></span> Ejecutando...';
            else btn.textContent = this.mode === 'editor' ? 'Ejecutar SQL' : 'Ejecutar';
        }
    },

    renderResults(data) {
        const container = document.getElementById('cj-results');
        if (!container) return;
        container.style.display = '';

        const sc = data.source_counts || {};
        const hasSourceCards = Object.keys(sc).length > 0;
        const sourceCards = Object.entries(sc).map(([alias, count]) =>
            `<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:8px;padding:12px 16px;text-align:center;flex:1;min-width:90px;">
                <div style="font-size:22px;font-weight:800;color:var(--primary);">${count}</div>
                <div style="font-size:11px;color:var(--text-light);">${this.esc(alias)}</div></div>`
        ).join('');

        container.innerHTML = `
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;align-items:center;">
                ${sourceCards}
                <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:8px;padding:12px 16px;text-align:center;flex:1;min-width:90px;">
                    <div style="font-size:22px;font-weight:800;color:var(--success);">${data.row_count || 0}</div>
                    <div style="font-size:11px;color:var(--text-light);">Resultado</div></div>
                <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:8px;padding:12px 16px;text-align:center;flex:1;min-width:90px;">
                    <div style="font-size:22px;font-weight:800;color:var(--warning);">${data.execution_time_ms || 0}ms</div>
                    <div style="font-size:11px;color:var(--text-light);">Tiempo</div></div>
            </div>
            <div style="display:flex;gap:8px;margin-bottom:12px;align-items:center;">
                <span style="font-size:12px;color:var(--text-light);">Exportar:</span>
                <button class="btn btn-sm btn-outline" onclick="CrossJoinUI.exportResults('csv')" style="font-size:11px;padding:4px 12px;">CSV</button>
                <button class="btn btn-sm btn-outline" onclick="CrossJoinUI.exportResults('excel')" style="font-size:11px;padding:4px 12px;">Excel</button>
                <button class="btn btn-sm btn-outline" onclick="CrossJoinUI.exportResults('json')" style="font-size:11px;padding:4px 12px;">JSON</button>
            </div>
            ${this.buildTable(data.columns || [], data.rows || [])}`;
    },

    exportResults(format) {
        if (!this.lastResult || !this.lastResult.columns?.length) return;
        ClientExport.export(this.lastResult.columns, this.lastResult.rows, format, 'crossjoin');
    },

    buildTable(columns, rows) {
        if (!columns.length) return '<p style="text-align:center;color:var(--text-light);padding:20px;">Sin resultados</p>';
        const max = 500;
        const display = rows.slice(0, max);
        const trunc = rows.length > max ? `<p style="text-align:center;color:var(--warning);padding:8px;font-size:12px;">Mostrando ${max} de ${rows.length} filas</p>` : '';

        let h = `${trunc}<div class="table-container" style="max-height:60vh;overflow:auto;">
            <table class="data-table"><thead><tr><th style="width:50px;text-align:center;">#</th>`;
        for (const col of columns) {
            const parts = col.split('.');
            const label = parts.length > 1
                ? `<span style="color:var(--primary);font-weight:700;">${this.esc(parts[0])}</span>.${this.esc(parts.slice(1).join('.'))}`
                : this.esc(col);
            h += `<th>${label}</th>`;
        }
        h += '</tr></thead><tbody>';
        for (let i = 0; i < display.length; i++) {
            const row = display[i];
            h += `<tr><td style="text-align:center;color:var(--text-light);font-size:11px;">${i + 1}</td>`;
            for (const col of columns) {
                const v = row[col];
                if (v === null || v === undefined) {
                    h += '<td><span style="color:var(--text-light);font-style:italic;">NULL</span></td>';
                } else {
                    const s = String(v);
                    h += `<td title="${this.esc(s)}">${this.esc(s.length > 150 ? s.substring(0, 150) + '...' : s)}</td>`;
                }
            }
            h += '</tr>';
        }
        h += '</tbody></table></div>';
        return h;
    },

    // ── Drawer ─────────────────────────────────────────────────────

    openDrawer() {
        const overlay = document.getElementById('cj-drawer-overlay');
        const drawer = document.getElementById('cj-drawer');
        const content = document.getElementById('cj-drawer-content');
        if (!overlay || !drawer || !content) return;

        let html = '';

        // Sources info
        html += '<div style="font-size:13px;font-weight:700;margin-bottom:12px;color:var(--primary);">FUENTES DE DATOS</div>';
        const srcInfos = [];
        for (const s of this.sources) {
            const alias = this.getAlias(s.id);
            const connId = document.getElementById(`cj-src-conn-${s.id}`)?.value;
            const db = document.getElementById(`cj-src-db-${s.id}`)?.value || '(default)';
            const sqlEl = document.getElementById(`cj-src-sql-${s.id}`);
            const sql = sqlEl ? sqlEl.value.trim() : '';
            const conn = this.connections.find(c => String(c.id) === String(connId));
            srcInfos.push({ alias, conn: conn?.name || '?', driver: conn?.driver || '?', db, sql });
            html += `<div style="background:#f1f5f9;border:1px solid var(--border);border-radius:8px;padding:10px 14px;margin-bottom:8px;">
                <div style="display:flex;gap:12px;font-size:12px;${sql ? 'margin-bottom:6px;' : ''}">
                    <span><strong>Fuente:</strong> <span style="color:var(--primary);font-weight:700;">${this.esc(alias)}</span></span>
                    <span><strong>Srv:</strong> ${this.esc(conn?.name || '?')} (${this.esc(conn?.driver || '?')})</span>
                    <span><strong>BD:</strong> ${this.esc(db)}</span>
                </div>
                ${sql ? `<pre style="background:#1e293b;color:#e2e8f0;padding:8px 10px;border-radius:6px;font-size:11px;line-height:1.4;margin:0;overflow-x:auto;white-space:pre-wrap;">${this.esc(sql)}</pre>` : ''}
            </div>`;
        }

        // Mode-specific info
        if (this.mode === 'editor') {
            const editorSql = document.getElementById('cj-editor-sql')?.value?.trim() || '(vacio)';
            html += `<div style="font-size:13px;font-weight:700;margin:20px 0 8px;color:var(--success);">QUERY SQL LIBRE</div>`;
            html += `<pre style="background:#1e293b;color:#e2e8f0;padding:16px;border-radius:8px;font-size:12px;line-height:1.6;overflow-x:auto;white-space:pre-wrap;">${this.esc(editorSql)}</pre>`;
        } else if (this.combineMode === 'set') {
            const opName = this.selectedSetOp === 'UNION_ALL' ? 'UNION ALL' : this.selectedSetOp;
            html += `<div style="font-size:13px;font-weight:700;margin:20px 0 8px;color:var(--success);">CONSULTA EQUIVALENTE (${opName})</div>`;
            const pseudo = srcInfos.map((s, i) =>
                `-- Fuente "${s.alias}" (${s.conn} / ${s.db})\n${s.sql || '...'}` + (i < srcInfos.length - 1 ? `\n\n${opName}\n` : '')
            ).join('\n');
            html += `<pre style="background:#1e293b;color:#e2e8f0;padding:16px;border-radius:8px;font-size:12px;line-height:1.6;overflow-x:auto;white-space:pre-wrap;">${this.esc(pseudo)}</pre>`;
        } else {
            // JOIN mode
            html += `<div style="font-size:13px;font-weight:700;margin:20px 0 8px;color:#f59e0b;">CADENA DE JOINs</div>`;
            document.querySelectorAll('[id^="cj-join-"]').forEach((el, i) => {
                const la = el.querySelector('.cj-join-left')?.value || '?';
                const ra = el.querySelector('.cj-join-right')?.value || '?';
                const type = el.querySelector('.cj-join-type')?.value || '?';
                const lk = el.querySelector('.cj-join-lkey')?.value || '';
                const rk = el.querySelector('.cj-join-rkey')?.value || '';
                const on = type === 'CROSS' ? '(cartesiano)' : `ON ${lk} = ${rk}`;
                html += `<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:8px 12px;margin-bottom:6px;font-size:12px;">
                    <strong>#${i + 1}:</strong> ${this.esc(la)} <strong style="color:#f59e0b;">${type} JOIN</strong> ${this.esc(ra)} ${this.esc(on)}</div>`;
            });
            // Pseudo-SQL
            let pseudo = `SELECT *\nFROM (${srcInfos[0]?.sql || '...'}) AS ${srcInfos[0]?.alias || 'a'}`;
            document.querySelectorAll('[id^="cj-join-"]').forEach(el => {
                const ra = el.querySelector('.cj-join-right')?.value || '?';
                const type = el.querySelector('.cj-join-type')?.value || 'INNER';
                const lk = el.querySelector('.cj-join-lkey')?.value || '?';
                const rk = el.querySelector('.cj-join-rkey')?.value || '?';
                const src = srcInfos.find(s => s.alias === ra);
                pseudo += type === 'CROSS' ? `\nCROSS JOIN (${src?.sql || '...'}) AS ${ra}` : `\n${type} JOIN (${src?.sql || '...'}) AS ${ra}\n  ON ${lk} = ${ra}.${rk}`;
            });
            html += `<div style="font-size:13px;font-weight:700;margin:16px 0 8px;color:var(--success);">CONSULTA EQUIVALENTE</div>`;
            html += `<pre style="background:#1e293b;color:#e2e8f0;padding:16px;border-radius:8px;font-size:12px;line-height:1.6;overflow-x:auto;white-space:pre-wrap;">${this.esc(pseudo)}</pre>`;
        }

        html += `<button onclick="CrossJoinUI.copyDrawer()" class="btn" style="margin-top:16px;width:100%;padding:10px;border:1px solid var(--border);font-size:13px;">Copiar al portapapeles</button>`;
        content.innerHTML = html;
        overlay.style.display = '';
        drawer.style.display = 'flex';
    },

    closeDrawer() {
        const o = document.getElementById('cj-drawer-overlay');
        const d = document.getElementById('cj-drawer');
        if (o) o.style.display = 'none';
        if (d) d.style.display = 'none';
    },

    copyDrawer() {
        const c = document.getElementById('cj-drawer-content');
        if (!c) return;
        navigator.clipboard.writeText(c.innerText).then(() => {
            const btn = c.querySelector('button:last-child');
            if (btn) { const t = btn.textContent; btn.textContent = 'Copiado!'; setTimeout(() => btn.textContent = t, 1500); }
        }).catch(() => {});
    },

    // ── Shared helpers ───────────────────────────────────────────────

    showError(msg) {
        const el = document.getElementById('cj-error');
        if (!el) return;
        el.style.display = '';
        el.innerHTML = `<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:14px 18px;margin-bottom:16px;color:#991b1b;font-size:14px;"><strong>Error:</strong> ${this.esc(msg)}</div>`;
    },

    hideError() {
        const el = document.getElementById('cj-error');
        if (el) { el.style.display = 'none'; el.innerHTML = ''; }
        const r = document.getElementById('cj-results');
        if (r) r.style.display = 'none';
    },

    clearAll() {
        this.sources = [];
        this.joins = [];
        this.sourceCounter = 0;
        this.lastResult = null;
        this.lastPayload = null;
        this.render();
    },

    esc(str) { const d = document.createElement('div'); d.textContent = str || ''; return d.innerHTML; }
};

if (!document.getElementById('cj-spin-style')) {
    const s = document.createElement('style');
    s.id = 'cj-spin-style';
    s.textContent = '@keyframes spin{to{transform:rotate(360deg)}}';
    document.head.appendChild(s);
}
