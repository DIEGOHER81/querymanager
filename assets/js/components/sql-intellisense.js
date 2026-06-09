/**
 * SQL Intellisense - Shared autocomplete + schema drawer for SQL textareas.
 *
 * Features:
 *   - Autocomplete popup: SQL keywords, table names, column names, functions
 *   - Schema drawer: slide-out panel showing DB objects for a connection
 *   - Works on any textarea by attaching with SqlIntellisense.attach(textarea, options)
 *
 * Usage:
 *   SqlIntellisense.attach(document.getElementById('my-textarea'), {
 *       getConnectionId: () => document.getElementById('conn-select').value,
 *       getDatabase: () => document.getElementById('db-select').value,
 *       // Optional: for cross-join sources with aliases
 *       getSources: () => [{ alias: 'a', connId: 1, db: 'mydb' }, ...]
 *   });
 *
 *   SqlIntellisense.openSchemaDrawer(connectionId, database, connectionName);
 */
const SqlIntellisense = {
    // ── State ─────────────────────────────────────────────────────────
    activeTextarea: null,
    popup: null,
    items: [],
    selectedIndex: 0,
    schemaCache: {}, // key: connId_db -> { tables, views, columns: { tableName: [...] } }
    attached: new WeakSet(),

    SQL_KEYWORDS: [
        'SELECT', 'FROM', 'WHERE', 'AND', 'OR', 'NOT', 'IN', 'LIKE', 'BETWEEN',
        'IS', 'NULL', 'AS', 'ON', 'JOIN', 'INNER', 'LEFT', 'RIGHT', 'CROSS', 'FULL', 'OUTER',
        'ORDER', 'BY', 'ASC', 'DESC', 'GROUP', 'HAVING', 'LIMIT', 'TOP', 'DISTINCT',
        'INSERT', 'INTO', 'VALUES', 'UPDATE', 'SET', 'DELETE', 'CREATE', 'ALTER', 'DROP',
        'TABLE', 'INDEX', 'VIEW', 'PROCEDURE', 'FUNCTION', 'TRIGGER', 'DATABASE',
        'UNION', 'ALL', 'EXCEPT', 'INTERSECT', 'EXISTS', 'CASE', 'WHEN', 'THEN', 'ELSE', 'END',
        'COUNT', 'SUM', 'AVG', 'MAX', 'MIN', 'UPPER', 'LOWER', 'TRIM', 'CONCAT',
        'COALESCE', 'ISNULL', 'CAST', 'CONVERT', 'GETDATE', 'NOW', 'DATEADD', 'DATEDIFF',
        'LEN', 'LENGTH', 'SUBSTRING', 'REPLACE', 'CHARINDEX', 'STUFF'
    ],

    // ── Attach to textarea ────────────────────────────────────────────

    attach(textarea, options = {}) {
        if (!textarea || this.attached.has(textarea)) return;
        this.attached.add(textarea);

        textarea._sqlOptions = options;

        textarea.addEventListener('input', (e) => this.onInput(e));
        textarea.addEventListener('keydown', (e) => this.onKeyDown(e));
        textarea.addEventListener('blur', () => setTimeout(() => this.hidePopup(), 150));

        // Preload schema if connection info available
        this.preloadSchema(options);
    },

    async preloadSchema(options) {
        const connId = options.getConnectionId?.();
        const db = options.getDatabase?.();
        if (connId && db) await this.loadSchema(connId, db);

        // Also preload for multi-source setups
        const sources = options.getSources?.();
        if (sources) {
            for (const s of sources) {
                if (s.connId && s.db) await this.loadSchema(s.connId, s.db);
            }
        }
    },

    // ── Schema loading ────────────────────────────────────────────────

    async loadSchema(connId, db) {
        if (!connId) return null;
        db = db || '';
        const key = `${connId}_${db}`;
        if (this.schemaCache[key]) return this.schemaCache[key];

        try {
            const [tablesResp, viewsResp, procsResp, funcsResp] = await Promise.all([
                API.getTables(connId, db),
                API.getViews(connId, db),
                API.getProcedures(connId, db),
                API.getFunctions(connId, db)
            ]);
            const tables = (tablesResp.data || []).map(t => t.name || t);
            const views = (viewsResp.data || []).map(v => v.name || v);
            const procedures = (procsResp.data || []).map(p => p.name || p);
            const functions = (funcsResp.data || []).map(f => f.name || f);

            this.schemaCache[key] = { tables, views, procedures, functions, columns: {} };
            return this.schemaCache[key];
        } catch (e) {
            return { tables: [], views: [], procedures: [], functions: [], columns: {} };
        }
    },

    async loadColumns(connId, db, tableName) {
        if (!connId) return [];
        db = db || '';
        const key = `${connId}_${db}`;
        if (!this.schemaCache[key]) await this.loadSchema(connId, db);
        const schema = this.schemaCache[key];
        if (!schema) return [];
        if (schema.columns[tableName]) return schema.columns[tableName];

        try {
            const resp = await API.getColumns(connId, tableName, db);
            const cols = (resp.data || []).map(c => ({
                name: c.name || c.COLUMN_NAME || c.column_name,
                type: c.type || c.DATA_TYPE || c.data_type || '',
                nullable: c.nullable || c.IS_NULLABLE || ''
            }));
            schema.columns[tableName] = cols;
            return cols;
        } catch (e) { return []; }
    },

    // ── Autocomplete logic ────────────────────────────────────────────

    _requestId: 0,
    _debounceTimer: null,

    onInput(e) {
        const textarea = e.target;
        this.activeTextarea = textarea;
        const word = this.getCurrentWord(textarea);

        if (!word || word.length < 3) { this.hidePopup(); return; }

        // Debounce: wait 200ms after last keystroke before searching
        clearTimeout(this._debounceTimer);
        this._debounceTimer = setTimeout(() => {
            this.buildSuggestions(textarea, word);
        }, 200);
    },

    getCurrentWord(textarea) {
        const pos = textarea.selectionStart;
        const text = textarea.value.substring(0, pos);
        const match = text.match(/[\w.]+$/);
        return match ? match[0] : '';
    },

    async buildSuggestions(textarea, word) {
        // Cancel any previous in-flight request
        const requestId = ++this._requestId;

        const options = textarea._sqlOptions || {};
        const upper = word.toUpperCase();
        const items = [];

        // Check if word has a dot (alias.table or table.column)
        const dotIndex = word.lastIndexOf('.');
        if (dotIndex > 0) {
            const prefix = word.substring(0, dotIndex);
            const suffix = word.substring(dotIndex + 1).toUpperCase();

            // Only use CACHED data for dot-completion
            const sources = options.getSources?.() || [];
            for (const src of sources) {
                if (src.alias && src.alias.toLowerCase() === prefix.toLowerCase() && src.connId) {
                    const schema = this.schemaCache[`${src.connId}_${src.db || ''}`];
                    if (schema) {
                        // Suggest tables/views of this source
                        for (const t of [...schema.tables, ...schema.views]) {
                            if (!suffix || t.toUpperCase().includes(suffix)) {
                                items.push({ text: `${prefix}.${t}`, label: t, detail: 'tabla', icon: 'T' });
                            }
                        }
                        // Suggest columns from cached tables
                        for (const t of schema.tables) {
                            const cols = schema.columns[t];
                            if (cols) {
                                for (const c of cols) {
                                    if (!suffix || c.name.toUpperCase().includes(suffix)) {
                                        items.push({ text: `${prefix}.${c.name}`, label: c.name, detail: `${t}`, icon: 'C' });
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // Try as tableName.column from direct connection cache
            const connId = options.getConnectionId?.();
            const db = options.getDatabase?.() || '';
            if (connId) {
                const schema = this.schemaCache[`${connId}_${db}`];
                if (schema && schema.columns[prefix]) {
                    for (const c of schema.columns[prefix]) {
                        if (!suffix || c.name.toUpperCase().includes(suffix)) {
                            items.push({ text: `${prefix}.${c.name}`, label: c.name, detail: c.type, icon: 'C' });
                        }
                    }
                }
            }
        } else {
            // Only use CACHED schemas - never await API calls during typing
            // Schemas are loaded when connections/BDs change (via preloadSchema)

            // From direct connection
            const connId = options.getConnectionId?.();
            const db = options.getDatabase?.() || '';
            if (connId) {
                const schema = this.schemaCache[`${connId}_${db}`];
                if (schema) {
                    for (const t of schema.tables) { if (t.toUpperCase().includes(upper)) items.push({ text: t, label: t, detail: 'tabla', icon: 'T' }); }
                    for (const v of schema.views) { if (v.toUpperCase().includes(upper)) items.push({ text: v, label: v, detail: 'vista', icon: 'V' }); }
                    for (const p of (schema.procedures || [])) { if (p.toUpperCase().includes(upper)) items.push({ text: p, label: p, detail: 'proc', icon: 'P' }); }
                }
            }

            // From multi-source setups
            const sources = options.getSources?.() || [];
            for (const src of sources) {
                if (src.connId) {
                    const schema = this.schemaCache[`${src.connId}_${src.db || ''}`];
                    if (schema) {
                        const pref = src.alias ? `${src.alias}: ` : '';
                        for (const t of schema.tables) { if (t.toUpperCase().includes(upper)) items.push({ text: t, label: t, detail: `${pref}tabla`, icon: 'T' }); }
                        for (const v of schema.views) { if (v.toUpperCase().includes(upper)) items.push({ text: v, label: v, detail: `${pref}vista`, icon: 'V' }); }
                    }
                }
                // Source aliases
                if (src.alias && src.alias.toUpperCase().includes(upper)) {
                    items.push({ text: src.alias, label: src.alias, detail: `fuente (${src.db || '?'})`, icon: 'S' });
                }
            }

            // SQL keywords (only if few schema matches)
            if (items.length < 5) {
                for (const kw of this.SQL_KEYWORDS) {
                    if (kw.startsWith(upper)) items.push({ text: kw, label: kw, detail: 'keyword', icon: 'K' });
                }
            }
        }

        // If this request is stale (user typed more, or moved to another textarea), discard
        if (requestId !== this._requestId || this.activeTextarea !== textarea) return;

        // Deduplicate and sort: tables/columns first, keywords last
        const seen = new Set();
        const priority = { S: 0, T: 1, V: 2, C: 3, K: 4, P: 4 };
        this.items = items
            .filter(item => { if (seen.has(item.text)) return false; seen.add(item.text); return true; })
            .sort((a, b) => (priority[a.icon] || 9) - (priority[b.icon] || 9))
            .slice(0, 12);

        this.selectedIndex = 0;
        if (this.items.length > 0 && requestId === this._requestId && this.activeTextarea === textarea) {
            this.showPopup(textarea);
        } else {
            this.hidePopup();
        }
    },

    onKeyDown(e) {
        if (!this.popup || this.popup.style.display === 'none') {
            // Tab inserts spaces
            if (e.key === 'Tab') {
                e.preventDefault();
                const ta = e.target;
                const start = ta.selectionStart;
                ta.value = ta.value.substring(0, start) + '    ' + ta.value.substring(ta.selectionEnd);
                ta.selectionStart = ta.selectionEnd = start + 4;
            }
            return;
        }

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            this.selectedIndex = Math.min(this.selectedIndex + 1, this.items.length - 1);
            this.updatePopupSelection();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            this.selectedIndex = Math.max(this.selectedIndex - 1, 0);
            this.updatePopupSelection();
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            if (this.items.length > 0) {
                e.preventDefault();
                this.acceptSuggestion(this.items[this.selectedIndex]);
            }
        } else if (e.key === 'Escape') {
            this.hidePopup();
        }
    },

    acceptSuggestion(item) {
        const textarea = this.activeTextarea;
        if (!textarea) return;
        const pos = textarea.selectionStart;
        const text = textarea.value;
        const before = text.substring(0, pos);
        const match = before.match(/[\w.]+$/);
        if (!match) return;
        const wordStart = pos - match[0].length;
        textarea.value = text.substring(0, wordStart) + item.text + text.substring(pos);
        textarea.selectionStart = textarea.selectionEnd = wordStart + item.text.length;
        textarea.focus();
        this.hidePopup();
        // Trigger input event for any listeners
        textarea.dispatchEvent(new Event('input'));
    },

    // ── Popup UI ──────────────────────────────────────────────────────

    showPopup(textarea) {
        if (!this.popup) this.createPopup();
        const rect = textarea.getBoundingClientRect();

        // Position: bottom-left of the textarea, outside of the editing area
        this.popup.style.display = 'block';
        this.popup.style.left = rect.left + 'px';
        this.popup.style.top = (rect.bottom + 4) + 'px';

        // If popup goes below viewport, show above textarea instead
        const popupH = Math.min(this.items.length * 30, 220);
        if (rect.bottom + 4 + popupH > window.innerHeight) {
            this.popup.style.top = (rect.top - popupH - 4) + 'px';
        }

        const iconColors = { K: '#93c5fd', T: '#86efac', V: '#c4b5fd', C: '#fbbf24', S: '#f472b6', P: '#34d399' };

        this.popup.innerHTML = this.items.map((item, i) => `
            <div class="si-item ${i === this.selectedIndex ? 'si-selected' : ''}"
                 onmousedown="SqlIntellisense.acceptSuggestion(SqlIntellisense.items[${i}])"
                 onmouseenter="SqlIntellisense.selectedIndex=${i};SqlIntellisense.updatePopupSelection()">
                <span class="si-icon" style="color:${iconColors[item.icon] || '#94a3b8'};">${item.icon}</span>
                <span class="si-label">${this.esc(item.label)}</span>
                <span class="si-detail">${this.esc(item.detail)}</span>
            </div>
        `).join('');
    },

    hidePopup() {
        if (this.popup) this.popup.style.display = 'none';
    },

    updatePopupSelection() {
        if (!this.popup) return;
        this.popup.querySelectorAll('.si-item').forEach((el, i) => {
            el.classList.toggle('si-selected', i === this.selectedIndex);
        });
        // Scroll into view
        const selected = this.popup.querySelector('.si-selected');
        if (selected) selected.scrollIntoView({ block: 'nearest' });
    },

    createPopup() {
        this.popup = document.createElement('div');
        this.popup.id = 'sql-intellisense-popup';
        this.popup.style.cssText = `
            display:none;position:fixed;z-index:10000;
            background:#1e293bee;border:1px solid #334155;border-radius:6px;
            box-shadow:0 4px 12px rgba(0,0,0,0.2);backdrop-filter:blur(8px);
            max-height:180px;overflow-y:auto;min-width:220px;max-width:360px;
            font-family:'Fira Code','Cascadia Code','Consolas',monospace;font-size:12px;
        `;
        document.body.appendChild(this.popup);

        // Add styles
        if (!document.getElementById('si-styles')) {
            const style = document.createElement('style');
            style.id = 'si-styles';
            style.textContent = `
                .si-item { display:flex;align-items:center;gap:8px;padding:6px 10px;cursor:pointer;color:#e2e8f0; }
                .si-item:hover, .si-selected { background:#334155; }
                .si-icon { width:18px;text-align:center;font-weight:700;font-size:11px;flex-shrink:0; }
                .si-label { flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
                .si-detail { font-size:10px;color:#64748b;flex-shrink:0; }
            `;
            document.head.appendChild(style);
        }
    },

    getCaretCoordinates(textarea) {
        // Approximate caret position
        const text = textarea.value.substring(0, textarea.selectionStart);
        const lines = text.split('\n');
        const lineNum = lines.length - 1;
        const colNum = lines[lines.length - 1].length;
        const lineHeight = parseFloat(getComputedStyle(textarea).lineHeight) || 18;
        const charWidth = 7.8; // approximate monospace char width at 12-13px
        return {
            top: Math.min(lineNum * lineHeight, textarea.clientHeight - 20) - textarea.scrollTop,
            left: Math.min(colNum * charWidth, textarea.clientWidth - 100) - textarea.scrollLeft + 10
        };
    },

    // ── Schema Drawer ─────────────────────────────────────────────────

    async openSchemaDrawer(connId, db, connName) {
        if (!connId) return;

        // Create drawer if not exists
        let overlay = document.getElementById('si-drawer-overlay');
        let drawer = document.getElementById('si-drawer');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'si-drawer-overlay';
            overlay.style.cssText = 'display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.4);z-index:998;';
            overlay.onclick = () => this.closeSchemaDrawer();
            document.body.appendChild(overlay);
        }
        if (!drawer) {
            drawer = document.createElement('div');
            drawer.id = 'si-drawer';
            drawer.style.cssText = 'display:none;position:fixed;top:0;right:0;width:380px;max-width:85vw;height:100vh;background:var(--bg-card);box-shadow:-4px 0 20px rgba(0,0,0,0.15);z-index:999;flex-direction:column;overflow:hidden;';
            document.body.appendChild(drawer);
        }

        overlay.style.display = '';
        drawer.style.display = 'flex';

        drawer.innerHTML = `
            <div style="display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid var(--border);flex-shrink:0;">
                <div>
                    <h3 style="margin:0;font-size:15px;font-weight:700;">Objetos de BD</h3>
                    <p style="margin:2px 0 0;font-size:11px;color:var(--text-light);">${this.esc(connName || '')} / ${this.esc(db || '(default)')}</p>
                </div>
                <button onclick="SqlIntellisense.closeSchemaDrawer()" style="background:none;border:none;cursor:pointer;font-size:22px;color:var(--text-light);padding:4px 8px;">&times;</button>
            </div>
            <div style="padding:12px 18px;flex-shrink:0;border-bottom:1px solid var(--border);">
                <input type="text" class="form-control" id="si-drawer-search" placeholder="Buscar tabla o columna..."
                       oninput="SqlIntellisense.filterDrawer(this.value)"
                       style="font-size:12px;padding:8px 12px;">
            </div>
            <div id="si-drawer-content" style="flex:1;overflow-y:auto;padding:12px 18px;">
                <div style="text-align:center;padding:30px;"><div class="spinner" style="margin:0 auto;"></div></div>
            </div>
        `;

        // Load schema
        await this.loadSchema(connId, db);
        const schema = this.schemaCache[`${connId}_${db}`];
        if (!schema) {
            document.getElementById('si-drawer-content').innerHTML = '<p style="color:var(--danger);padding:20px;">Error al cargar esquema</p>';
            return;
        }

        // Render tree
        await this.renderSchemaTree(connId, db, schema);
    },

    async loadRoutineParams(connId, db, routineName) {
        try {
            const resp = await API.getRoutineParams(connId, routineName, db);
            return (resp.data || []).map(p => ({
                name: p.name || p.PARAMETER_NAME || '',
                type: p.type || p.DATA_TYPE || '',
                mode: p.mode || p.PARAMETER_MODE || 'IN'
            }));
        } catch (e) { return []; }
    },

    async renderSchemaTree(connId, db, schema) {
        const content = document.getElementById('si-drawer-content');
        if (!content) return;

        const iconTable = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px;opacity:0.6;"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="3" x2="9" y2="21"/></svg>';
        const iconView = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px;opacity:0.6;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
        const iconProc = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px;opacity:0.6;"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>';
        const iconFunc = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" style="vertical-align:middle;margin-right:4px;opacity:0.6;"><text x="3" y="16" font-size="14" fill="#64748b" font-style="italic" font-family="serif">fx</text></svg>';

        const buildSection = (title, icon, items, type, hoverColor) => {
            if (!items.length) return '';
            let h = `<div class="si-section" data-type="${type}">
                <div class="si-section-header" onclick="this.nextElementSibling.classList.toggle('si-collapsed')" style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;cursor:pointer;font-size:13px;font-weight:600;color:var(--text);border-bottom:1px solid var(--border);">
                    <span>${icon} ${title}</span><span style="font-size:11px;color:var(--text-light);font-weight:400;">${items.length}</span>
                </div>
                <div class="si-section-items">`;
            for (const name of items) {
                const expandType = (type === 'tables' || type === 'views') ? 'columns' : 'params';
                h += `<div class="si-tree-item" data-name="${this.esc(name.toLowerCase())}">
                    <div onclick="SqlIntellisense.toggleChildren(this, ${connId}, '${this.esc(db)}', '${this.esc(name)}', '${expandType}')"
                         style="display:flex;align-items:center;gap:4px;padding:6px 4px;cursor:pointer;font-size:12px;color:var(--text);border-radius:4px;"
                         onmouseenter="this.style.background='${hoverColor}'" onmouseleave="this.style.background=''">
                        <span class="si-arrow" style="font-size:9px;color:var(--text-light);width:14px;text-align:center;">&#9654;</span>
                        ${icon}<span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${this.esc(name)}</span>
                        <button onclick="event.stopPropagation();SqlIntellisense.insertText('${this.esc(name)}')" title="Insertar nombre"
                                style="background:none;border:none;cursor:pointer;font-size:10px;color:var(--primary);padding:2px 4px;opacity:0.6;"
                                onmouseenter="this.style.opacity='1'" onmouseleave="this.style.opacity='0.6'">+</button>
                    </div>
                    <div class="si-children" style="display:none;padding-left:22px;"></div>
                </div>`;
            }
            h += '</div></div>';
            return h;
        };

        let html = '';
        html += buildSection('Tablas', iconTable, schema.tables, 'tables', 'var(--primary-light)');
        html += buildSection('Vistas', iconView, schema.views, 'views', '#ede9fe');
        html += buildSection('Procedimientos', iconProc, schema.procedures || [], 'procs', '#dcfce7');
        html += buildSection('Funciones', iconFunc, schema.functions || [], 'funcs', '#fff7ed');

        if (!html) html = '<p style="text-align:center;color:var(--text-light);padding:20px;">Sin objetos</p>';
        content.innerHTML = html;

        if (!document.getElementById('si-tree-styles')) {
            const s = document.createElement('style');
            s.id = 'si-tree-styles';
            s.textContent = '.si-collapsed { display:none !important; }';
            document.head.appendChild(s);
        }
    },

    async toggleChildren(headerEl, connId, db, objectName, childType) {
        const parent = headerEl.parentElement;
        const childDiv = parent.querySelector('.si-children');
        const arrow = headerEl.querySelector('.si-arrow');

        if (childDiv.style.display === 'none') {
            childDiv.style.display = '';
            arrow.innerHTML = '&#9660;';

            if (!childDiv.dataset.loaded) {
                childDiv.innerHTML = '<div style="font-size:11px;color:var(--text-light);padding:4px 0;">Cargando...</div>';

                if (childType === 'columns') {
                    const cols = await this.loadColumns(connId, db, objectName);
                    if (cols.length) {
                        childDiv.innerHTML = cols.map(c => `
                            <div style="display:flex;align-items:center;gap:6px;padding:3px 4px;font-size:11px;border-radius:3px;cursor:pointer;"
                                 onmouseenter="this.style.background='#f1f5f9'" onmouseleave="this.style.background=''"
                                 onclick="SqlIntellisense.insertText('${this.esc(c.name)}')" title="Clic para insertar">
                                <span style="color:#fbbf24;font-size:10px;font-weight:700;width:12px;">C</span>
                                <span style="flex:1;color:var(--text);">${this.esc(c.name)}</span>
                                <span style="color:var(--text-light);font-size:10px;">${this.esc(c.type)}${c.nullable === 'YES' ? ' null' : ''}</span>
                            </div>
                        `).join('');
                    } else {
                        childDiv.innerHTML = '<div style="font-size:11px;color:var(--text-light);padding:4px 0;">Sin columnas</div>';
                    }
                } else if (childType === 'params') {
                    const params = await this.loadRoutineParams(connId, db, objectName);
                    if (params.length) {
                        childDiv.innerHTML = params.map(p => {
                            const modeColors = { IN: '#3b82f6', OUT: '#f59e0b', INOUT: '#8b5cf6' };
                            const modeColor = modeColors[p.mode] || '#94a3b8';
                            return `
                            <div style="display:flex;align-items:center;gap:6px;padding:3px 4px;font-size:11px;border-radius:3px;cursor:pointer;"
                                 onmouseenter="this.style.background='#f1f5f9'" onmouseleave="this.style.background=''"
                                 onclick="SqlIntellisense.insertText('${this.esc(p.name)}')" title="Clic para insertar">
                                <span style="color:${modeColor};font-size:9px;font-weight:700;width:28px;">${this.esc(p.mode)}</span>
                                <span style="flex:1;color:var(--text);">${this.esc(p.name)}</span>
                                <span style="color:var(--text-light);font-size:10px;">${this.esc(p.type)}</span>
                            </div>`;
                        }).join('');
                    } else {
                        childDiv.innerHTML = '<div style="font-size:11px;color:var(--text-light);padding:4px 0;">Sin parametros</div>';
                    }
                }
                childDiv.dataset.loaded = '1';
            }
        } else {
            childDiv.style.display = 'none';
            arrow.innerHTML = '&#9654;';
        }
    },

    insertText(text) {
        // Insert into the last active textarea
        if (this.activeTextarea) {
            const ta = this.activeTextarea;
            const pos = ta.selectionStart;
            ta.value = ta.value.substring(0, pos) + text + ta.value.substring(ta.selectionEnd);
            ta.selectionStart = ta.selectionEnd = pos + text.length;
            ta.focus();
        }
    },

    filterDrawer(query) {
        const lower = query.toLowerCase();
        document.querySelectorAll('.si-tree-item').forEach(item => {
            const name = item.dataset.name || '';
            item.style.display = !lower || name.includes(lower) ? '' : 'none';
        });
    },

    closeSchemaDrawer() {
        const o = document.getElementById('si-drawer-overlay');
        const d = document.getElementById('si-drawer');
        if (o) o.style.display = 'none';
        if (d) d.style.display = 'none';
    },

    // ── Helpers ────────────────────────────────────────────────────────

    esc(str) { const d = document.createElement('div'); d.textContent = str || ''; return d.innerHTML; }
};
