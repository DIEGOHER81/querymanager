/**
 * Schema Compare UI
 * Compares database schemas between two connections, showing differences
 * in tables, views, procedures and functions. Generates migration scripts.
 */
const SchemaCompareUI = {
    connections: [],
    direction: 'a_to_b', // 'a_to_b' | 'b_to_a' | 'bidirectional'
    comparing: false,
    lastDiff: null,
    lastScript: null,
    activeTab: 'tables',

    async load() {
        try {
            const resp = await API.getConnections();
            this.connections = resp.data || [];
        } catch (e) { this.connections = []; }
        this.lastDiff = null;
        this.lastScript = null;
        this.direction = 'a_to_b';
        this.activeTab = 'tables';
        this.render();
    },

    render() {
        const panel = document.getElementById('panel-compare-content');
        if (!panel) return;

        panel.innerHTML = `
            ${this.renderSelectionForm()}
            <div id="sc-results"></div>
            <div id="sc-script-section"></div>
        `;

        if (this.lastDiff) {
            this.renderResults();
        }
    },

    // ═══════════════════════════════════════════════════════════════════
    //  SELECTION FORM
    // ═══════════════════════════════════════════════════════════════════

    renderSelectionForm() {
        const connOptions = this.connections.map(c =>
            `<option value="${c.id}">${this.esc(c.name)} (${c.driver})</option>`
        ).join('');

        return `
        <div class="card" style="margin-bottom:20px;">
            <div style="padding:16px 20px;border-bottom:1px solid var(--border);">
                <h3 style="margin:0;font-size:16px;font-weight:700;">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:6px;">
                        <path d="M16 3h5v5"/><path d="M8 3H3v5"/><path d="M12 22v-8.3a4 4 0 0 0-1.172-2.872L3 3"/><path d="m15 9 6-6"/>
                    </svg>
                    Comparar Esquemas
                </h3>
            </div>
            <div style="padding:20px;">
                <!-- Two side-by-side cards -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
                    <!-- Conexion A -->
                    <div style="border:1px solid var(--border);border-left:4px solid #3b82f6;border-radius:8px;padding:16px;background:var(--bg-card);">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
                            <span style="width:10px;height:10px;border-radius:50%;background:#3b82f6;display:inline-block;"></span>
                            <span style="font-weight:700;font-size:14px;">Conexion A (Origen)</span>
                        </div>
                        <div style="margin-bottom:10px;">
                            <label style="font-size:11px;color:var(--text-light);display:block;margin-bottom:3px;">Conexion</label>
                            <select class="form-control" id="sc-conn-a" onchange="SchemaCompareUI.onConnChange('a')" style="font-size:12px;">
                                <option value="">-- Seleccionar --</option>${connOptions}
                            </select>
                        </div>
                        <div>
                            <label style="font-size:11px;color:var(--text-light);display:block;margin-bottom:3px;">Base de datos</label>
                            <select class="form-control" id="sc-db-a" style="font-size:12px;" disabled>
                                <option value="">-- BD --</option>
                            </select>
                        </div>
                    </div>
                    <!-- Conexion B -->
                    <div style="border:1px solid var(--border);border-left:4px solid #8b5cf6;border-radius:8px;padding:16px;background:var(--bg-card);">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
                            <span style="width:10px;height:10px;border-radius:50%;background:#8b5cf6;display:inline-block;"></span>
                            <span style="font-weight:700;font-size:14px;">Conexion B (Destino)</span>
                        </div>
                        <div style="margin-bottom:10px;">
                            <label style="font-size:11px;color:var(--text-light);display:block;margin-bottom:3px;">Conexion</label>
                            <select class="form-control" id="sc-conn-b" onchange="SchemaCompareUI.onConnChange('b')" style="font-size:12px;">
                                <option value="">-- Seleccionar --</option>${connOptions}
                            </select>
                        </div>
                        <div>
                            <label style="font-size:11px;color:var(--text-light);display:block;margin-bottom:3px;">Base de datos</label>
                            <select class="form-control" id="sc-db-b" style="font-size:12px;" disabled>
                                <option value="">-- BD --</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Direction selector -->
                <div style="display:flex;align-items:center;justify-content:center;gap:0;margin-bottom:20px;">
                    <span style="font-size:12px;color:var(--text-light);margin-right:12px;font-weight:600;">Direccion:</span>
                    <div style="display:flex;border:1px solid var(--border);border-radius:8px;overflow:hidden;">
                        <button id="sc-dir-atob" onclick="SchemaCompareUI.setDirection('a_to_b')"
                            style="border:none;padding:8px 18px;font-size:12px;font-weight:600;cursor:pointer;
                                   ${this.direction === 'a_to_b' ? 'background:var(--primary);color:#fff;' : 'background:var(--bg-card);color:var(--text);'}">
                            A &rarr; B
                        </button>
                        <button id="sc-dir-btoa" onclick="SchemaCompareUI.setDirection('b_to_a')"
                            style="border:none;padding:8px 18px;font-size:12px;font-weight:600;cursor:pointer;border-left:1px solid var(--border);border-right:1px solid var(--border);
                                   ${this.direction === 'b_to_a' ? 'background:var(--primary);color:#fff;' : 'background:var(--bg-card);color:var(--text);'}">
                            B &rarr; A
                        </button>
                        <button id="sc-dir-bidi" onclick="SchemaCompareUI.setDirection('bidirectional')"
                            style="border:none;padding:8px 18px;font-size:12px;font-weight:600;cursor:pointer;
                                   ${this.direction === 'bidirectional' ? 'background:var(--primary);color:#fff;' : 'background:var(--bg-card);color:var(--text);'}">
                            Bidireccional
                        </button>
                    </div>
                </div>

                <!-- Compare button -->
                <div style="text-align:center;">
                    <button class="btn btn-primary" onclick="SchemaCompareUI.compare()" style="padding:10px 40px;font-size:14px;font-weight:700;"
                        ${this.comparing ? 'disabled' : ''}>
                        ${this.comparing
                            ? '<span style="display:inline-block;width:14px;height:14px;border:2px solid #fff3;border-top-color:#fff;border-radius:50%;animation:spin 0.8s linear infinite;vertical-align:middle;margin-right:8px;"></span>Comparando...'
                            : '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:6px;"><path d="M16 3h5v5"/><path d="M8 3H3v5"/><path d="M12 22v-8.3a4 4 0 0 0-1.172-2.872L3 3"/><path d="m15 9 6-6"/></svg>Comparar Esquemas'}
                    </button>
                </div>
            </div>
        </div>`;
    },

    setDirection(dir) {
        this.direction = dir;
        // Update button styles without full re-render
        ['a_to_b', 'b_to_a', 'bidirectional'].forEach(d => {
            const ids = { a_to_b: 'sc-dir-atob', b_to_a: 'sc-dir-btoa', bidirectional: 'sc-dir-bidi' };
            const btn = document.getElementById(ids[d]);
            if (!btn) return;
            if (d === dir) {
                btn.style.background = 'var(--primary)';
                btn.style.color = '#fff';
            } else {
                btn.style.background = 'var(--bg-card)';
                btn.style.color = 'var(--text)';
            }
        });
    },

    async onConnChange(side) {
        const connSelect = document.getElementById(`sc-conn-${side}`);
        const dbSelect = document.getElementById(`sc-db-${side}`);
        if (!connSelect || !dbSelect) return;
        dbSelect.innerHTML = '<option value="">-- BD --</option>';
        dbSelect.disabled = true;
        if (!connSelect.value) return;
        try {
            const resp = await API.getDatabases(connSelect.value);
            (resp.data || []).forEach(db => {
                const o = document.createElement('option');
                o.value = db;
                o.textContent = db;
                dbSelect.appendChild(o);
            });
            dbSelect.disabled = false;
        } catch (e) {
            dbSelect.disabled = false;
        }
    },

    // ═══════════════════════════════════════════════════════════════════
    //  COMPARISON
    // ═══════════════════════════════════════════════════════════════════

    async compare() {
        const connA = document.getElementById('sc-conn-a')?.value;
        const dbA = document.getElementById('sc-db-a')?.value;
        const connB = document.getElementById('sc-conn-b')?.value;
        const dbB = document.getElementById('sc-db-b')?.value;

        if (!connA || !dbA || !connB || !dbB) {
            this.showError('Seleccione conexion y base de datos en ambos lados.');
            return;
        }

        this.comparing = true;
        this.lastDiff = null;
        this.lastScript = null;

        const resultsEl = document.getElementById('sc-results');
        const scriptEl = document.getElementById('sc-script-section');
        if (scriptEl) scriptEl.innerHTML = '';

        // Progress bar UI
        if (resultsEl) resultsEl.innerHTML = `
            <div class="card" style="padding:30px;margin-bottom:16px;">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                    <div class="spinner" style="width:20px;height:20px;border:2px solid var(--border);border-top-color:var(--primary);border-radius:50%;animation:spin 0.8s linear infinite;flex-shrink:0;"></div>
                    <span style="font-size:14px;font-weight:600;color:var(--text);" id="sc-progress-text">Comparando esquemas...</span>
                </div>
                <div style="background:var(--border);border-radius:6px;height:8px;overflow:hidden;">
                    <div id="sc-progress-bar" style="background:var(--primary);height:100%;width:0%;border-radius:6px;transition:width 0.3s;"></div>
                </div>
                <div id="sc-progress-step" style="font-size:11px;color:var(--text-light);margin-top:8px;">Iniciando...</div>
            </div>`;

        const setProgress = (pct, step) => {
            const bar = document.getElementById('sc-progress-bar');
            const stepEl = document.getElementById('sc-progress-step');
            const textEl = document.getElementById('sc-progress-text');
            if (bar) bar.style.width = pct + '%';
            if (stepEl) stepEl.textContent = step;
            if (textEl) textEl.textContent = `Comparando esquemas... ${pct}%`;
        };

        try {
            setProgress(10, 'Conectando a las bases de datos...');
            await new Promise(r => setTimeout(r, 50)); // Allow UI update

            setProgress(20, 'Obteniendo esquemas de ambas conexiones...');
            const resp = await API.request('POST', '/schema-compare/compare', {
                connA: parseInt(connA), dbA, connB: parseInt(connB), dbB
            });

            setProgress(90, 'Procesando resultados...');
            await new Promise(r => setTimeout(r, 50));

            this.lastDiff = resp.data;
            this._driverA = resp.data?.driverA;
            this._driverB = resp.data?.driverB;
            this._lastConnA = connA;
            this._lastDbA = dbA;
            this._lastConnB = connB;
            this._lastDbB = dbB;
            this.activeTab = 'tables';
            this.statusFilter = 'all';

            setProgress(100, 'Comparacion completada.');
            await new Promise(r => setTimeout(r, 200));

            this.renderResults();
        } catch (e) {
            this.showError(e.message || 'Error al comparar esquemas.');
        } finally {
            this.comparing = false;
        }
    },

    showError(msg) {
        const el = document.getElementById('sc-results');
        if (el) {
            el.innerHTML = `<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:14px 18px;margin-bottom:16px;color:#991b1b;font-size:14px;">
                <strong>Error:</strong> ${this.esc(msg)}</div>`;
        }
    },

    // ═══════════════════════════════════════════════════════════════════
    //  RESULTS
    // ═══════════════════════════════════════════════════════════════════

    renderResults() {
        const container = document.getElementById('sc-results');
        if (!container || !this.lastDiff) return;

        // Backend returns { diff: { tables, views, procedures, functions, summary }, driverA, driverB }
        const raw = this.lastDiff.diff || this.lastDiff;
        const diff = this.normalizeDiff(raw);
        this._normalizedDiff = diff;
        const stats = this.computeStats(diff);

        container.innerHTML = `
            ${this.renderSummaryCards(stats)}
            ${this.renderTabs()}
            <div id="sc-tab-content" style="margin-bottom:20px;">
                ${this.renderTabContent()}
            </div>
            <div style="display:flex;gap:10px;margin-bottom:20px;">
                <button class="btn btn-primary" onclick="SchemaCompareUI.generateScript()" style="padding:8px 24px;font-size:13px;font-weight:600;">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px;">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>
                    </svg>
                    Generar Script de Migracion
                </button>
                <button class="btn" onclick="SchemaCompareUI.exportResults()" style="padding:8px 24px;font-size:13px;font-weight:600;border:1px solid var(--border);">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px;">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                    Exportar CSV
                </button>
            </div>
        `;
    },

    /**
     * Normalize backend diff format into a flat array per type with status field.
     * Backend: { tables: { onlyInSource:[], onlyInTarget:[], common:[] }, views: { onlyInSource, onlyInTarget, different, identical }, ... }
     * Frontend: { tables: [{name, status, details}], views: [...], ... }
     */
    normalizeDiff(raw) {
        const result = { tables: [], views: [], procedures: [], functions: [] };

        // Tables
        if (raw.tables) {
            (raw.tables.onlyInSource || []).forEach(t => {
                const name = typeof t === 'string' ? t : t.name || t;
                result.tables.push({ name, status: 'only_a', details: 'Solo en A' });
            });
            (raw.tables.onlyInTarget || []).forEach(t => {
                const name = typeof t === 'string' ? t : t.name || t;
                result.tables.push({ name, status: 'only_b', details: 'Solo en B' });
            });
            (raw.tables.common || []).forEach(t => {
                const name = t.name || t.table || '';
                if (t.identical) {
                    result.tables.push({ name, status: 'identical', details: 'Identico' });
                } else {
                    const cols = t.columns || {};
                    const diffs = [];
                    (cols.onlyInSource || []).forEach(c => diffs.push(`+${typeof c === 'string' ? c : c.name}`));
                    (cols.onlyInTarget || []).forEach(c => diffs.push(`-${typeof c === 'string' ? c : c.name}`));
                    (cols.different || []).forEach(c => diffs.push(`~${c.name || c.column || ''}`));
                    result.tables.push({ name, status: 'different', details: diffs.join(', ') || 'Diferencias en columnas', columnDiff: cols });
                }
            });
        }

        // Views, Procedures, Functions
        ['views', 'procedures', 'functions'].forEach(type => {
            if (!raw[type]) return;
            (raw[type].onlyInSource || []).forEach(n => {
                result[type].push({ name: typeof n === 'string' ? n : n.name || n, status: 'only_a', details: 'Solo en A' });
            });
            (raw[type].onlyInTarget || []).forEach(n => {
                result[type].push({ name: typeof n === 'string' ? n : n.name || n, status: 'only_b', details: 'Solo en B' });
            });
            (raw[type].different || []).forEach(item => {
                const name = typeof item === 'string' ? item : item.name || item;
                const srcDef = item.source_definition || '';
                const tgtDef = item.target_definition || '';
                result[type].push({ name, status: 'different', details: 'Definicion diferente', sourceDef: srcDef, targetDef: tgtDef });
            });
            (raw[type].identical || []).forEach(n => {
                result[type].push({ name: typeof n === 'string' ? n : n.name || n, status: 'identical', details: 'Identico' });
            });
        });

        return result;
    },

    computeStats(diff) {
        const count = (arr, type) => {
            if (!arr) return { identical: 0, different: 0, onlyA: 0, onlyB: 0, total: 0 };
            let identical = 0, different = 0, onlyA = 0, onlyB = 0;
            arr.forEach(item => {
                switch (item.status) {
                    case 'identical': identical++; break;
                    case 'different': different++; break;
                    case 'only_a': onlyA++; break;
                    case 'only_b': onlyB++; break;
                }
            });
            return { identical, different, onlyA, onlyB, total: arr.length };
        };
        return {
            tables: count(diff.tables),
            views: count(diff.views),
            procedures: count(diff.procedures),
            functions: count(diff.functions)
        };
    },

    renderSummaryCards(stats) {
        const card = (label, s, icon) => `
            <div class="card" style="padding:14px 18px;flex:1;min-width:160px;">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                    ${icon}
                    <span style="font-weight:700;font-size:13px;">${label}</span>
                    <span style="margin-left:auto;font-size:18px;font-weight:800;color:var(--text);">${s.total}</span>
                </div>
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <span class="badge" style="background:#05966915;color:#059669;font-size:11px;padding:2px 8px;border-radius:4px;">${s.identical} identicos</span>
                    <span class="badge" style="background:#d9770615;color:#d97706;font-size:11px;padding:2px 8px;border-radius:4px;">${s.different} diferentes</span>
                    <span class="badge" style="background:#dc262615;color:#dc2626;font-size:11px;padding:2px 8px;border-radius:4px;">${s.onlyA + s.onlyB} ausentes</span>
                </div>
            </div>`;

        const tIcon = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#3b82f6" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="9" x2="9" y2="21"/></svg>';
        const vIcon = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#8b5cf6" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
        const pIcon = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#059669" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>';
        const fIcon = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#d97706" stroke-width="2"><path d="M18 3a3 3 0 0 0-3 3v12a3 3 0 0 0 3 3 3 3 0 0 0 3-3 3 3 0 0 0-3-3H6a3 3 0 0 0-3 3 3 3 0 0 0 3 3 3 3 0 0 0 3-3V6a3 3 0 0 0-3-3 3 3 0 0 0-3 3 3 3 0 0 0 3 3h12"/></svg>';

        return `
        <div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
            ${card('Tablas', stats.tables, tIcon)}
            ${card('Vistas', stats.views, vIcon)}
            ${card('Procedimientos', stats.procedures, pIcon)}
            ${card('Funciones', stats.functions, fIcon)}
        </div>`;
    },

    // ═══════════════════════════════════════════════════════════════════
    //  TABS
    // ═══════════════════════════════════════════════════════════════════

    renderTabs() {
        const tabs = [
            { key: 'tables', label: 'Tablas' },
            { key: 'views', label: 'Vistas' },
            { key: 'procedures', label: 'Procedimientos' },
            { key: 'functions', label: 'Funciones' }
        ];
        return `
        <div style="display:flex;gap:0;margin-bottom:0;border:1px solid var(--border);border-radius:8px 8px 0 0;overflow:hidden;width:fit-content;">
            ${tabs.map(t => `
                <button onclick="SchemaCompareUI.switchTab('${t.key}')"
                    style="border:none;padding:10px 24px;font-size:13px;font-weight:600;cursor:pointer;
                           ${this.activeTab === t.key ? 'background:var(--primary);color:#fff;' : 'background:var(--bg-card);color:var(--text);'}">
                    ${t.label}
                </button>
            `).join('')}
        </div>`;
    },

    switchTab(tab) {
        this.activeTab = tab;
        // Update tab button styles
        const tabs = ['tables', 'views', 'procedures', 'functions'];
        tabs.forEach(t => {
            const btns = document.querySelectorAll('#sc-results button');
            // Re-render just the tab content to avoid full re-render
        });
        // Re-render results section
        this.renderResults();
    },

    statusFilter: 'all', // 'all' | 'different' | 'only_a' | 'only_b' | 'identical'

    setStatusFilter(filter) {
        this.statusFilter = filter;
        const el = document.getElementById('sc-tab-content');
        if (el) el.innerHTML = this.renderTabContent();
    },

    renderTabContent() {
        if (!this._normalizedDiff) return '';
        const allItems = this._normalizedDiff[this.activeTab] || [];
        if (allItems.length === 0) {
            return `<div class="card" style="padding:30px;text-align:center;color:var(--text-light);font-size:14px;border-radius:0 8px 8px 8px;">
                No se encontraron objetos de este tipo en ninguna de las bases de datos.
            </div>`;
        }

        // Count per status for filter badges
        const counts = { all: allItems.length, identical: 0, different: 0, only_a: 0, only_b: 0 };
        allItems.forEach(i => { if (counts[i.status] !== undefined) counts[i.status]++; });

        const items = this.statusFilter === 'all' ? allItems : allItems.filter(i => i.status === this.statusFilter);
        const isTable = this.activeTab === 'tables';

        let rows = '';
        items.forEach((item, idx) => {
            const badge = this.statusBadge(item.status);
            const details = this.statusDetails(item);
            const expandableTable = isTable && item.status === 'different' && item.columnDiff;
            const expandableDef = !isTable && item.status === 'different' && (item.sourceDef || item.targetDef);
            const expandable = expandableTable || expandableDef;

            rows += `
                <tr style="border-top:1px solid var(--border);${expandable ? 'cursor:pointer;' : ''}"
                    ${expandable ? `onclick="SchemaCompareUI.toggleDetailRow(${idx})"` : ''}>
                    <td style="padding:10px 14px;font-weight:600;font-size:13px;">
                        ${expandable ? `<svg id="sc-chevron-${idx}" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:6px;transition:transform 0.2s;"><polyline points="9 18 15 12 9 6"/></svg>` : ''}
                        ${this.esc(item.name)}
                    </td>
                    <td style="padding:10px 14px;">${badge}</td>
                    <td style="padding:10px 14px;font-size:12px;color:var(--text-light);">${details}</td>
                </tr>`;

            if (expandableTable) {
                rows += `<tr id="sc-detail-${idx}" style="display:none;"><td colspan="3" style="padding:0;">${this.renderColumnDiff(item.columnDiff)}</td></tr>`;
            } else if (expandableDef) {
                rows += `<tr id="sc-detail-${idx}" style="display:none;"><td colspan="3" style="padding:0;">${this.renderDefinitionDiff(item)}</td></tr>`;
            }
        });

        // Filter bar
        const fb = (key, label, color) => {
            const active = this.statusFilter === key;
            return `<button onclick="SchemaCompareUI.setStatusFilter('${key}')" style="padding:5px 12px;font-size:11px;font-weight:600;border:1px solid ${active ? color : 'var(--border)'};border-radius:6px;cursor:pointer;background:${active ? color + '15' : 'var(--bg-card)'};color:${active ? color : 'var(--text-light)'};">${label} <span style="font-weight:400;">(${counts[key]})</span></button>`;
        };

        return `
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px;">
            ${fb('all', 'Todos', 'var(--primary)')}
            ${fb('different', 'Diferentes', '#d97706')}
            ${fb('only_a', 'Solo en A', '#dc2626')}
            ${fb('only_b', 'Solo en B', '#dc2626')}
            ${fb('identical', 'Identicos', '#059669')}
        </div>
        <div class="card" style="border-radius:8px;overflow:hidden;">
            <table class="data-table" style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr>
                        <th style="padding:10px 14px;text-align:left;font-size:12px;width:35%;">Objeto</th>
                        <th style="padding:10px 14px;text-align:left;font-size:12px;width:18%;">Estado</th>
                        <th style="padding:10px 14px;text-align:left;font-size:12px;width:47%;">Detalles</th>
                    </tr>
                </thead>
                <tbody>${rows || '<tr><td colspan="3" style="padding:20px;text-align:center;color:var(--text-light);">No hay objetos con este filtro.</td></tr>'}</tbody>
            </table>
        </div>`;
    },

    filterByDirection(items) {
        if (this.direction === 'bidirectional') return items;
        if (this.direction === 'a_to_b') {
            // Show everything except "only_b" items that would not affect B
            return items.filter(i => i.status !== 'only_b' || true);
        }
        // b_to_a - show all, but labeling changes
        return items;
    },

    statusBadge(status) {
        switch (status) {
            case 'identical':
                return '<span class="badge" style="background:#05966920;color:#059669;padding:4px 10px;border-radius:4px;font-size:11px;font-weight:700;">Identico</span>';
            case 'different':
                return '<span class="badge" style="background:#d9770620;color:#d97706;padding:4px 10px;border-radius:4px;font-size:11px;font-weight:700;">Diferente</span>';
            case 'only_a':
                return '<span class="badge" style="background:#dc262620;color:#dc2626;padding:4px 10px;border-radius:4px;font-size:11px;font-weight:700;">Solo en A</span>';
            case 'only_b':
                return '<span class="badge" style="background:#dc262620;color:#dc2626;padding:4px 10px;border-radius:4px;font-size:11px;font-weight:700;">Solo en B</span>';
            default:
                return '<span class="badge" style="background:var(--border);padding:4px 10px;border-radius:4px;font-size:11px;">Desconocido</span>';
        }
    },

    statusDetails(item) {
        switch (item.status) {
            case 'identical':
                return 'Estructura identica en ambas bases de datos.';
            case 'different':
                if (item.differences && item.differences.length > 0) {
                    return item.differences.map(d => this.esc(d)).join('; ');
                }
                return 'Las definiciones difieren entre A y B.';
            case 'only_a':
                if (this.direction === 'a_to_b') return 'Necesita ser creado en B.';
                if (this.direction === 'b_to_a') return 'Existe solo en A, podria eliminarse.';
                return 'Existe unicamente en la conexion A.';
            case 'only_b':
                if (this.direction === 'b_to_a') return 'Necesita ser creado en A.';
                if (this.direction === 'a_to_b') return 'Existe solo en B, podria eliminarse.';
                return 'Existe unicamente en la conexion B.';
            default:
                return '';
        }
    },

    // ═══════════════════════════════════════════════════════════════════
    //  COLUMN-LEVEL DIFF (for tables with status "different")
    // ═══════════════════════════════════════════════════════════════════

    toggleDetailRow(idx) {
        const row = document.getElementById(`sc-detail-${idx}`);
        const chevron = document.getElementById(`sc-chevron-${idx}`);
        if (row) {
            const show = row.style.display === 'none';
            row.style.display = show ? '' : 'none';
            if (chevron) chevron.style.transform = show ? 'rotate(90deg)' : '';
        }
    },

    renderDefinitionDiff(item) {
        const srcDef = item.sourceDef || '(sin definicion)';
        const tgtDef = item.targetDef || '(sin definicion)';
        return `
            <div style="padding:12px 16px;background:#f8fafc;border-top:1px solid var(--border);">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div>
                        <div style="font-size:11px;font-weight:700;color:var(--primary);margin-bottom:6px;">Definicion en A (Origen)</div>
                        <pre style="background:#1e293b;color:#e2e8f0;padding:10px 12px;border-radius:6px;font-size:11px;line-height:1.5;max-height:200px;overflow:auto;white-space:pre-wrap;word-break:break-word;margin:0;">${this.esc(srcDef)}</pre>
                    </div>
                    <div>
                        <div style="font-size:11px;font-weight:700;color:#8b5cf6;margin-bottom:6px;">Definicion en B (Destino)</div>
                        <pre style="background:#1e293b;color:#e2e8f0;padding:10px 12px;border-radius:6px;font-size:11px;line-height:1.5;max-height:200px;overflow:auto;white-space:pre-wrap;word-break:break-word;margin:0;">${this.esc(tgtDef)}</pre>
                    </div>
                </div>
            </div>`;
    },

    toggleColumnDiff(idx) {
        const row = document.getElementById(`sc-coldiff-${idx}`);
        const chevron = document.getElementById(`sc-chevron-${idx}`);
        if (!row) return;
        const open = row.style.display !== 'none';
        row.style.display = open ? 'none' : 'table-row';
        if (chevron) {
            chevron.style.transform = open ? '' : 'rotate(90deg)';
        }
    },

    renderColumnDiff(colDiff) {
        if (!colDiff) return '<div style="padding:12px 30px;color:var(--text-light);font-size:12px;">Sin detalles de columnas disponibles.</div>';

        // Normalize: colDiff can be { onlyInSource:[], onlyInTarget:[], different:[], identical:[] }
        // or already an array of {name, status, type_a, type_b}
        let columns = [];
        if (Array.isArray(colDiff)) {
            columns = colDiff;
        } else {
            (colDiff.onlyInSource || []).forEach(c => {
                const name = typeof c === 'string' ? c : c.name || c.column || '';
                const type = typeof c === 'string' ? '' : c.type || c.full_type || '';
                columns.push({ name, status: 'only_a', type_a: type, type_b: '-' });
            });
            (colDiff.onlyInTarget || []).forEach(c => {
                const name = typeof c === 'string' ? c : c.name || c.column || '';
                const type = typeof c === 'string' ? '' : c.type || c.full_type || '';
                columns.push({ name, status: 'only_b', type_a: '-', type_b: type });
            });
            (colDiff.different || []).forEach(c => {
                const srcType = c.source?.type || c.source?.full_type || c.source_type || c.type_a || '';
                const tgtType = c.target?.type || c.target?.full_type || c.target_type || c.type_b || '';
                const diffs = c.diffs || [];
                columns.push({
                    name: c.name || c.column || '',
                    status: 'different',
                    type_a: srcType + (diffs.length ? ' (' + diffs.join(', ') + ')' : ''),
                    type_b: tgtType
                });
            });
            (colDiff.identical || []).forEach(c => {
                const name = typeof c === 'string' ? c : c.name || c.column || '';
                const type = typeof c === 'string' ? '' : c.type || c.full_type || '';
                columns.push({ name, status: 'identical', type_a: type, type_b: type });
            });
        }

        if (columns.length === 0) return '<div style="padding:12px 30px;color:var(--text-light);font-size:12px;">Sin diferencias de columnas.</div>';

        let rows = '';
        columns.forEach(col => {
            const colStatus = this.columnStatusBadge(col.status);
            rows += `
                <tr style="border-top:1px solid var(--border);">
                    <td style="padding:6px 14px;font-size:12px;font-weight:600;">${this.esc(col.name)}</td>
                    <td style="padding:6px 14px;font-size:12px;font-family:'Fira Code','Consolas',monospace;">${this.esc(col.type_a || '-')}</td>
                    <td style="padding:6px 14px;font-size:12px;font-family:'Fira Code','Consolas',monospace;">${this.esc(col.type_b || '-')}</td>
                    <td style="padding:6px 14px;">${colStatus}</td>
                </tr>`;
        });

        return `
        <div style="margin:0 20px 12px 30px;border:1px solid var(--border);border-radius:6px;overflow:hidden;background:var(--bg);">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="background:var(--bg-card);">
                        <th style="padding:6px 14px;text-align:left;font-size:11px;font-weight:700;color:var(--text-light);">Columna</th>
                        <th style="padding:6px 14px;text-align:left;font-size:11px;font-weight:700;color:#3b82f6;">Tipo A</th>
                        <th style="padding:6px 14px;text-align:left;font-size:11px;font-weight:700;color:#8b5cf6;">Tipo B</th>
                        <th style="padding:6px 14px;text-align:left;font-size:11px;font-weight:700;color:var(--text-light);">Estado</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        </div>`;
    },

    columnStatusBadge(status) {
        switch (status) {
            case 'identical':
                return '<span style="color:#059669;font-size:11px;font-weight:600;">Identico</span>';
            case 'different':
                return '<span style="color:#d97706;font-size:11px;font-weight:600;">Diferente</span>';
            case 'only_a':
                return '<span style="color:#dc2626;font-size:11px;font-weight:600;">Solo en A</span>';
            case 'only_b':
                return '<span style="color:#dc2626;font-size:11px;font-weight:600;">Solo en B</span>';
            default:
                return '<span style="font-size:11px;">-</span>';
        }
    },

    // ═══════════════════════════════════════════════════════════════════
    //  SCRIPT GENERATION
    // ═══════════════════════════════════════════════════════════════════

    async generateScript() {
        if (!this.lastDiff) return;

        const connA = document.getElementById('sc-conn-a')?.value;
        const dbA = document.getElementById('sc-db-a')?.value;
        const connB = document.getElementById('sc-conn-b')?.value;
        const dbB = document.getElementById('sc-db-b')?.value;
        const section = document.getElementById('sc-script-section');
        if (!section) return;

        section.innerHTML = `
        <div class="card" style="padding:20px;margin-bottom:20px;">
            <div style="color:var(--text-light);font-size:13px;">
                <span style="display:inline-block;width:14px;height:14px;border:2px solid #0001;border-top-color:var(--primary);border-radius:50%;animation:spin 0.8s linear infinite;vertical-align:middle;margin-right:8px;"></span>
                Generando script de migracion...
            </div>
        </div>`;

        try {
            const dirMap = { 'a_to_b': 'AtoB', 'b_to_a': 'BtoA', 'bidirectional': 'AtoB' };
            const resp = await API.request('POST', '/schema-compare/generate-script', {
                connA: parseInt(this._lastConnA || connA),
                dbA: this._lastDbA || dbA,
                connB: parseInt(this._lastConnB || connB),
                dbB: this._lastDbB || dbB,
                direction: dirMap[this.direction] || 'AtoB'
            });
            this.lastScript = resp.data?.script || '';
            this.renderScriptSection();
        } catch (e) {
            section.innerHTML = `<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:14px 18px;margin-bottom:16px;color:#991b1b;font-size:14px;">
                <strong>Error:</strong> ${this.esc(e.message || 'Error al generar script.')}</div>`;
        }
    },

    renderScriptSection() {
        const section = document.getElementById('sc-script-section');
        if (!section || this.lastScript === null) return;

        const dirLabel = this.direction === 'a_to_b' ? 'A \u2192 B'
            : this.direction === 'b_to_a' ? 'B \u2192 A'
            : 'Bidireccional';

        const connOptions = this.connections.map(c =>
            `<option value="${c.id}">${this.esc(c.name)} (${c.driver})</option>`
        ).join('');

        section.innerHTML = `
        <div class="card" style="margin-bottom:20px;">
            <div style="padding:14px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;">
                <h3 style="margin:0;font-size:15px;font-weight:700;">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:6px;">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>
                    </svg>
                    Script de Migracion
                    <span style="font-weight:400;font-size:12px;color:var(--text-light);margin-left:8px;">(${this.esc(dirLabel)})</span>
                </h3>
                <button class="btn" onclick="SchemaCompareUI.copyScript()" style="font-size:11px;padding:4px 12px;border:1px solid var(--border);">
                    <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px;">
                        <rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                    </svg>
                    Copiar
                </button>
            </div>
            <div style="padding:0;">
                <pre id="sc-script-content" style="background:#1e293b;color:#e2e8f0;padding:20px;margin:0;font-family:'Fira Code','Cascadia Code','Consolas',monospace;font-size:12px;line-height:1.6;overflow-x:auto;white-space:pre-wrap;max-height:500px;overflow-y:auto;border-radius:0;">${this.highlightSQL(this.lastScript)}</pre>
            </div>
            <div style="padding:16px 20px;border-top:1px solid var(--border);display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <label style="font-size:12px;font-weight:600;color:var(--text-light);">Ejecutar en:</label>
                <select class="form-control" id="sc-exec-conn" style="font-size:12px;width:220px;">
                    <option value="">-- Seleccionar conexion --</option>
                    ${connOptions}
                </select>
                <select class="form-control" id="sc-exec-db" style="font-size:12px;width:180px;" disabled>
                    <option value="">-- BD --</option>
                </select>
                <button class="btn" onclick="SchemaCompareUI.onExecConnChange()" style="font-size:11px;padding:5px 10px;border:1px solid var(--border);">Cargar BDs</button>
                <button class="btn btn-danger" onclick="SchemaCompareUI.executeScript()" style="padding:8px 20px;font-size:13px;font-weight:600;margin-left:auto;">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px;">
                        <polygon points="5 3 19 12 5 21 5 3"/>
                    </svg>
                    Ejecutar Script
                </button>
            </div>
            <div id="sc-exec-results"></div>
        </div>`;
    },

    highlightSQL(sql) {
        if (!sql) return '<span style="color:#64748b;font-style:italic;">-- Sin cambios detectados --</span>';
        // Basic syntax highlighting for SQL in the dark-themed pre block
        let escaped = this.esc(sql);
        // Keywords
        const keywords = ['CREATE', 'ALTER', 'DROP', 'TABLE', 'VIEW', 'PROCEDURE', 'FUNCTION',
            'ADD', 'MODIFY', 'COLUMN', 'INDEX', 'PRIMARY', 'KEY', 'FOREIGN', 'REFERENCES',
            'NOT', 'NULL', 'DEFAULT', 'AUTO_INCREMENT', 'ENGINE', 'CHARSET', 'COLLATE',
            'IF', 'EXISTS', 'BEGIN', 'END', 'RETURNS', 'RETURN', 'DECLARE', 'SET',
            'INSERT', 'UPDATE', 'DELETE', 'SELECT', 'FROM', 'WHERE', 'INTO', 'VALUES',
            'DELIMITER', 'AS', 'OR', 'REPLACE', 'DEFINER', 'TRIGGER', 'AFTER', 'BEFORE',
            'EACH', 'ROW', 'FOR', 'IN', 'OUT', 'INOUT', 'CONSTRAINT', 'UNIQUE', 'CHECK',
            'VARCHAR', 'INT', 'INTEGER', 'BIGINT', 'SMALLINT', 'TINYINT', 'TEXT', 'BLOB',
            'DATETIME', 'TIMESTAMP', 'DATE', 'TIME', 'DECIMAL', 'FLOAT', 'DOUBLE', 'BOOLEAN',
            'ENUM', 'ON', 'CASCADE', 'RESTRICT', 'NO', 'ACTION'];
        // Highlight keywords (word boundary)
        escaped = escaped.replace(
            new RegExp('\\b(' + keywords.join('|') + ')\\b', 'gi'),
            '<span style="color:#7dd3fc;">$1</span>'
        );
        // Highlight comments (single-line)
        escaped = escaped.replace(/(--[^\n]*)/g, '<span style="color:#64748b;font-style:italic;">$1</span>');
        // Highlight strings
        escaped = escaped.replace(/(&apos;[^&]*?&apos;|&#39;[^&]*?&#39;)/g, '<span style="color:#fbbf24;">$1</span>');
        return escaped;
    },

    async copyScript() {
        if (!this.lastScript) return;
        try {
            await navigator.clipboard.writeText(this.lastScript);
            if (typeof Swal !== 'undefined') {
                Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Script copiado al portapapeles', showConfirmButton: false, timer: 1500 });
            }
        } catch (e) {
            // Fallback
            const ta = document.createElement('textarea');
            ta.value = this.lastScript;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
        }
    },

    async onExecConnChange() {
        const connId = document.getElementById('sc-exec-conn')?.value;
        const dbSelect = document.getElementById('sc-exec-db');
        if (!dbSelect) return;
        dbSelect.innerHTML = '<option value="">-- BD --</option>';
        dbSelect.disabled = true;
        if (!connId) return;
        try {
            const resp = await API.getDatabases(connId);
            (resp.data || []).forEach(db => {
                const o = document.createElement('option');
                o.value = db;
                o.textContent = db;
                dbSelect.appendChild(o);
            });
            dbSelect.disabled = false;
        } catch (e) {
            dbSelect.disabled = false;
        }
    },

    // ═══════════════════════════════════════════════════════════════════
    //  SCRIPT EXECUTION
    // ═══════════════════════════════════════════════════════════════════

    async executeScript() {
        if (!this.lastScript) return;
        const connId = document.getElementById('sc-exec-conn')?.value;
        const db = document.getElementById('sc-exec-db')?.value;

        if (!connId || !db) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'warning', title: 'Seleccion requerida', text: 'Seleccione una conexion y base de datos de destino.', confirmButtonText: 'Entendido' });
            }
            return;
        }

        // Confirmation with SweetAlert2
        if (typeof Swal !== 'undefined') {
            const result = await Swal.fire({
                icon: 'warning',
                title: 'Ejecutar script de migracion',
                html: `<p style="font-size:14px;">Esta a punto de ejecutar el script de migracion en la base de datos seleccionada.</p>
                       <p style="font-size:13px;color:#991b1b;font-weight:600;">Esta accion puede modificar la estructura de la base de datos y no se puede deshacer facilmente.</p>`,
                showCancelButton: true,
                confirmButtonText: 'Ejecutar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#dc2626',
                reverseButtons: true
            });
            if (!result.isConfirmed) return;
        }

        const resultsDiv = document.getElementById('sc-exec-results');
        if (!resultsDiv) return;

        resultsDiv.innerHTML = `
        <div style="padding:16px 20px;border-top:1px solid var(--border);">
            <div style="color:var(--text-light);font-size:13px;">
                <span style="display:inline-block;width:14px;height:14px;border:2px solid #0001;border-top-color:var(--primary);border-radius:50%;animation:spin 0.8s linear infinite;vertical-align:middle;margin-right:8px;"></span>
                Ejecutando script...
            </div>
        </div>`;

        try {
            const resp = await API.request('POST', '/schema-compare/execute-script', {
                connection_id: connId,
                database: db,
                script: this.lastScript
            });

            const statements = resp.data?.results || [];
            this.renderExecutionResults(statements);
        } catch (e) {
            resultsDiv.innerHTML = `
            <div style="padding:16px 20px;border-top:1px solid var(--border);">
                <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:12px 16px;color:#991b1b;font-size:13px;">
                    <strong>Error:</strong> ${this.esc(e.message || 'Error al ejecutar el script.')}
                </div>
            </div>`;
        }
    },

    renderExecutionResults(statements) {
        const resultsDiv = document.getElementById('sc-exec-results');
        if (!resultsDiv) return;

        if (statements.length === 0) {
            resultsDiv.innerHTML = `
            <div style="padding:16px 20px;border-top:1px solid var(--border);">
                <div style="color:var(--text-light);font-size:13px;">No se ejecutaron sentencias.</div>
            </div>`;
            return;
        }

        const successCount = statements.filter(s => s.success).length;
        const errorCount = statements.filter(s => !s.success).length;

        let rows = '';
        statements.forEach((stmt, idx) => {
            const icon = stmt.success
                ? '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#059669" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>'
                : '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#dc2626" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
            const bgColor = stmt.success ? '' : 'background:#fef2f2;';
            const sqlPreview = (stmt.sql || '').substring(0, 120) + ((stmt.sql || '').length > 120 ? '...' : '');

            rows += `
                <tr style="border-top:1px solid var(--border);${bgColor}">
                    <td style="padding:8px 14px;text-align:center;width:40px;">${icon}</td>
                    <td style="padding:8px 14px;font-size:11px;font-family:'Fira Code','Consolas',monospace;color:var(--text-light);">${this.esc(sqlPreview)}</td>
                    <td style="padding:8px 14px;font-size:12px;">
                        ${stmt.success
                            ? '<span style="color:#059669;font-weight:600;">OK</span>' + (stmt.affected_rows !== undefined ? ` <span style="color:var(--text-light);font-size:11px;">(${stmt.affected_rows} filas)</span>` : '')
                            : `<span style="color:#dc2626;font-weight:600;">Error:</span> <span style="color:#991b1b;font-size:11px;">${this.esc(stmt.error || 'Error desconocido')}</span>`
                        }
                    </td>
                </tr>`;
        });

        resultsDiv.innerHTML = `
        <div style="padding:16px 20px;border-top:1px solid var(--border);">
            <div style="display:flex;gap:12px;margin-bottom:12px;align-items:center;">
                <span style="font-weight:700;font-size:14px;">Resultados de Ejecucion</span>
                <span class="badge" style="background:#05966920;color:#059669;padding:3px 10px;border-radius:4px;font-size:11px;font-weight:700;">${successCount} exitosas</span>
                ${errorCount > 0 ? `<span class="badge" style="background:#dc262620;color:#dc2626;padding:3px 10px;border-radius:4px;font-size:11px;font-weight:700;">${errorCount} errores</span>` : ''}
            </div>
            <div style="border:1px solid var(--border);border-radius:8px;overflow:hidden;">
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="background:var(--bg-card);">
                            <th style="padding:8px 14px;font-size:11px;font-weight:700;color:var(--text-light);width:40px;"></th>
                            <th style="padding:8px 14px;text-align:left;font-size:11px;font-weight:700;color:var(--text-light);">Sentencia</th>
                            <th style="padding:8px 14px;text-align:left;font-size:11px;font-weight:700;color:var(--text-light);width:30%;">Resultado</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>
        </div>`;
    },

    // ═══════════════════════════════════════════════════════════════════
    //  EXPORT
    // ═══════════════════════════════════════════════════════════════════

    exportResults() {
        if (!this._normalizedDiff) return;
        const columns = ['Tipo', 'Nombre', 'Estado', 'Detalles'];
        const rows = [];
        const types = { tables: 'Tabla', views: 'Vista', procedures: 'Procedimiento', functions: 'Funcion' };

        Object.keys(types).forEach(key => {
            const items = this._normalizedDiff[key] || [];
            items.forEach(item => {
                const status = { identical: 'Identico', different: 'Diferente', only_a: 'Solo en A', only_b: 'Solo en B' }[item.status] || item.status;
                const details = item.differences ? item.differences.join('; ') : this.statusDetails(item);
                rows.push([types[key], item.name, status, details]);
            });
        });

        if (typeof ClientExport !== 'undefined') {
            ClientExport.export(columns, rows, 'csv', 'schema_compare');
        }
    },

    // ═══════════════════════════════════════════════════════════════════
    //  UTILITY
    // ═══════════════════════════════════════════════════════════════════

    esc(str) {
        const d = document.createElement('div');
        d.textContent = str || '';
        return d.innerHTML;
    }
};
