<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#2563eb">
    <meta name="description" content="Gestiona, construye y ejecuta consultas SQL contra MySQL y SQL Server">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Query Manager">
    <title>PHPAdmin - Query Manager</title>
    <link rel="manifest" href="manifest.json">
    <link rel="icon" type="image/svg+xml" href="assets/icons/icon.svg">
    <link rel="apple-touch-icon" href="assets/icons/icon-192.png">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= time() ?>">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
    <!-- Loading screen while checking session -->
    <div id="loading-screen" style="height:100vh;display:flex;align-items:center;justify-content:center;background:var(--bg-main);">
        <div style="text-align:center;">
            <div class="spinner" style="width:32px;height:32px;margin:0 auto 12px;"></div>
            <p style="color:var(--text-light);font-size:13px;">Cargando...</p>
        </div>
    </div>

    <!-- Login Screen (hidden until JS decides) -->
    <div id="login-screen" class="login-screen" style="display:none;">
        <div class="login-card">
            <div class="login-header">
                <div class="logo" style="width:48px;height:48px;font-size:20px;background:var(--primary);border-radius:12px;display:flex;align-items:center;justify-content:center;color:white;font-weight:700;">QM</div>
                <h1>Query Manager</h1>
                <p style="color:var(--secondary);font-size:13px;">Inicia sesión para continuar</p>
            </div>
            <div id="login-error" style="display:none;padding:10px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;color:#991b1b;font-size:13px;margin-bottom:16px;"></div>
            <div class="form-group">
                <label>Usuario</label>
                <input type="text" class="form-control" id="login-username" placeholder="admin" autofocus>
            </div>
            <div class="form-group">
                <label>Contraseña</label>
                <input type="password" class="form-control" id="login-password" placeholder="Contraseña">
            </div>
            <button class="btn btn-primary" style="width:100%;justify-content:center;" id="login-btn" onclick="App.doLogin()">
                Iniciar Sesión
            </button>
            <p style="text-align:center;margin-top:16px;font-size:11px;color:var(--text-light);">Ingrese sus credenciales de acceso</p>

            <!-- Legal footer -->
            <div style="margin-top:24px;padding-top:16px;border-top:1px solid var(--border);text-align:center;">
                <p style="font-size:10px;color:#94a3b8;margin:0 0 4px;line-height:1.6;">
                    &copy; <?= date('Y') ?> <strong style="color:#64748b;">DesarrollaLoYa</strong> by Diego Hernandez
                </p>
                <p style="font-size:9px;color:#94a3b8;margin:0;line-height:1.5;">
                    <a href="#" onclick="event.preventDefault();document.getElementById('legal-modal').style.display='flex';" style="color:#64748b;text-decoration:underline;">Politica de privacidad</a>
                    &nbsp;&middot;&nbsp;
                    <a href="#" onclick="event.preventDefault();document.getElementById('legal-modal').style.display='flex';" style="color:#64748b;text-decoration:underline;">Derechos de autor</a>
                </p>
            </div>
        </div>
    </div>

    <!-- Legal Modal -->
    <div id="legal-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);z-index:9999;align-items:center;justify-content:center;padding:20px;" onclick="if(event.target===this)this.style.display='none'">
        <div style="background:var(--bg-card);border-radius:16px;max-width:640px;width:100%;max-height:85vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
            <div style="padding:24px 28px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;">
                <h2 style="margin:0;font-size:18px;font-weight:700;">Informacion Legal</h2>
                <button onclick="document.getElementById('legal-modal').style.display='none'" style="background:none;border:none;cursor:pointer;font-size:22px;color:var(--text-light);padding:4px 8px;">&times;</button>
            </div>
            <div style="padding:24px 28px;">
                <!-- Derechos de autor -->
                <div style="margin-bottom:24px;">
                    <h3 style="font-size:15px;font-weight:700;margin:0 0 10px;color:var(--text);display:flex;align-items:center;gap:8px;">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M14.83 14.83a4 4 0 11-5.66-5.66 4 4 0 015.66 5.66z"/></svg>
                        Derechos de Autor y Propiedad Intelectual
                    </h3>
                    <p style="font-size:13px;color:var(--text-secondary);line-height:1.7;margin:0 0 8px;">
                        <strong>Query Manager</strong> es un producto de software desarrollado y propiedad intelectual de
                        <strong>DesarrollaLoYa</strong>, creado por <strong>Diego Hernandez</strong>.
                        Todos los derechos reservados &copy; <?= date('Y') ?>.
                    </p>
                    <p style="font-size:13px;color:var(--text-secondary);line-height:1.7;margin:0 0 8px;">
                        El codigo fuente, diseno, arquitectura, documentacion, logica de negocio, interfaces de usuario
                        y todos los elementos que componen esta aplicacion estan protegidos por las leyes de propiedad intelectual
                        y derechos de autor aplicables en la Republica de Colombia y tratados internacionales.
                    </p>

                    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 16px;margin:12px 0;">
                        <p style="font-size:12px;font-weight:700;color:#1e40af;margin:0 0 6px;">Modalidades de uso autorizadas:</p>
                        <div style="font-size:12px;color:#1e3a5f;line-height:1.7;">
                            <p style="margin:0 0 8px;">
                                <strong style="color:#2563eb;">1. Arrendamiento (SaaS / Suscripcion mensual):</strong><br>
                                El cliente obtiene acceso a la herramienta mediante pago mensual recurrente. La propiedad intelectual,
                                el codigo fuente y todos los derechos permanecen exclusivamente de <strong>DesarrollaLoYa</strong>.
                                El cliente no puede copiar, descompilar, distribuir ni sublicenciar el software.
                                Al finalizar la suscripcion, el acceso se suspende inmediatamente.
                                Las actualizaciones, soporte tecnico y mantenimiento estan incluidos durante la vigencia de la suscripcion.
                            </p>
                            <p style="margin:0;">
                                <strong style="color:#2563eb;">2. Adquisicion de Codigo Fuente (Licencia perpetua):</strong><br>
                                El cliente adquiere una licencia perpetua de uso sobre el codigo fuente mediante pago unico.
                                Puede modificar, personalizar y desplegar el software en servidores de su propiedad sin restriccion de cantidad.
                                <strong>No se transfiere la propiedad intelectual</strong>: DesarrollaLoYa sigue siendo el autor y titular de los derechos.
                                El cliente <strong>no puede revender, redistribuir ni sublicenciar</strong> el codigo fuente a terceros.
                                Incluye 3 meses de soporte tecnico y 1 ano de actualizaciones desde la fecha de compra.
                            </p>
                        </div>
                    </div>

                    <p style="font-size:13px;color:var(--text-secondary);line-height:1.7;margin:0;">
                        Cualquier uso fuera de las modalidades descritas, incluyendo la reproduccion, distribucion, ingenieria inversa
                        o uso no autorizado total o parcial, queda estrictamente prohibido y sera perseguido conforme a la ley.
                    </p>
                </div>

                <!-- Politica de privacidad -->
                <div style="margin-bottom:24px;">
                    <h3 style="font-size:15px;font-weight:700;margin:0 0 10px;color:var(--text);display:flex;align-items:center;gap:8px;">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        Politica de Privacidad y Tratamiento de Datos
                    </h3>
                    <p style="font-size:13px;color:var(--text-secondary);line-height:1.7;margin:0 0 8px;">
                        <strong>Query Manager</strong> recopila y almacena la siguiente informacion exclusivamente para su funcionamiento operativo:
                    </p>
                    <ul style="font-size:13px;color:var(--text-secondary);line-height:1.8;margin:0 0 8px;padding-left:20px;">
                        <li><strong>Credenciales de usuario</strong>: nombre de usuario y contrasena hasheada con bcrypt (nunca se almacena en texto plano).</li>
                        <li><strong>Credenciales de conexion a BD</strong>: host, puerto, usuario y contrasena de bases de datos (encriptados con AES-256-CBC).</li>
                        <li><strong>Registro de auditoria</strong>: consultas SQL ejecutadas, fecha/hora, IP del usuario, tiempo de ejecucion, resultado y conexion utilizada.</li>
                        <li><strong>Datos de sesion</strong>: informacion temporal del lado del servidor para mantener la sesion activa.</li>
                    </ul>

                    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px 16px;margin:12px 0;">
                        <p style="font-size:12px;font-weight:700;color:#166534;margin:0 0 4px;">Compromisos de privacidad:</p>
                        <ul style="font-size:12px;color:#14532d;line-height:1.8;margin:0;padding-left:16px;">
                            <li>Toda la informacion se almacena <strong>localmente</strong> en el servidor donde esta instalada la aplicacion.</li>
                            <li><strong>No se transmite, comparte ni vende informacion a terceros</strong> ni a servidores externos bajo ninguna circunstancia.</li>
                            <li>La aplicacion no utiliza cookies de rastreo, analiticas de terceros ni servicios de telemetria.</li>
                            <li>En modalidad de <strong>arrendamiento</strong>: DesarrollaLoYa puede acceder al servidor unicamente para tareas de soporte tecnico previamente autorizadas por el cliente.</li>
                            <li>En modalidad de <strong>codigo fuente</strong>: el cliente es el unico responsable de la infraestructura, los datos almacenados y el cumplimiento normativo.</li>
                        </ul>
                    </div>

                    <p style="font-size:13px;color:var(--text-secondary);line-height:1.7;margin:0;">
                        Los administradores del sistema son responsables de implementar las medidas de seguridad del servidor,
                        realizar copias de seguridad periodicas y garantizar el cumplimiento de las regulaciones de proteccion
                        de datos personales aplicables en su jurisdiccion (Ley 1581 de 2012 en Colombia, GDPR en Europa, etc.).
                    </p>
                </div>

                <!-- Terminos de uso -->
                <div style="margin-bottom:24px;">
                    <h3 style="font-size:15px;font-weight:700;margin:0 0 10px;color:var(--text);display:flex;align-items:center;gap:8px;">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                        Terminos y Condiciones de Uso
                    </h3>
                    <p style="font-size:13px;color:var(--text-secondary);line-height:1.7;margin:0 0 8px;">
                        Al utilizar <strong>Query Manager</strong>, en cualquiera de sus modalidades, el usuario y/o cliente acepta los siguientes terminos:
                    </p>

                    <p style="font-size:13px;font-weight:600;color:var(--text);margin:12px 0 6px;">Ambas modalidades:</p>
                    <ul style="font-size:13px;color:var(--text-secondary);line-height:1.8;margin:0 0 8px;padding-left:20px;">
                        <li>La herramienta ejecuta consultas SQL directamente contra bases de datos reales. <strong>El usuario es el unico responsable</strong> de las consultas que ejecuta y sus consecuencias.</li>
                        <li>DesarrollaLoYa no se hace responsable por perdida de datos, danos, interrupciones del servicio o perjuicios derivados del uso inadecuado de la herramienta.</li>
                        <li>El acceso esta sujeto a las credenciales proporcionadas por el administrador del sistema.</li>
                        <li>El registro de auditoria puede ser utilizado por el administrador para supervision, cumplimiento normativo y resolucion de incidentes.</li>
                    </ul>

                    <p style="font-size:13px;font-weight:600;color:var(--text);margin:12px 0 6px;">Modalidad Arrendamiento:</p>
                    <ul style="font-size:13px;color:var(--text-secondary);line-height:1.8;margin:0 0 8px;padding-left:20px;">
                        <li>El servicio se presta mientras la suscripcion este vigente y al dia en pagos.</li>
                        <li>DesarrollaLoYa se reserva el derecho de suspender el servicio por falta de pago tras 15 dias de gracia.</li>
                        <li>Las actualizaciones de funcionalidad y seguridad se aplican automaticamente durante la suscripcion.</li>
                        <li>El cliente puede cancelar en cualquier momento. No hay permanencia minima ni clausulas de penalizacion.</li>
                        <li>Al finalizar, el cliente conserva sus datos exportados pero pierde acceso a la herramienta.</li>
                    </ul>

                    <p style="font-size:13px;font-weight:600;color:var(--text);margin:12px 0 6px;">Modalidad Codigo Fuente:</p>
                    <ul style="font-size:13px;color:var(--text-secondary);line-height:1.8;margin:0 0 8px;padding-left:20px;">
                        <li>La licencia es perpetua, de uso ilimitado en servidores propios del cliente.</li>
                        <li>El cliente puede modificar y personalizar el codigo para sus necesidades internas.</li>
                        <li>Queda prohibido revender, redistribuir, sublicenciar o publicar el codigo fuente.</li>
                        <li>El soporte tecnico (3 meses incluidos) cubre instalacion, configuracion y resolucion de errores del producto original.</li>
                        <li>Las modificaciones realizadas por el cliente quedan fuera del alcance del soporte tecnico.</li>
                        <li>Las actualizaciones gratuitas (1 ano) se entregan como parches o nuevas versiones descargables.</li>
                        <li>Garantia de satisfaccion: 15 dias para solicitar reembolso completo si el producto no cumple expectativas.</li>
                    </ul>
                </div>

                <!-- Limitacion de responsabilidad -->
                <div style="margin-bottom:24px;">
                    <h3 style="font-size:15px;font-weight:700;margin:0 0 10px;color:var(--text);display:flex;align-items:center;gap:8px;">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        Limitacion de Responsabilidad
                    </h3>
                    <p style="font-size:13px;color:var(--text-secondary);line-height:1.7;margin:0 0 8px;">
                        <strong>Query Manager</strong> se proporciona "tal cual" (<em>as is</em>). En la maxima medida permitida por la ley:
                    </p>
                    <ul style="font-size:13px;color:var(--text-secondary);line-height:1.8;margin:0;padding-left:20px;">
                        <li>DesarrollaLoYa no garantiza que el software este libre de errores o que funcione de forma ininterrumpida.</li>
                        <li>La responsabilidad total de DesarrollaLoYa, ante cualquier reclamacion, se limita al monto pagado por el cliente en los ultimos 12 meses.</li>
                        <li>DesarrollaLoYa no sera responsable por danos indirectos, incidentales, especiales o consecuentes, incluyendo perdida de datos, lucro cesante o interrupcion de actividades comerciales.</li>
                        <li>El usuario reconoce que la herramienta accede a bases de datos en produccion y asume toda responsabilidad por las operaciones realizadas.</li>
                    </ul>
                </div>

                <!-- Jurisdiccion -->
                <div style="margin-bottom:24px;background:#faf5ff;border:1px solid #e9d5ff;border-radius:8px;padding:14px 18px;">
                    <p style="font-size:12px;color:#581c87;line-height:1.7;margin:0;">
                        <strong>Jurisdiccion y ley aplicable:</strong> Estos terminos se rigen por las leyes de la Republica de Colombia.
                        Cualquier controversia sera resuelta ante los tribunales competentes de la ciudad de domicilio del titular,
                        salvo acuerdo expreso de las partes para mediacion o arbitraje.
                    </p>
                </div>

                <!-- Contacto -->
                <div style="background:#f1f5f9;border-radius:8px;padding:14px 18px;">
                    <p style="font-size:13px;font-weight:700;color:var(--text);margin:0 0 6px;">Contacto y Soporte</p>
                    <p style="font-size:12px;color:var(--text-secondary);margin:0;line-height:1.8;">
                        <strong>Empresa:</strong> DesarrollaLoYa &nbsp;&middot;&nbsp; <strong>Titular:</strong> Diego Hernandez<br>
                        <strong>Email:</strong> comercial@desarrollaloya.com &nbsp;&middot;&nbsp;
                        <strong>WhatsApp:</strong> +57 301 255 0175<br>
                        <strong>Web:</strong> <a href="https://desarrollaloya.com" target="_blank" rel="noopener noreferrer" style="color:var(--primary);">desarrollaloya.com</a>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Main App (hidden until login) -->
    <div class="app-layout" id="app-layout" style="display:none;">
        <!-- Sidebar Navigation -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="logo">QM</div>
                <div>
                    <h1>Query Manager</h1>
                    <small>v1.0.0</small>
                </div>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-item active" data-panel="connections">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7"/>
                        <path d="M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4"/>
                        <path d="M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/>
                    </svg>
                    <span>Conexiones</span>
                </div>

                <div class="nav-item" data-panel="browser">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/>
                    </svg>
                    <span>Explorador</span>
                </div>

                <div class="nav-item" data-panel="query">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="16 18 22 12 16 6"/>
                        <polyline points="8 6 2 12 8 18"/>
                    </svg>
                    <span>Consultas</span>
                </div>

                <div class="nav-item" data-panel="multiquery">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="2" y="3" width="20" height="18" rx="2"/><line x1="2" y1="9" x2="22" y2="9"/><line x1="12" y1="9" x2="12" y2="21"/>
                    </svg>
                    <span>Multi-Query</span>
                </div>
                <div class="nav-item" data-panel="crossjoin">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="6" cy="12" r="4"/><circle cx="18" cy="12" r="4"/><line x1="10" y1="12" x2="14" y2="12"/>
                    </svg>
                    <span>Cross-Join</span>
                </div>

                <div class="nav-item" data-panel="compare">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 3v18"/><rect x="2" y="6" width="8" height="12" rx="1"/><rect x="14" y="6" width="8" height="12" rx="1"/>
                    </svg>
                    <span>Comparar</span>
                </div>

                <div class="nav-item" data-panel="backups">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>
                    </svg>
                    <span>Backups</span>
                </div>

                <div class="nav-divider"></div>

                <div class="nav-item" data-panel="audit">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <line x1="16" y1="13" x2="8" y2="13"/>
                        <line x1="16" y1="17" x2="8" y2="17"/>
                        <polyline points="10 9 9 9 8 9"/>
                    </svg>
                    <span>Auditoría</span>
                </div>
                <div id="nav-users" class="nav-item" data-panel="users" style="display:none;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/>
                        <path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/>
                    </svg>
                    <span>Usuarios</span>
                </div>

                <div class="nav-divider"></div>

                <div class="nav-item" data-action="help" onclick="HelpUI.toggle()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3"/>
                        <line x1="12" y1="17" x2="12.01" y2="17"/>
                    </svg>
                    <span>Ayuda</span>
                </div>
            </nav>

            <!-- Connection status -->
            <div id="sidebar-conn-status" style="padding:12px 20px;border-top:1px solid rgba(255,255,255,0.1);font-size:12px;color:var(--text-light);">
                <span style="opacity:0.5;">&#9679;</span> Sin conexión activa
            </div>

            <!-- User info -->
            <div style="padding:12px 20px;border-top:1px solid rgba(255,255,255,0.1);display:flex;align-items:center;gap:10px;">
                <div style="width:32px;height:32px;background:var(--primary);border-radius:50%;display:flex;align-items:center;justify-content:center;color:white;font-size:13px;font-weight:700;flex-shrink:0;" id="sidebar-user-avatar">?</div>
                <div style="flex:1;min-width:0;">
                    <div id="sidebar-user-name" style="font-size:12px;color:#f8fafc;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">-</div>
                    <div id="sidebar-user-role" style="font-size:10px;color:var(--text-light);">-</div>
                </div>
                <button onclick="App.doLogout()" title="Cerrar sesión" style="background:none;border:none;color:var(--text-light);cursor:pointer;padding:4px;border-radius:4px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                </button>
            </div>

            <!-- Legal footer sidebar -->
            <div style="padding:8px 20px;border-top:1px solid rgba(255,255,255,0.06);text-align:center;">
                <p style="font-size:9px;color:rgba(255,255,255,0.25);margin:0;line-height:1.5;">
                    &copy; <?= date('Y') ?> <a href="#" onclick="event.preventDefault();document.getElementById('legal-modal').style.display='flex';" style="color:rgba(255,255,255,0.35);text-decoration:none;">DesarrollaLoYa</a> by Diego Hernandez
                </p>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Bar -->
            <header class="topbar">
                <div style="display:flex;align-items:center;gap:12px;">
                    <button class="sidebar-toggle" onclick="App.toggleSidebar()" title="Mostrar/Ocultar menú">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
                        </svg>
                    </button>
                    <h2 id="topbar-title">Configuración de Conexiones</h2>
                </div>
                <div style="display:flex;align-items:center;gap:12px;">
                    <span id="topbar-conn-info" style="font-size:13px;color:var(--secondary);"></span>
                </div>
            </header>

            <!-- Content Area -->
            <div class="content-area">
                <!-- Panel: Connections -->
                <div class="panel active" id="panel-connections">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
                        <p style="color:var(--secondary);">Administra las conexiones a tus bases de datos MySQL y SQL Server.</p>
                        <button class="btn btn-primary" onclick="ConnectionsUI.openForm()">
                            + Nueva Conexión
                        </button>
                    </div>
                    <div id="connections-list"></div>
                </div>

                <!-- Panel: Browser -->
                <div class="panel" id="panel-browser">
                    <div id="browser-conn-selector"></div>
                    <div style="display:grid;grid-template-columns:1fr 1.5fr;gap:20px;margin-top:16px;">
                        <div id="browser-tree" class="card" style="min-height:400px;overflow-y:auto;"></div>
                        <div id="browser-detail">
                            <div class="empty-state">
                                <h3>Selecciona un objeto</h3>
                                <p>Escoge una tabla, vista, procedimiento o función para ver sus detalles.</p>
                            </div>
                        </div>
                    </div>
                    <div id="browser-content"></div>
                </div>

                <!-- Panel: Query -->
                <div class="panel" id="panel-query">
                    <div id="panel-query-content">
                        <div class="empty-state">
                            <h3>Cargando editor...</h3>
                        </div>
                    </div>
                </div>

                <!-- Panel: Multi-Query -->
                <div class="panel" id="panel-multiquery">
                    <div id="panel-multiquery-content">
                        <div class="empty-state"><h3>Cargando...</h3></div>
                    </div>
                </div>

                <!-- Panel: Cross-Join -->
                <div class="panel" id="panel-crossjoin">
                    <div id="panel-crossjoin-content">
                        <div class="empty-state"><h3>Cargando...</h3></div>
                    </div>
                </div>

                <!-- Panel: Schema Compare -->
                <div class="panel" id="panel-compare">
                    <div id="panel-compare-content">
                        <div class="empty-state"><h3>Cargando...</h3></div>
                    </div>
                </div>

                <!-- Panel: Audit -->
                <div class="panel" id="panel-audit">
                    <div id="audit-stats"></div>
                    <div id="audit-logs"></div>
                </div>

                <!-- Panel: Backups -->
                <div class="panel" id="panel-backups"></div>

                <!-- Panel: Users -->
                <div class="panel" id="panel-users">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
                        <p style="color:var(--secondary);">Administración de usuarios del sistema.</p>
                        <button class="btn btn-primary" onclick="UsersUI.openForm()">+ Nuevo Usuario</button>
                    </div>
                    <div id="users-list"></div>
                </div>
            </div>
        </main>
    </div>

    <!-- Modal: Connection Form -->
    <div class="modal-overlay" id="conn-form">
        <div class="modal">
            <div class="modal-header">
                <h3>Nueva Conexión</h3>
                <button class="modal-close" onclick="Modal.close('conn-form')">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="conn-id">

                <div class="form-group">
                    <label>Nombre de la conexión *</label>
                    <input type="text" class="form-control" id="conn-name" placeholder="Ej: Producción MySQL, Dev SQL Server...">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Motor de Base de Datos *</label>
                        <select class="form-control" id="conn-driver" onchange="ConnectionsUI.onDriverChange()">
                            <option value="mysql">MySQL</option>
                            <option value="sqlsrv">SQL Server</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Charset</label>
                        <input type="text" class="form-control" id="conn-charset" value="utf8mb4">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Host / Servidor *</label>
                        <input type="text" class="form-control" id="conn-host" placeholder="localhost" value="localhost">
                    </div>
                    <div class="form-group">
                        <label>Puerto</label>
                        <input type="number" class="form-control" id="conn-port" value="3306">
                    </div>
                </div>

                <div class="form-group">
                    <label>Base de Datos</label>
                    <input type="text" class="form-control" id="conn-database" placeholder="Nombre de la base de datos (opcional)">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Usuario *</label>
                        <input type="text" class="form-control" id="conn-username" placeholder="root">
                    </div>
                    <div class="form-group">
                        <label>Contraseña</label>
                        <input type="password" class="form-control" id="conn-password" placeholder="Dejar vacío si no cambia">
                    </div>
                </div>

                <div class="form-group">
                    <label>Nombre del Stored Procedure para modo JSON (opcional)</label>
                    <input type="text" class="form-control" id="conn-sp-name" placeholder="Ej: sp_ExecuteJsonQuery">
                    <small style="color:var(--text-light);font-size:11px;">Si se configura, el modo JSON enviará las consultas a este SP.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="Modal.close('conn-form')">Cancelar</button>
                <button class="btn btn-primary" onclick="ConnectionsUI.save()">Guardar</button>
            </div>
        </div>
    </div>

    <!-- Modal: User Form -->
    <div class="modal-overlay" id="user-form">
        <div class="modal">
            <div class="modal-header">
                <h3>Nuevo Usuario</h3>
                <button class="modal-close" onclick="Modal.close('user-form')">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="user-id">
                <div class="form-row">
                    <div class="form-group">
                        <label>Usuario *</label>
                        <input type="text" class="form-control" id="user-username" placeholder="nombre.usuario">
                    </div>
                    <div class="form-group">
                        <label>Nombre completo</label>
                        <input type="text" class="form-control" id="user-fullname" placeholder="Nombre Apellido">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Contraseña *</label>
                        <input type="password" class="form-control" id="user-password" placeholder="Dejar vacío si no cambia">
                    </div>
                    <div class="form-group">
                        <label>Rol *</label>
                        <select class="form-control" id="user-role">
                            <option value="user">Usuario</option>
                            <option value="admin">Administrador</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="user-active" checked> Activo
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="Modal.close('user-form')">Cancelar</button>
                <button class="btn btn-primary" onclick="UsersUI.save()">Guardar</button>
            </div>
        </div>
    </div>

    <!-- Help Panel (slide-in from right) -->
    <aside class="help-panel" id="help-panel">
        <div class="help-panel-header">
            <h3>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
                Ayuda: <span id="help-title">Conexiones</span>
            </h3>
            <button class="help-panel-close" onclick="HelpUI.close()">&times;</button>
        </div>
        <div class="help-panel-body" id="help-body">
            <p style="color:var(--text-light);">Cargando ayuda...</p>
        </div>
    </aside>

    <!-- Toast container -->
    <div class="toast-container" id="toast-container"></div>

    <!-- Scripts -->
    <script src="assets/js/api-client.js?v=<?= time() ?>"></script>
    <script src="assets/js/components/client-export.js?v=<?= time() ?>"></script>
    <script src="assets/js/components/sql-intellisense.js?v=<?= time() ?>"></script>
    <script src="assets/js/components/connections.js?v=<?= time() ?>"></script>
    <script src="assets/js/components/browser.js?v=<?= time() ?>"></script>
    <script src="assets/js/components/query-editor.js?v=<?= time() ?>"></script>
    <script src="assets/js/components/audit.js?v=<?= time() ?>"></script>
    <script src="assets/js/components/backups.js?v=<?= time() ?>"></script>
    <script src="assets/js/components/help.js?v=<?= time() ?>"></script>
    <script src="assets/js/components/users.js?v=<?= time() ?>"></script>
    <script src="assets/js/components/multi-query.js?v=<?= time() ?>"></script>
    <script src="assets/js/components/cross-join.js?v=<?= time() ?>"></script>
    <script src="assets/js/components/schema-compare.js?v=<?= time() ?>"></script>
    <script src="assets/js/app.js?v=<?= time() ?>"></script>

    <!-- PWA: Service Worker Registration -->
    <script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('./service-worker.js')
                .then(reg => console.log('SW registrado:', reg.scope))
                .catch(err => console.log('SW error:', err));
        });
    }
    </script>
</body>
</html>
