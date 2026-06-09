/**
 * PHPAdmin Query Manager - Main Application
 */
const App = {
    currentPanel: 'connections',
    currentConnection: null,
    currentDatabase: null,

    currentUser: null,

    async init() {
        await API.init();
        Toast.init();
        HelpUI.init();
        this.bindNavigation();
        this.bindLoginForm();

        // Check if already logged in
        try {
            const resp = await API.getMe();
            if (resp.success && resp.data) {
                this.currentUser = resp.data;
                if (resp.data.must_change_password) {
                    this.hideLoading();
                    await this.forcePasswordChange();
                    return;
                }
                this.showApp();
                return;
            }
        } catch (e) { /* not logged in */ }

        this.showLogin();
    },

    bindLoginForm() {
        const pwdField = document.getElementById('login-password');
        if (pwdField) {
            pwdField.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') this.doLogin();
            });
        }
        const userField = document.getElementById('login-username');
        if (userField) {
            userField.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') document.getElementById('login-password').focus();
            });
        }
    },

    hideLoading() {
        const el = document.getElementById('loading-screen');
        if (el) el.style.display = 'none';
    },

    showLogin() {
        this.hideLoading();
        document.getElementById('login-screen').style.display = 'flex';
        document.getElementById('app-layout').style.display = 'none';
        document.getElementById('login-error').style.display = 'none';
        document.getElementById('login-username').value = '';
        document.getElementById('login-password').value = '';
        setTimeout(() => document.getElementById('login-username').focus(), 100);
    },

    showApp() {
        this.hideLoading();
        document.getElementById('login-screen').style.display = 'none';
        document.getElementById('app-layout').style.display = 'flex';
        this.updateUserInfo();
        this.showAdminMenu();
        this.restoreState();
        this.updateConnectionStatus();
        const savedPanel = sessionStorage.getItem('qm_panel') || 'connections';
        this.navigate(savedPanel);
    },

    async doLogin() {
        const username = document.getElementById('login-username').value.trim();
        const password = document.getElementById('login-password').value;
        const errorDiv = document.getElementById('login-error');
        const btn = document.getElementById('login-btn');

        if (!username || !password) {
            errorDiv.textContent = 'Ingresa usuario y contraseña';
            errorDiv.style.display = 'block';
            return;
        }

        btn.disabled = true;
        btn.textContent = 'Ingresando...';

        try {
            const resp = await API.login(username, password);
            this.currentUser = resp.data.user;
            API.csrfToken = resp.data.csrf_token;
            errorDiv.style.display = 'none';

            if (resp.data.must_change_password) {
                await this.forcePasswordChange();
                return;
            }

            this.showApp();
            Toast.success('Bienvenido, ' + (this.currentUser.full_name || this.currentUser.username));
        } catch (e) {
            errorDiv.textContent = e.message;
            errorDiv.style.display = 'block';
            document.getElementById('login-password').value = '';
            document.getElementById('login-password').focus();
        } finally {
            btn.disabled = false;
            btn.textContent = 'Iniciar Sesión';
        }
    },

    async forcePasswordChange() {
        const result = await Swal.fire({
            title: 'Cambio de contraseña obligatorio',
            html:
                '<p style="margin-bottom:16px;font-size:14px;color:#64748b;">Por seguridad, debe cambiar su contraseña antes de continuar.</p>' +
                '<input type="password" id="swal-current-pwd" class="swal2-input" placeholder="Contraseña actual">' +
                '<input type="password" id="swal-new-pwd" class="swal2-input" placeholder="Nueva contraseña">' +
                '<input type="password" id="swal-confirm-pwd" class="swal2-input" placeholder="Confirmar nueva contraseña">',
            focusConfirm: false,
            allowOutsideClick: false,
            allowEscapeKey: false,
            showCancelButton: true,
            cancelButtonText: 'Cerrar sesión',
            confirmButtonText: 'Cambiar contraseña',
            confirmButtonColor: '#2563eb',
            cancelButtonColor: '#64748b',
            preConfirm: () => {
                const current = document.getElementById('swal-current-pwd').value;
                const newPwd = document.getElementById('swal-new-pwd').value;
                const confirm = document.getElementById('swal-confirm-pwd').value;
                if (!current || !newPwd || !confirm) {
                    Swal.showValidationMessage('Todos los campos son requeridos');
                    return false;
                }
                if (newPwd.length < 4) {
                    Swal.showValidationMessage('La nueva contraseña debe tener al menos 4 caracteres');
                    return false;
                }
                if (newPwd !== confirm) {
                    Swal.showValidationMessage('Las contraseñas no coinciden');
                    return false;
                }
                if (current === newPwd) {
                    Swal.showValidationMessage('La nueva contraseña debe ser diferente a la actual');
                    return false;
                }
                return { current_password: current, new_password: newPwd };
            }
        });

        if (result.isDismissed) {
            await this.doLogout();
            return;
        }

        try {
            await API.changePassword(result.value.current_password, result.value.new_password);
            await Swal.fire({
                title: 'Contraseña actualizada',
                text: 'Su contraseña ha sido cambiada exitosamente.',
                icon: 'success',
                confirmButtonColor: '#2563eb'
            });
            this.showApp();
            Toast.success('Bienvenido, ' + (this.currentUser.full_name || this.currentUser.username));
        } catch (e) {
            Toast.error(e.message || 'Error al cambiar la contraseña');
            await this.forcePasswordChange();
        }
    },

    async doLogout() {
        try {
            await API.logout();
        } catch (e) { /* ignore */ }
        this.currentUser = null;
        sessionStorage.clear();
        this.showLogin();
        Toast.info('Sesión cerrada');
    },

    showAdminMenu() {
        const navUsers = document.getElementById('nav-users');
        if (navUsers) {
            navUsers.style.display = (this.currentUser && this.currentUser.role === 'admin') ? '' : 'none';
        }
    },

    updateUserInfo() {
        const user = this.currentUser;
        if (!user) return;
        const avatar = document.getElementById('sidebar-user-avatar');
        const name = document.getElementById('sidebar-user-name');
        const role = document.getElementById('sidebar-user-role');

        if (avatar) avatar.textContent = (user.full_name || user.username || '?').charAt(0).toUpperCase();
        if (name) name.textContent = user.full_name || user.username;
        if (role) role.textContent = user.role === 'admin' ? 'Administrador' : 'Usuario';
    },

    saveState() {
        sessionStorage.setItem('qm_panel', this.currentPanel);
        if (this.currentConnection) {
            sessionStorage.setItem('qm_conn', JSON.stringify(this.currentConnection));
        }
        if (this.currentDatabase) {
            sessionStorage.setItem('qm_db', this.currentDatabase);
        }
    },

    restoreState() {
        try {
            const conn = sessionStorage.getItem('qm_conn');
            if (conn) this.currentConnection = JSON.parse(conn);
            const db = sessionStorage.getItem('qm_db');
            if (db) this.currentDatabase = db;
        } catch (e) { /* ignore */ }
    },

    bindNavigation() {
        document.querySelectorAll('.nav-item[data-panel]').forEach(item => {
            item.addEventListener('click', () => {
                this.navigate(item.dataset.panel);
            });
        });
    },

    navigate(panel) {
        this.currentPanel = panel;

        // Update nav
        document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
        const activeNav = document.querySelector(`.nav-item[data-panel="${panel}"]`);
        if (activeNav) activeNav.classList.add('active');

        // Update panels
        document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
        const activePanel = document.getElementById(`panel-${panel}`);
        if (activePanel) activePanel.classList.add('active');

        // Update topbar title
        const titles = {
            connections: 'Configuración de Conexiones',
            browser: 'Explorador de Base de Datos',
            query: 'Constructor de Consultas',
            multiquery: 'Multi-Query: Consulta Simultánea',
            crossjoin: 'Cross-Connection JOIN',
            compare: 'Comparar Esquemas',
            audit: 'Registro de Auditoría',
            users: 'Administración de Usuarios'
        };
        document.getElementById('topbar-title').textContent = titles[panel] || '';

        // Load panel data
        switch (panel) {
            case 'connections': ConnectionsUI.load(); break;
            case 'browser': BrowserUI.load(); break;
            case 'query': QueryUI.load(); break;
            case 'multiquery': MultiQueryUI.load(); break;
            case 'crossjoin': CrossJoinUI.load(); break;
            case 'compare': SchemaCompareUI.load(); break;
            case 'audit': AuditUI.load(); break;
            case 'users': UsersUI.load(); break;
        }

        // Update help if open
        HelpUI.update();
        this.saveState();
    },

    setConnection(conn, db) {
        this.currentConnection = conn;
        this.currentDatabase = db;
        this.updateConnectionStatus();
        this.saveState();
    },

    updateConnectionStatus() {
        const sidebar = document.getElementById('sidebar-conn-status');
        const topbar = document.getElementById('topbar-conn-info');
        const conn = this.currentConnection;
        const db = this.currentDatabase;

        if (!sidebar) return;

        if (conn && conn.name) {
            const driver = (conn.driver === 'mysql') ? 'MySQL' : (conn.driver === 'sqlsrv') ? 'SQL Server' : (conn.driver || '');
            const dbName = db || conn.database_name || '';

            sidebar.style.color = '';
            sidebar.innerHTML =
                '<div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;">' +
                    '<span style="color:#22c55e;font-size:10px;">&#9679;</span>' +
                    '<strong style="color:#f8fafc;font-size:13px;">' + (conn.name || '') + '</strong>' +
                '</div>' +
                '<div style="color:#94a3b8;font-size:11px;">' +
                    driver + (dbName ? ' / ' + dbName : '') +
                '</div>';

            if (topbar) {
                topbar.innerHTML =
                    '<span style="color:#22c55e;">&#9679;</span> ' +
                    (conn.name || '') +
                    (dbName ? ' <span style="opacity:0.6;">(' + dbName + ')</span>' : '');
            }
        } else {
            sidebar.style.color = 'var(--text-light)';
            sidebar.innerHTML = '<span style="opacity:0.5;">&#9679;</span> Sin conexión activa';
            if (topbar) topbar.textContent = '';
        }
    },

    toggleSidebar() {
        document.querySelector('.sidebar').classList.toggle('collapsed');
    },

    toggleQuerySidebar() {
        const layout = document.querySelector('.query-layout');
        if (layout) layout.classList.toggle('sidebar-hidden');
    }
};

