/**
 * Help Panel - Contextual help system
 */
const HelpUI = {
    isOpen: false,
    content: {},

    async init() {
        try {
            const resp = await fetch('assets/help-content.html');
            const html = await resp.text();
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');

            // Parse each section by id
            doc.querySelectorAll('[data-help]').forEach(section => {
                this.content[section.dataset.help] = section.innerHTML;
            });
        } catch (e) {
            console.warn('No se pudo cargar la ayuda:', e);
        }
    },

    toggle() {
        this.isOpen = !this.isOpen;
        const panel = document.getElementById('help-panel');
        panel.classList.toggle('open', this.isOpen);

        // Update nav active state
        document.querySelector('.nav-item[data-action="help"]')
            ?.classList.toggle('active', this.isOpen);

        if (this.isOpen) this.update();
    },

    close() {
        this.isOpen = false;
        document.getElementById('help-panel').classList.remove('open');
        document.querySelector('.nav-item[data-action="help"]')
            ?.classList.remove('active');
    },

    update() {
        if (!this.isOpen) return;

        const panel = App.currentPanel || 'connections';
        const body = document.getElementById('help-body');
        const title = document.getElementById('help-title');

        const titles = {
            connections: 'Conexiones',
            browser: 'Explorador de BD',
            query: 'Constructor de Consultas',
            multiquery: 'Multi-Query',
            crossjoin: 'Cross-Connection JOIN',
            compare: 'Comparar Esquemas',
            audit: 'Auditoría',
            users: 'Usuarios'
        };

        title.textContent = titles[panel] || 'Ayuda';

        if (this.content[panel]) {
            body.innerHTML = this.content[panel];
        } else {
            body.innerHTML = '<p style="color:var(--text-light);">No hay ayuda disponible para esta sección.</p>';
        }
    }
};
