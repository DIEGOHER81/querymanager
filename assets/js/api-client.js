/**
 * API Client - Handles all communication with the backend
 */
const API = {
    baseUrl: (function() {
        // Auto-detect base path from current page URL
        const scripts = document.querySelectorAll('script[src*="api-client"]');
        if (scripts.length > 0) {
            const src = scripts[0].src;
            const idx = src.indexOf('/assets/');
            if (idx > -1) return src.substring(0, idx).replace(window.location.origin, '') + '/api';
        }
        // Fallback: derive from current page path
        const path = window.location.pathname;
        const dir = path.substring(0, path.lastIndexOf('/') + 1);
        return dir + 'api';
    })(),
    csrfToken: null,
    availableDrivers: [],

    async init() {
        await this.refreshCsrfToken();
    },

    async refreshCsrfToken() {
        const resp = await fetch(`${this.baseUrl}/csrf-token`);
        const data = await resp.json();
        if (data.success) {
            this.csrfToken = data.data.token;
            this.availableDrivers = data.data.drivers || [];
        }
    },

    hasDriver(driver) {
        return this.availableDrivers.includes(driver);
    },

    _currentAbortController: null,

    createAbortController() {
        this.cancelCurrentRequest();
        this._currentAbortController = new AbortController();
        return this._currentAbortController;
    },

    cancelCurrentRequest() {
        if (this._currentAbortController) {
            this._currentAbortController.abort();
            this._currentAbortController = null;
        }
    },

    async request(method, path, body = null, options2 = {}) {
        const options = {
            method,
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': this.csrfToken || ''
            }
        };

        if (options2.signal) {
            options.signal = options2.signal;
        }

        if (body && method !== 'GET') {
            options.body = JSON.stringify(body);
        }

        const url = path.startsWith('http') ? path : `${this.baseUrl}${path}`;
        const response = await fetch(url, options);

        // Handle file downloads
        const contentType = response.headers.get('content-type') || '';
        if (contentType.includes('text/csv') || contentType.includes('spreadsheetml') || contentType.includes('vnd.ms-excel') ||
            (contentType.includes('application/json') && response.headers.get('content-disposition'))) {
            if (!response.ok) throw new Error('Error al descargar el archivo');
            const blob = await response.blob();
            const disposition = response.headers.get('content-disposition') || '';
            const match = disposition.match(/filename="?([^"]+)"?/);
            const filename = match ? match[1] : 'export';
            this.downloadBlob(blob, filename);
            return { success: true, message: 'Archivo descargado' };
        }

        const data = await response.json();

        // Handle 401: redirect to login
        if (response.status === 401) {
            if (typeof App !== 'undefined' && App.showLogin) {
                App.showLogin();
            }
            throw new Error(data.error || 'No autenticado');
        }

        if (!response.ok || !data.success) {
            throw new Error(data.error || 'Error desconocido');
        }

        return data;
    },

    // Auth
    login(username, password) { return this.request('POST', '/auth/login', { username, password }); },
    logout() { return this.request('POST', '/auth/logout'); },
    getMe() { return this.request('GET', '/auth/me'); },
    changePassword(current_password, new_password) { return this.request('POST', '/auth/change-password', { current_password, new_password }); },

    // Users (admin)
    getUsers() { return this.request('GET', '/users'); },
    createUser(data) { return this.request('POST', '/users', data); },
    updateUser(id, data) { return this.request('PUT', `/users/${id}`, data); },
    deleteUser(id) { return this.request('DELETE', `/users/${id}`); },

    downloadBlob(blob, filename) {
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    },

    // Connections
    getConnections() { return this.request('GET', '/connections'); },
    getConnection(id) { return this.request('GET', `/connections/${id}`); },
    createConnection(data) { return this.request('POST', '/connections', data); },
    updateConnection(id, data) { return this.request('PUT', `/connections/${id}`, data); },
    deleteConnection(id) { return this.request('DELETE', `/connections/${id}`); },
    testConnection(id) { return this.request('POST', `/connections/${id}/test`); },
    testConnectionParams(data) { return this.request('POST', '/connections/test', data); },

    // Browser
    getDatabases(connId) { return this.request('GET', `/browser/${connId}/databases`); },
    getTables(connId, db) { return this.request('GET', `/browser/${connId}/tables?db=${encodeURIComponent(db || '')}`); },
    getViews(connId, db) { return this.request('GET', `/browser/${connId}/views?db=${encodeURIComponent(db || '')}`); },
    getProcedures(connId, db) { return this.request('GET', `/browser/${connId}/procedures?db=${encodeURIComponent(db || '')}`); },
    getFunctions(connId, db) { return this.request('GET', `/browser/${connId}/functions?db=${encodeURIComponent(db || '')}`); },
    getColumns(connId, table, db) { return this.request('GET', `/browser/${connId}/columns/${encodeURIComponent(table)}?db=${encodeURIComponent(db || '')}`); },
    getRoutineParams(connId, routine, db) { return this.request('GET', `/browser/${connId}/routine-params/${encodeURIComponent(routine)}?db=${encodeURIComponent(db || '')}`); },
    getRoutineDefinition(connId, routine, db) { return this.request('GET', `/browser/${connId}/routine-definition/${encodeURIComponent(routine)}?db=${encodeURIComponent(db || '')}`); },

    // Query
    executeQuery(data, signal) { return this.request('POST', '/query/execute', data, { signal }); },
    executeQueryJson(data, signal) { return this.request('POST', '/query/execute-json', data, { signal }); },

    // Export
    exportCsv(data) { return this.request('POST', '/export/csv', data); },
    exportExcel(data) { return this.request('POST', '/export/excel', data); },
    exportJson(data) { return this.request('POST', '/export/json', data); },

    // Audit
    getAuditLogs(params = {}) {
        const qs = new URLSearchParams(params).toString();
        return this.request('GET', `/audit?${qs}`);
    },
    getAuditStats() { return this.request('GET', '/audit/stats'); },
    toggleAuditFavorite(id) { return this.request('POST', `/audit/${id}/toggle-favorite`); },
    getAuditFavorites(params = {}) {
        const qs = new URLSearchParams(params).toString();
        return this.request('GET', `/audit/favorites?${qs}`);
    },
    clearAuditLogs() { return this.request('DELETE', '/audit'); }
};
