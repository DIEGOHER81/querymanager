/**
 * Connections UI - CRUD for database connections
 */
const ConnectionsUI = {
    connections: [],

    async load() {
        try {
            const resp = await API.getConnections();
            this.connections = resp.data || [];
            this.render();
        } catch (e) {
            Toast.error('Error al cargar conexiones: ' + e.message);
        }
    },

    render() {
        const container = document.getElementById('connections-list');

        if (this.connections.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/>
                    </svg>
                    <h3>No hay conexiones configuradas</h3>
                    <p>Crea tu primera conexión para comenzar a trabajar.</p>
                    <button class="btn btn-primary" onclick="ConnectionsUI.openForm()" style="margin-top:16px">
                        + Nueva Conexión
                    </button>
                </div>`;
            return;
        }

        container.innerHTML = `
            <div class="conn-grid">
                ${this.connections.map(c => this.renderCard(c)).join('')}
            </div>`;
    },

    renderCard(conn) {
        return `
            <div class="conn-card" id="conn-${conn.id}">
                <div class="conn-card-header">
                    <h4>${this.escHtml(conn.name)}</h4>
                    <span class="driver-badge ${conn.driver}">${conn.driver === 'mysql' ? 'MySQL' : 'SQL Server'}</span>
                </div>
                <div class="conn-detail"><strong>Host:</strong> ${this.escHtml(conn.host)}:${conn.port || ''}</div>
                <div class="conn-detail"><strong>Base de datos:</strong> ${this.escHtml(conn.database_name || 'N/A')}</div>
                <div class="conn-detail"><strong>Usuario:</strong> ${this.escHtml(conn.username)}</div>
                ${conn.sp_name ? `<div class="conn-detail"><strong>SP JSON:</strong> ${this.escHtml(conn.sp_name)}</div>` : ''}
                <div class="conn-actions">
                    <button class="btn btn-sm btn-success" onclick="ConnectionsUI.test(${conn.id})">Probar</button>
                    <button class="btn btn-sm btn-primary" onclick="ConnectionsUI.use(${conn.id})">Usar</button>
                    <button class="btn btn-sm btn-outline" onclick="ConnectionsUI.edit(${conn.id})">Editar</button>
                    <button class="btn btn-sm btn-danger" onclick="ConnectionsUI.confirmDelete(${conn.id})">Eliminar</button>
                </div>
            </div>`;
    },

    openForm(conn = null) {
        const isEdit = conn !== null;
        const form = document.getElementById('conn-form');
        form.querySelector('.modal-header h3').textContent = isEdit ? 'Editar Conexión' : 'Nueva Conexión';

        document.getElementById('conn-id').value = isEdit ? conn.id : '';
        document.getElementById('conn-name').value = isEdit ? conn.name : '';
        document.getElementById('conn-driver').value = isEdit ? conn.driver : 'mysql';
        document.getElementById('conn-host').value = isEdit ? conn.host : 'localhost';
        document.getElementById('conn-port').value = isEdit ? conn.port : '3306';
        document.getElementById('conn-database').value = isEdit ? (conn.database_name || '') : '';
        document.getElementById('conn-username').value = isEdit ? conn.username : '';
        document.getElementById('conn-password').value = '';
        document.getElementById('conn-charset').value = isEdit ? (conn.charset || 'utf8mb4') : 'utf8mb4';
        document.getElementById('conn-sp-name').value = isEdit ? (conn.sp_name || '') : '';

        // Enable/disable drivers based on server availability
        const driverSelect = document.getElementById('conn-driver');
        const sqlsrvOption = driverSelect.querySelector('option[value="sqlsrv"]');
        const hasSqlsrv = API.hasDriver('sqlsrv');

        if (sqlsrvOption) {
            sqlsrvOption.disabled = !hasSqlsrv;
            sqlsrvOption.textContent = hasSqlsrv ? 'SQL Server' : 'SQL Server (no disponible en este servidor)';
        }

        // Show/hide driver warning
        let warning = document.getElementById('conn-driver-warning');
        if (!warning) {
            warning = document.createElement('div');
            warning.id = 'conn-driver-warning';
            warning.style.cssText = 'display:none;padding:8px 12px;background:#fef3c7;border:1px solid #fde68a;border-radius:6px;color:#92400e;font-size:12px;margin-top:8px;line-height:1.6;';
            driverSelect.parentNode.appendChild(warning);
        }

        if (!hasSqlsrv) {
            warning.innerHTML = '<strong>SQL Server no disponible:</strong> Este servidor no tiene el Microsoft ODBC Driver instalado. Solo se pueden crear conexiones MySQL/MariaDB. Para SQL Server se requiere un VPS o servidor dedicado.';
            warning.style.display = '';
        } else {
            warning.style.display = 'none';
        }

        this.onDriverChange();
        Modal.open('conn-form');
    },

    onDriverChange() {
        const driverSelect = document.getElementById('conn-driver');
        const driver = driverSelect.value;
        const portField = document.getElementById('conn-port');

        // Prevent selecting unavailable driver
        if (driver === 'sqlsrv' && !API.hasDriver('sqlsrv')) {
            driverSelect.value = 'mysql';
            if (typeof Toast !== 'undefined') {
                Toast.error('SQL Server no esta disponible en este servidor. Se requiere el Microsoft ODBC Driver instalado en un VPS o servidor dedicado.');
            }
            this.onDriverChange();
            return;
        }

        if (driver === 'mysql' && (!portField.value || portField.value === '1433')) {
            portField.value = '3306';
        } else if (driver === 'sqlsrv' && (!portField.value || portField.value === '3306')) {
            portField.value = '1433';
        }

        // Update warning visibility
        const warning = document.getElementById('conn-driver-warning');
        if (warning) {
            warning.style.display = (driver === 'sqlsrv' || API.hasDriver('sqlsrv')) ? 'none' : '';
        }
    },

    async save() {
        const id = document.getElementById('conn-id').value;
        const data = {
            name: document.getElementById('conn-name').value,
            driver: document.getElementById('conn-driver').value,
            host: document.getElementById('conn-host').value,
            port: parseInt(document.getElementById('conn-port').value) || null,
            database_name: document.getElementById('conn-database').value || null,
            username: document.getElementById('conn-username').value,
            password: document.getElementById('conn-password').value || undefined,
            charset: document.getElementById('conn-charset').value,
            sp_name: document.getElementById('conn-sp-name').value || null
        };

        try {
            if (id) {
                await API.updateConnection(id, data);
                Toast.success('Conexión actualizada');
            } else {
                await API.createConnection(data);
                Toast.success('Conexión creada');
            }
            Modal.close('conn-form');
            this.load();
        } catch (e) {
            Toast.error(e.message);
        }
    },

    async edit(id) {
        try {
            const resp = await API.getConnection(id);
            this.openForm(resp.data);
        } catch (e) {
            Toast.error(e.message);
        }
    },

    async test(id) {
        const card = document.getElementById(`conn-${id}`);
        const btn = card.querySelector('.btn-success');
        const original = btn.textContent;
        btn.textContent = 'Probando...';
        btn.disabled = true;

        try {
            const resp = await API.testConnection(id);
            Toast.success(`${resp.message} - Versión: ${resp.data.server_version}`);
        } catch (e) {
            Toast.error(e.message);
        } finally {
            btn.textContent = original;
            btn.disabled = false;
        }
    },

    use(id) {
        const conn = this.connections.find(c => c.id == id);
        if (conn) {
            App.setConnection(conn, conn.database_name);
            Toast.info(`Usando conexión: ${conn.name}`);
            App.navigate('query');
        }
    },

    async confirmDelete(id) {
        const conn = this.connections.find(c => c.id == id);
        const ok = await Confirm.delete(
            'Eliminar conexión',
            `¿Eliminar la conexión "${conn?.name}"? Esta acción no se puede deshacer.`
        );
        if (ok) this.delete(id);
    },

    async delete(id) {
        try {
            await API.deleteConnection(id);
            Toast.success('Conexión eliminada');
            this.load();
        } catch (e) {
            Toast.error(e.message);
        }
    },

    escHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
};