/**
 * Toast notifications
 */
const Toast = {
    container: null,

    init() {
        this.container = document.getElementById('toast-container');
    },

    show(message, type = 'info', duration = 4000) {
        const icons = {
            success: '&#10004;',
            error: '&#10006;',
            warning: '&#9888;',
            info: '&#8505;'
        };
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `<span>${icons[type] || ''}</span> <span>${message}</span>`;
        this.container.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(100%)';
            toast.style.transition = 'all 0.3s';
            setTimeout(() => toast.remove(), 300);
        }, duration);
    },

    success(msg) { this.show(msg, 'success'); },
    error(msg) { this.show(msg, 'error', 6000); },
    warning(msg) { this.show(msg, 'warning'); },
    info(msg) { this.show(msg, 'info'); }
};

/**
 * Modal helper
 */
const Modal = {
    open(id) {
        document.getElementById(id).classList.add('open');
    },
    close(id) {
        document.getElementById(id).classList.remove('open');
    }
};

/**
 * SweetAlert2 confirmation dialogs
 */
const Confirm = {
    async delete(title, text) {
        const result = await Swal.fire({
            title,
            text,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Eliminar',
            cancelButtonText: 'Cancelar',
            reverseButtons: true
        });
        return result.isConfirmed;
    },

    async warning(title, text) {
        const result = await Swal.fire({
            title,
            text,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d97706',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Continuar',
            cancelButtonText: 'Cancelar',
            reverseButtons: true
        });
        return result.isConfirmed;
    },

    async info(title, text) {
        const result = await Swal.fire({
            title,
            text,
            icon: 'info',
            showCancelButton: true,
            confirmButtonColor: '#2563eb',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Aceptar',
            cancelButtonText: 'Cancelar',
            reverseButtons: true
        });
        return result.isConfirmed;
    },

    success(title, text) {
        return Swal.fire({ title, text, icon: 'success', confirmButtonColor: '#2563eb' });
    },

    error(title, text) {
        return Swal.fire({ title, text, icon: 'error', confirmButtonColor: '#2563eb' });
    }
};

// Init on DOM ready
document.addEventListener('DOMContentLoaded', () => App.init());
