/**
 * Backups UI - Genera backups .sql de las bases de datos seleccionadas.
 */
const BackupsUI = {
    connections: [],
    _generating: false,

    async load() {
        try {
            const resp = await API.getConnections();
            this.connections = resp.data || [];
        } catch (e) {
            this.connections = [];
        }
        this.render();
    },

    render() {
        const panel = document.getElementById('panel-backups');
        if (!panel) return;

        panel.innerHTML = `
            <div style="margin-bottom:20px;">
                <p style="color:var(--secondary);">Genera un respaldo <strong>.sql</strong> de una o varias bases de datos. El archivo se descarga a tu equipo.</p>
            </div>

            <div class="card" style="max-width:720px;">
                <div class="card-header"><h3>Nuevo backup</h3></div>
                <div style="padding:16px;">
                    <div class="form-group">
                        <label class="form-label">Conexión</label>
                        <select class="form-control" id="backup-conn-select" onchange="BackupsUI.onConnChange(this.value)">
                            <option value="">-- Selecciona una conexión --</option>
                            ${this.connections.map(c => `<option value="${c.id}">${this.esc(c.name)} (${this.esc(c.driver)})</option>`).join('')}
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Bases de datos <span style="color:var(--text-light);font-weight:normal;">(Ctrl/⌘ para varias)</span></label>
                        <select class="form-control" id="backup-db-select" multiple size="8" style="min-height:160px;" disabled>
                            <option value="">Selecciona una conexión primero</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Contenido</label>
                        <div style="display:flex;flex-direction:column;gap:8px;">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                                <input type="checkbox" id="backup-structure" checked> Estructura (tablas, vistas, rutinas)
                            </label>
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                                <input type="checkbox" id="backup-data" checked> Datos (INSERT de las filas)
                            </label>
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                                <input type="checkbox" id="backup-drop"> Incluir DROP antes de cada objeto
                            </label>
                        </div>
                    </div>

                    <div style="margin-top:16px;display:flex;gap:10px;align-items:center;">
                        <button class="btn btn-success" id="btn-backup-generate" onclick="BackupsUI.generate()">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px;">
                                <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
                            </svg>
                            Generar y descargar
                        </button>
                        <span id="backup-status" style="font-size:13px;color:var(--text-light);"></span>
                    </div>
                </div>
            </div>
        `;
    },

    async onConnChange(connId) {
        const dbSelect = document.getElementById('backup-db-select');
        if (!connId) {
            dbSelect.innerHTML = '<option value="">Selecciona una conexión primero</option>';
            dbSelect.disabled = true;
            return;
        }
        dbSelect.disabled = true;
        dbSelect.innerHTML = '<option value="">Cargando...</option>';
        try {
            const resp = await API.getDatabases(connId);
            const dbs = resp.data || [];
            if (dbs.length === 0) {
                dbSelect.innerHTML = '<option value="">Sin bases de datos</option>';
                return;
            }
            dbSelect.innerHTML = dbs.map(d => `<option value="${this.esc(d)}">${this.esc(d)}</option>`).join('');
            dbSelect.disabled = false;
        } catch (e) {
            dbSelect.innerHTML = '<option value="">Error al cargar</option>';
            Toast.error('No se pudieron cargar las bases de datos: ' + e.message);
        }
    },

    async generate() {
        if (this._generating) return;

        const connId = document.getElementById('backup-conn-select').value;
        if (!connId) { Toast.warning('Selecciona una conexión'); return; }

        const dbSelect = document.getElementById('backup-db-select');
        const databases = Array.from(dbSelect.selectedOptions).map(o => o.value).filter(v => v);
        if (databases.length === 0) { Toast.warning('Selecciona al menos una base de datos'); return; }

        const structure = document.getElementById('backup-structure').checked;
        const data = document.getElementById('backup-data').checked;
        const drop = document.getElementById('backup-drop').checked;
        if (!structure && !data) { Toast.warning('Selecciona estructura, datos, o ambos'); return; }

        const btn = document.getElementById('btn-backup-generate');
        const status = document.getElementById('backup-status');
        this._generating = true;
        btn.disabled = true;
        status.textContent = 'Generando backup...';

        try {
            const resp = await API.generateBackup({
                connection_id: parseInt(connId),
                databases,
                structure,
                data,
                drop
            });
            status.textContent = '';
            Toast.success(resp.message || 'Backup descargado');
        } catch (e) {
            status.textContent = '';
            Toast.error('Error al generar el backup: ' + e.message);
        } finally {
            this._generating = false;
            btn.disabled = false;
        }
    },

    esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }
};
