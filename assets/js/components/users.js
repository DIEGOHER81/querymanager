/**
 * Users UI - User management (admin only)
 */
const UsersUI = {
    users: [],

    async load() {
        try {
            const resp = await API.getUsers();
            this.users = resp.data || [];
            this.render();
        } catch (e) {
            document.getElementById('users-list').innerHTML =
                `<div class="empty-state"><h3>Error</h3><p>${e.message}</p></div>`;
        }
    },

    render() {
        const container = document.getElementById('users-list');

        if (this.users.length === 0) {
            container.innerHTML = '<div class="empty-state"><h3>No hay usuarios</h3></div>';
            return;
        }

        container.innerHTML = `
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Usuario</th>
                            <th>Nombre</th>
                            <th>Rol</th>
                            <th>Estado</th>
                            <th>Ultimo Acceso</th>
                            <th>Creado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${this.users.map(u => `
                            <tr>
                                <td>${u.id}</td>
                                <td><strong>${this.esc(u.username)}</strong></td>
                                <td>${this.esc(u.full_name || '')}</td>
                                <td><span class="badge ${u.role === 'admin' ? 'badge-warning' : 'badge-info'}">${u.role === 'admin' ? 'Admin' : 'Usuario'}</span></td>
                                <td><span class="badge ${u.is_active ? 'badge-success' : 'badge-error'}">${u.is_active ? 'Activo' : 'Inactivo'}</span></td>
                                <td>${u.last_login ? this.formatDate(u.last_login) : '<span style="color:var(--text-light);">Nunca</span>'}</td>
                                <td>${this.formatDate(u.created_at)}</td>
                                <td>
                                    <div style="display:flex;gap:4px;">
                                        <button class="btn btn-sm btn-outline" onclick="UsersUI.edit(${u.id})" title="Editar">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                        </button>
                                        <button class="btn btn-sm btn-danger" onclick="UsersUI.confirmDelete(${u.id}, '${this.esc(u.username)}')" title="Eliminar" style="padding:3px 6px;">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>`).join('')}
                    </tbody>
                </table>
            </div>`;
    },

    openForm(user = null) {
        const isEdit = user !== null;
        document.querySelector('#user-form .modal-header h3').textContent = isEdit ? 'Editar Usuario' : 'Nuevo Usuario';

        document.getElementById('user-id').value = isEdit ? user.id : '';
        document.getElementById('user-username').value = isEdit ? user.username : '';
        document.getElementById('user-fullname').value = isEdit ? (user.full_name || '') : '';
        document.getElementById('user-password').value = '';
        document.getElementById('user-password').placeholder = isEdit ? 'Dejar vacío si no cambia' : 'Contraseña';
        document.getElementById('user-role').value = isEdit ? user.role : 'user';
        document.getElementById('user-active').checked = isEdit ? !!user.is_active : true;

        Modal.open('user-form');
    },

    async save() {
        const id = document.getElementById('user-id').value;
        const data = {
            username: document.getElementById('user-username').value.trim(),
            full_name: document.getElementById('user-fullname').value.trim(),
            password: document.getElementById('user-password').value || undefined,
            role: document.getElementById('user-role').value,
            is_active: document.getElementById('user-active').checked ? 1 : 0
        };

        if (!data.username) { Toast.warning('El usuario es requerido'); return; }
        if (!id && !data.password) { Toast.warning('La contraseña es requerida para nuevos usuarios'); return; }

        try {
            if (id) {
                await API.updateUser(id, data);
                Toast.success('Usuario actualizado');
            } else {
                await API.createUser(data);
                Toast.success('Usuario creado');
            }
            Modal.close('user-form');
            this.load();
        } catch (e) {
            Toast.error(e.message);
        }
    },

    async edit(id) {
        const user = this.users.find(u => u.id === id);
        if (user) this.openForm(user);
    },

    async confirmDelete(id, username) {
        const ok = await Confirm.delete(
            'Eliminar usuario',
            `¿Eliminar el usuario "${username}"? Esta acción no se puede deshacer.`
        );
        if (ok) this.deleteUser(id);
    },

    async deleteUser(id) {
        try {
            await API.deleteUser(id);
            Toast.success('Usuario eliminado');
            this.load();
        } catch (e) {
            Toast.error(e.message);
        }
    },

    formatDate(iso) {
        if (!iso) return '';
        const d = new Date(iso);
        return d.toLocaleString('es-CO', { dateStyle: 'short', timeStyle: 'short' });
    },

    esc(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
};
