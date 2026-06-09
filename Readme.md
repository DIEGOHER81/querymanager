# PHPAdmin - Query Manager v1.0.0

Sistema web para gestionar, construir y ejecutar consultas SQL contra bases de datos MySQL y SQL Server, con soporte de ejecución directa o mediante JSON a través de procedimientos almacenados genéricos.

## Requisitos

- PHP 8.0+ con extensiones: `pdo_sqlite`, `pdo_mysql`, `pdo_sqlsrv` (según motor), `openssl`, `json`
- Apache con mod_rewrite habilitado (XAMPP)
- MySQL 5.7+ / MariaDB 10.2+ y/o SQL Server 2016+

## Instalación

1. Copiar la carpeta `phpadmin` dentro de `htdocs` de XAMPP.
2. Asegurar que Apache esté corriendo.
3. Acceder a `http://localhost/phpadmin/`
4. El sistema creará automáticamente su base de datos interna SQLite en `/data/app.sqlite`.
5. La clave de encriptación se generará automáticamente en `/config/.encryption_key`.

## Módulos

### 1. Conexiones
- CRUD completo de conexiones a MySQL y SQL Server.
- Contraseñas encriptadas con AES-256-CBC.
- Prueba de conectividad en tiempo real.

### 2. Explorador de Base de Datos
- Visualización de tablas, vistas, procedimientos y funciones.
- Detalle de columnas con tipo de dato, nullable, primary key, default.
- Generación automática de sentencias SELECT, INSERT, UPDATE.

### 3. Constructor de Consultas
- **Editor SQL**: Editor con resaltado, soporte Tab y Ctrl+Enter.
- **Constructor Visual**: Drag & drop de tablas, selección de columnas, generación automática de SQL.

### 4. Ejecución de Consultas

#### Modo Directo
Ejecuta la consulta SQL directamente contra la base de datos seleccionada.

#### Modo JSON (Stored Procedure)
Envía la consulta como JSON a un procedimiento almacenado genérico. Soporta **CRUD completo** (SELECT, INSERT, UPDATE, DELETE).

**Formato del JSON:**
```json
{
    "query": "La sentencia SQL con placeholders ?",
    "params": ["valor1", "valor2"],
    "limit": 10
}
```

- `query`: La sentencia SQL. Usar `?` como placeholder para valores dinámicos.
- `params`: Array con los valores que reemplazan cada `?` en orden. Enviar `[]` si no hay parámetros.
- `limit`: Solo aplica a SELECT. En INSERT/UPDATE/DELETE se ignora.

**Ejemplos CRUD completos:**

**SELECT** - Consultar datos con filtro:
```json
{
    "query": "SELECT * FROM clientes WHERE ciudad = ? AND estado = ?",
    "params": ["Bogota", "activo"],
    "limit": 10
}
```

**SELECT con LIKE** - Busqueda parcial (los comodines `%` van en el valor, no en la query):
```json
{
    "query": "SELECT contactos.noidentifiacion, contactos.nombre FROM contactos, r_contacto_cliente, clientes WHERE contactos.idcontacto = r_contacto_cliente.idcontacto AND r_contacto_cliente.codcliente = clientes.codigo AND contactos.nombre LIKE ?",
    "params": ["%NELSON%"],
    "limit": 100
}
```

**INSERT** - Crear un registro:
```json
{
    "query": "INSERT INTO clientes (codigo, nombre, nit, ciudad) VALUES (?, ?, ?, ?)",
    "params": ["COD-002", "Empresa ABC", "900123456", "Bogota"],
    "limit": 10
}
```

**UPDATE** - Actualizar registros:
```json
{
    "query": "UPDATE clientes SET nombre = ?, estado = ? WHERE codigo = ?",
    "params": ["Nuevo Nombre", "activo", "COD-002"],
    "limit": 10
}
```

**DELETE** - Eliminar registros:
```json
{
    "query": "DELETE FROM clientes WHERE codigo = ?",
    "params": ["COD-002"],
    "limit": 10
}
```

**SELECT sin parametros** - Consulta directa sin filtros variables:
```json
{
    "query": "SELECT codigo, nombre, nit FROM clientes",
    "params": [],
    "limit": 50
}
```

**Comportamiento del SP segun la operacion:**
| Operacion | `limit` | Retorno |
|-----------|---------|---------|
| SELECT | Se aplica automaticamente si la query no trae LIMIT | Result set con las columnas de la consulta |
| INSERT | Se ignora | Filas afectadas |
| UPDATE | Se ignora | Filas afectadas |
| DELETE | Se ignora | Filas afectadas |

**Como instalar el SP:**
- Para MySQL: Ejecutar `sql/sp_mysql_json_query.sql` en la base de datos destino.
- Para SQL Server: Ejecutar `sql/sp_sqlserver_json_query.sql` en la base de datos destino.

**Uso desde codigo externo (ejemplo de integracion):**
```sql
-- MySQL
CALL sp_ExecuteJsonQuery('{"query":"SELECT * FROM clientes WHERE ciudad = ?","params":["Bogota"],"limit":10}');

-- SQL Server
EXEC sp_ExecuteJsonQuery @JsonInput = '{"query":"SELECT * FROM clientes WHERE ciudad = ?","params":["Bogota"],"limit":10}';
```

**Uso desde PHP (ejemplo de integracion con otro sistema):**
```php
$json = json_encode([
    'query'  => 'SELECT * FROM clientes WHERE estado = ?',
    'params' => ['activo'],
    'limit'  => 10
]);

// MySQL
$stmt = $pdo->prepare("CALL sp_ExecuteJsonQuery(?)");
$stmt->execute([$json]);
$resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

// SQL Server
$stmt = $pdo->prepare("EXEC sp_ExecuteJsonQuery @JsonInput = ?");
$stmt->execute([$json]);
$resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
```

**Uso desde JavaScript/fetch (ejemplo de integracion con frontend):**
```javascript
const response = await fetch('/phpadmin/api/query/execute-json', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
    },
    body: JSON.stringify({
        connection_id: 1,
        sql: 'SELECT * FROM clientes WHERE ciudad = ?',
        params: ['Bogota'],
        database: 'mi_base_datos'
    })
});
const data = await response.json();
// data.data.rows = array de resultados
```

### 5. Resultados
- **Vista Tabla**: Tabla interactiva con todos los resultados.
- **Vista JSON**: Formato JSON limitado a 10 registros para integración con otros sistemas.
- **Exportación**: CSV (separador ;), Excel (HTML), JSON (máximo 10 registros).

### 6. Manejo de Errores
Los errores se muestran con mensajes descriptivos incluyendo código de error y detalle del motor de BD.

### 7. Auditoría
Registro completo de todas las consultas ejecutadas:
- Fecha/hora, conexión, base de datos, consulta, modo de ejecución
- Tiempo de ejecución, filas afectadas/retornadas, estado (éxito/error)
- IP del usuario
- Dashboard con estadísticas y filtros avanzados

### 8. Autenticacion

- Login con usuario y contraseña (bcrypt).
- Usuario por defecto: `admin`, creado automaticamente al iniciar la app por primera vez con una contraseña **aleatoria** (no hay clave fija). La clave se guarda una sola vez en `data/.initial_password` y debe cambiarse al primer ingreso. Si se pierde el acceso, usar `reset_admin.php` (ver "Scripts de Mantenimiento y Diagnostico").
- Sesion PHP con verificacion automatica al refrescar la pagina.
- Roles: `admin` (acceso total + gestion de usuarios) y `user` (acceso a conexiones, explorador, consultas, auditoria).
- Cambio de contraseña desde la sesion activa.
- Logout con destruccion de sesion.

### 9. Gestion de Usuarios (solo admin)

- CRUD completo de usuarios.
- Campos: usuario, nombre completo, contraseña, rol (admin/user), activo/inactivo.
- No permite eliminar el ultimo administrador.
- Registro de ultimo acceso.

## Scripts de Mantenimiento y Diagnostico

La raiz del proyecto incluye tres scripts PHP de uso puntual (no forman parte de la aplicacion en si). Se ejecutan directamente desde el navegador o por consola, y estan pensados para instalacion, diagnostico y recuperacion de acceso.

> **IMPORTANTE (seguridad):** Estos tres archivos permiten ver credenciales o restablecer la contraseña del administrador a quien pueda abrir su URL. **Deben eliminarse del servidor despues de usarlos** y nunca subirse a produccion. No deben versionarse (ver `.gitignore`).

### Como funciona la contraseña inicial

La aplicacion **no tiene una contraseña por defecto fija**. La primera vez que se ejecuta (cuando la tabla `users` esta vacia), `AuthService::ensureDefaultUser()` crea el usuario `admin` con una **contraseña aleatoria** de 12 caracteres hexadecimales, la hashea con bcrypt (irreversible) y escribe la clave en texto plano en `data/.initial_password` para que pueda leerse una sola vez. El usuario entra con esa clave y el sistema le obliga a cambiarla (`must_change_password = 1`).

Si ese archivo se pierde o ya se cambio la contraseña, la unica forma de recuperar el acceso es **restablecer** el hash en la BD con `reset_admin.php` (bcrypt no permite "leer" la clave original).

### reset_admin.php — Restablecer la contraseña de administrador

Restablece la contraseña de un usuario directamente en `data/app.sqlite`. Trae una salvaguarda: **sin el parametro `confirm=si` no realiza ningun cambio**, solo muestra las instrucciones, para evitar que se dispare por accidente.

**Uso por navegador:**

| URL | Que hace |
|-----|----------|
| `http://localhost/phpadmin/reset_admin.php` | Solo muestra instrucciones (no toca nada) |
| `http://localhost/phpadmin/reset_admin.php?confirm=si` | Resetea `admin` a la clave temporal `Admin1234` y activa `must_change_password=1` (la pedira al entrar) |
| `http://localhost/phpadmin/reset_admin.php?confirm=si&user=admin&password=MiClave123` | Establece el usuario y la contraseña exactos indicados (sin forzar cambio) |

**Uso por consola:**

```bash
php reset_admin.php                                    # muestra instrucciones
php reset_admin.php confirm=si                          # admin -> 'Admin1234', fuerza cambio
php reset_admin.php confirm=si password=MiClave123      # establece la clave indicada
php reset_admin.php confirm=si user=admin password=Xyz  # usuario y clave especificos
```

**Parametros:**

| Parametro | Obligatorio | Por defecto | Descripcion |
|-----------|-------------|-------------|-------------|
| `confirm` | Si (`si`) | — | Sin este valor el script no modifica nada |
| `user` | No | `admin` | Usuario a restablecer (debe existir) |
| `password` | No | `Admin1234` | Nueva contraseña. Si se omite, usa la temporal y fuerza el cambio al entrar |

**Comportamiento:** verifica que el usuario exista, hashea la nueva clave con bcrypt, reactiva la cuenta (`is_active=1`) y confirma el resultado mostrando si el hash verifica correctamente. Si NO se pasa `password`, activa `must_change_password=1`; si se pasa, deja la clave lista sin pedir cambio.

### test_api.php — Diagnostico de la API y el backend

Verificacion tecnica del entorno de ejecucion. Se abre en el navegador (`http://localhost/phpadmin/test_api.php`) y reporta:

- Version de PHP y extensiones requeridas (`pdo_sqlite`, `openssl`, `json`).
- Existencia y permisos de escritura de `data/` y `config/`.
- Carga de la configuracion (`config/app.php`) y constantes (`APP_ROOT`, `DATA_DIR`, `SQLITE_DB`).
- Conexion a SQLite y arranque del `SQLiteManager` (migraciones).
- Credenciales iniciales: muestra `data/.initial_password` si existe, o intenta crear el usuario `admin` por defecto si la BD aun no se inicializo.

Util cuando la aplicacion no carga o la API devuelve errores 500, para aislar si el problema es de extensiones, permisos o base de datos.

### check_setup.php — Verificacion de instalacion

Pensado para validar un despliegue (especialmente en hosting compartido). Se abre en el navegador (`http://localhost/phpadmin/check_setup.php`) y reporta:

- Existencia y permisos de `data/` y `config/` (avisa si faltan permisos 755/775).
- Estado de `data/app.sqlite` y de la clave de encriptacion `config/.encryption_key`.
- Credenciales iniciales (`data/.initial_password`); si no existen, intenta inicializar la BD y generarlas.
- Extensiones PHP (`pdo_sqlite`, `openssl`, `json`, `pdo_mysql`, `mbstring`), version de PHP y `mod_rewrite`.

Es el primer archivo a subir tras un deploy para confirmar que el entorno cumple los requisitos y obtener las credenciales de acceso. **Eliminar inmediatamente despues de verificar.**

### Resumen comparativo

| Script | Para que sirve | Cuando usarlo |
|--------|----------------|---------------|
| `check_setup.php` | Validar entorno/permisos y obtener credenciales iniciales | Tras un deploy o instalacion nueva |
| `test_api.php` | Diagnostico profundo de PHP, config, SQLite y API | Cuando la app o la API fallan |
| `reset_admin.php` | Restablecer la contraseña del admin | Cuando se pierde el acceso de administrador |

## Estructura del Proyecto

```
phpadmin/
├── index.php                          # Frontend SPA + login
├── .htaccess                          # Rewrite rules
├── reset_admin.php                    # [Mantenimiento] Restablecer contraseña admin (ELIMINAR tras usar)
├── test_api.php                       # [Diagnostico] Verificacion de API/backend (ELIMINAR tras usar)
├── check_setup.php                    # [Diagnostico] Verificacion de instalacion (ELIMINAR tras usar)
├── api/
│   ├── index.php                      # Router API (rutas publicas + protegidas)
│   ├── bootstrap.php                  # Inicializacion, auth middleware
│   ├── Controllers/
│   │   ├── AuthController.php         # Login, logout, usuarios
│   │   ├── ConnectionController.php   # CRUD conexiones
│   │   ├── BrowserController.php      # Explorador BD
│   │   ├── QueryController.php        # Ejecucion consultas
│   │   ├── ExportController.php       # Exportacion datos (CSV, XLSX, JSON)
│   │   └── AuditController.php        # Logs auditoria + favoritas
│   ├── Models/
│   │   └── Connection.php             # Modelo conexion
│   ├── Services/
│   │   ├── AuthService.php            # Autenticacion y gestion de usuarios
│   │   ├── DatabaseService.php        # Factory conexiones PDO
│   │   ├── SchemaService.php          # Introspección BD (tablas, columnas, params SP)
│   │   ├── QueryExecutionService.php  # Ejecucion consultas
│   │   ├── AuditService.php           # Servicio auditoria + favoritas
│   │   ├── EncryptionService.php      # Encriptacion AES-256
│   │   └── XlsxService.php           # Generador XLSX nativo (sin librerias)
│   └── Database/
│       └── SQLiteManager.php          # BD interna SQLite + migraciones
├── assets/
│   ├── css/app.css                    # Estilos (responsive, dark editor, steps)
│   ├── help-content.html             # Contenido de ayuda contextual
│   └── js/
│       ├── api-client.js             # Cliente API fetch + AbortController
│       ├── app.js                    # App principal, auth, navegacion, SweetAlert2
│       └── components/
│           ├── connections.js         # UI conexiones
│           ├── browser.js            # UI explorador (tablas, vistas, SPs, funciones)
│           ├── query-editor.js       # UI editor SQL + constructor visual + JSON steps
│           ├── audit.js              # UI auditoria + favoritas + detalle
│           ├── users.js              # UI gestion de usuarios (admin)
│           └── help.js               # Panel de ayuda contextual
├── sql/
│   ├── sp_mysql_json_query.sql       # SP generico MySQL
│   └── sp_sqlserver_json_query.sql   # SP generico SQL Server
├── config/
│   ├── app.php                       # Configuracion
│   └── .encryption_key               # Clave AES (auto-generada, protegida)
└── data/
    └── app.sqlite                    # BD interna (auto-generada)
```

## API Endpoints

### Autenticacion (publicas)

| Metodo | Ruta | Descripcion |
|--------|------|-------------|
| GET | /api/csrf-token | Token CSRF |
| POST | /api/auth/login | Iniciar sesion |
| GET | /api/auth/me | Usuario autenticado actual |

### Autenticacion (protegidas)

| Metodo | Ruta | Descripcion |
|--------|------|-------------|
| POST | /api/auth/logout | Cerrar sesion |
| POST | /api/auth/change-password | Cambiar contraseña |

### Usuarios (solo admin)

| Metodo | Ruta | Descripcion |
|--------|------|-------------|
| GET | /api/users | Listar usuarios |
| POST | /api/users | Crear usuario |
| PUT | /api/users/{id} | Actualizar usuario |
| DELETE | /api/users/{id} | Eliminar usuario |

### Conexiones

| Metodo | Ruta | Descripcion |
|--------|------|-------------|
| GET | /api/connections | Listar conexiones |
| GET | /api/connections/{id} | Detalle de conexion |
| POST | /api/connections | Crear conexion |
| PUT | /api/connections/{id} | Actualizar conexion |
| DELETE | /api/connections/{id} | Eliminar conexion |
| POST | /api/connections/{id}/test | Probar conectividad |

### Explorador de BD

| Metodo | Ruta | Descripcion |
|--------|------|-------------|
| GET | /api/browser/{id}/databases | Listar bases de datos |
| GET | /api/browser/{id}/tables | Listar tablas |
| GET | /api/browser/{id}/views | Listar vistas |
| GET | /api/browser/{id}/procedures | Listar procedimientos |
| GET | /api/browser/{id}/functions | Listar funciones |
| GET | /api/browser/{id}/columns/{tabla} | Columnas de tabla/vista |
| GET | /api/browser/{id}/routine-params/{nombre} | Parametros de SP/funcion |
| GET | /api/browser/{id}/routine-definition/{nombre} | Codigo fuente de SP/funcion |

### Consultas

| Metodo | Ruta | Descripcion |
|--------|------|-------------|
| POST | /api/query/execute | Ejecutar SQL directo |
| POST | /api/query/execute-json | Ejecutar via JSON SP |

### Exportacion

| Metodo | Ruta | Descripcion |
|--------|------|-------------|
| POST | /api/export/csv | Exportar CSV (separador ;, UTF-8 BOM) |
| POST | /api/export/excel | Exportar XLSX nativo (Office Open XML) |
| POST | /api/export/json | Exportar JSON (limitado a 10 registros) |

### Auditoria

| Metodo | Ruta | Descripcion |
|--------|------|-------------|
| GET | /api/audit | Logs con paginacion y filtros |
| GET | /api/audit/stats | Estadisticas generales |
| GET | /api/audit/favorites | Consultas marcadas como favoritas |
| POST | /api/audit/{id}/toggle-favorite | Marcar/desmarcar favorita |
| DELETE | /api/audit | Limpiar logs (conserva favoritas) |

## Seguridad

### Estado actual (implementado)

| Capa | Detalle |
|------|---------|
| Autenticacion | Login con usuario/contraseña, sesiones PHP |
| Contraseñas de usuario | Hasheadas con bcrypt (password_hash) |
| Contraseñas de BD | Encriptadas con AES-256-CBC |
| CSRF | Token en todas las peticiones mutantes (POST/PUT/DELETE) |
| Roles | admin y user, validados en backend |
| Proteccion de archivos | .htaccess deniega acceso a /data/ y /config/ |
| BD interna | Prepared statements en todas las consultas SQLite |
| Sesion | Verificacion automatica, regeneracion de CSRF token en login |
| Headers HTTP | X-Content-Type-Options, X-Frame-Options, X-XSS-Protection, Referrer-Policy, CSP |
| Validacion de drivers | Verifica disponibilidad de pdo_mysql/pdo_sqlsrv antes de conectar |
| Auto-deteccion de rutas | api-client.js y api/index.php detectan basePath automaticamente |

### Lo que falta para publicacion en internet

**CRITICO: Esta aplicacion NO debe publicarse en internet sin las siguientes capas adicionales:**

| Capa faltante | Riesgo sin ella | Solucion |
|---------------|-----------------|----------|
| HTTPS | Credenciales viajan en texto plano | Certificado SSL (Let's Encrypt gratuito) |
| Rate limiting en login | Ataques de fuerza bruta | Implementar contador de intentos fallidos con bloqueo temporal |
| Bloqueo de cuenta | Fuerza bruta persistente | Bloquear cuenta tras N intentos fallidos |
| Headers de seguridad | XSS, clickjacking, sniffing | CSP, X-Frame-Options, X-Content-Type-Options, HSTS |
| Complejidad de contraseñas | Contraseñas debiles | Validar minimo 8 caracteres, mayuscula, numero, especial |
| 2FA | Sesion robada = acceso total | TOTP (Google Authenticator) |
| WAF / Proxy reverso | Ataques automatizados | Cloudflare, nginx como reverse proxy |
| Logs de login fallidos | No se detectan ataques | Registrar IP, usuario, timestamp de intentos fallidos |

**Riesgo principal:** Esta herramienta ejecuta SQL arbitrario contra bases de datos reales. Si un atacante obtiene acceso (credenciales robadas, sesion secuestrada), tiene acceso directo a las BDs configuradas. Esto es por diseño, ya que es la funcion de la herramienta, pero implica que la autenticacion debe ser extremadamente robusta.

**Recomendacion:** Para uso en internet, agregar como minimo HTTPS + rate limiting + bloqueo de cuenta + headers de seguridad. Idealmente tambien 2FA y VPN.

## Despliegue en Hosting Compartido

### Script de deploy

```powershell
# Generar paquete para hosting (raiz del dominio)
.\deploy.ps1 -BasePath "/" -Production -Zip

# Hosting en subdirectorio
.\deploy.ps1 -BasePath "/query-manager/" -Production -Zip

# Con documentacion y stored procedures
.\deploy.ps1 -IncludeDocs -IncludeSQL -BasePath "/" -Zip
```

### Paso a paso

1. Ejecutar `deploy.ps1` para generar el paquete limpio (sin datos sensibles)
2. Subir via FTP o cPanel al hosting
3. Verificar permisos: `data/` y `config/` deben tener escritura (755/775)
4. Acceder a la URL - el sistema se inicializa automaticamente
5. Obtener credenciales en `data/.initial_password` (via FTP o panel del hosting)
6. Login y cambio de contrasena obligatorio

### Auto-deteccion de rutas (v1.1.0)

A partir de v1.1.0, tanto `api-client.js` como `api/index.php` detectan automaticamente la ruta base donde esta instalada la aplicacion. No es necesario editar codigo para cambiar entre `/phpadmin/`, `/query-manager/` o `/`. Solo ajustar `RewriteBase` en `.htaccess`.

### Compatibilidad con hosting compartido

| Caracteristica | Hosting compartido (Linux) | VPS / Dedicado |
|----------------|---------------------------|----------------|
| MySQL / MariaDB | SI | SI |
| SQL Server | NO (requiere ODBC Driver) | SI |
| PHP 8.0+ | SI (verificar version) | SI |
| pdo_sqlite | SI (generalmente incluido) | SI |
| HTTPS (SSL) | SI (Let's Encrypt gratuito) | SI |

**Nota:** Si el hosting tiene `php_sqlsrv` en php.ini pero sin el ODBC Driver instalado, la aplicacion suprime el warning automaticamente y solo permite conexiones MySQL.

### Diagnostico de problemas en hosting

Subir `check_setup.php` (incluido en el proyecto) a la raiz del hosting y abrir en el navegador. Muestra:
- Estado de extensiones PHP
- Permisos de directorios
- Estado de la BD
- Credenciales iniciales
- **Eliminar inmediatamente despues de usar**

## Despliegue y acceso desde dispositivos moviles

### Opcion A: Red local (la mas segura, recomendada para oficina)

```
┌─────────────┐     WiFi     ┌─────────────┐
│ PC con XAMPP │◄────────────►│ Celular     │
│ 192.168.1.50│              │ Chrome      │
│ Puerto 80   │              │ http://192. │
└─────────────┘              └─────────────┘
```

- XAMPP corre en una PC de la oficina.
- Los celulares/tablets se conectan a la misma red WiFi.
- Acceden por IP local: `http://192.168.1.X/phpadmin/`
- El trafico nunca sale de la red local.
- No necesita HTTPS (trafico interno).

### Opcion B: VPN (acceso remoto seguro)

```
┌─────────────┐    VPN tunnel    ┌─────────────┐
│ Servidor    │◄────────────────►│ Celular     │
│ con XAMPP   │  (encriptado)    │ WireGuard   │
└─────────────┘                  └─────────────┘
```

- Se configura un servidor VPN (WireGuard, OpenVPN) en el servidor.
- El celular se conecta por VPN desde cualquier lugar.
- Accede como si estuviera en la red local.
- Seguro porque el trafico va encriptado por el tunel.

### Opcion C: Publicar con restricciones (internet)

```
┌──────────┐     ┌───────────┐     ┌─────────────┐
│ Celular  │────►│ Cloudflare│────►│ Servidor    │
│ Chrome   │HTTPS│ WAF       │     │ nginx+PHP   │
└──────────┘     └───────────┘     └─────────────┘
```

- Servidor con HTTPS (Let's Encrypt).
- Firewall con IP whitelist.
- Fail2ban para bloquear intentos de fuerza bruta.
- Reverse proxy (nginx) delante de Apache.
- Requiere implementar todas las capas de seguridad faltantes.

## Analisis PWA (Progressive Web App)

### Que es una PWA

Una PWA permite que la aplicacion web se instale en el celular o tablet como si fuera una app nativa, con icono en la pantalla de inicio, pantalla completa y carga rapida.

### Como se instalaria

**En Android (Chrome):**
1. Abrir `https://tuservidor/phpadmin/` en Chrome.
2. Aparece un banner automatico: "Agregar Query Manager a pantalla de inicio".
3. Tocar Instalar.
4. Se crea un icono en el escritorio, igual que una app nativa.
5. Al abrirla: pantalla completa, sin barra de URL, splash screen con logo.

**En iPhone/iPad (Safari):**
1. Abrir la URL en Safari.
2. Tocar el boton Compartir (cuadrado con flecha).
3. Tocar "Agregar a pantalla de inicio".
4. Se instala con icono y nombre.

**En PC/Laptop (Chrome/Edge):**
1. Abrir la URL.
2. En la barra de direccion aparece un icono de instalar.
3. Se instala como ventana independiente (sin pestañas del navegador).

### Comparacion con app nativa

```
App nativa del celular          PWA Query Manager
┌──────────────────┐           ┌──────────────────┐
│  Icono en home   │    =      │  Icono en home   │
│  Splash screen   │    =      │  Splash screen   │
│  Pantalla completa│   =      │  Pantalla completa│
│  Sin barra URL   │    =      │  Sin barra URL   │
│  App Store       │    ≠      │  Solo una URL    │
│  30MB descarga   │    ≠      │  ~500KB cache    │
│  Actualizar manual│   ≠      │  Auto-actualiza  │
└──────────────────┘           └──────────────────┘
```

### Ventajas de PWA para esta aplicacion

| Ventaja | Descripcion |
|---------|-------------|
| Instalar como app | Boton "Agregar a pantalla de inicio", se abre sin barra del navegador |
| Carga instantanea | CSS/JS se cachean con Service Worker, no se descargan cada vez |
| Sin tienda de apps | No necesitas publicar en Google Play ni App Store |
| Actualizaciones automaticas | Al cambiar archivos en el servidor, el Service Worker sincroniza |
| Experiencia fullscreen | Sin barra de URL, parece app nativa |

### Limitacion critica: requiere servidor encendido

**IMPORTANTE:** La PWA cachea solo los archivos de la interfaz (HTML, CSS, JS). Toda la logica de negocio (autenticacion, conexiones a BD, ejecucion de queries, auditoria) vive en el servidor PHP.

```
Servidor ENCENDIDO                    Servidor APAGADO
┌─────────────────────┐              ┌─────────────────────┐
│  La interfaz carga  │  SI          │  La interfaz carga  │  SI (cache)
│  Login              │  SI          │  Login              │  NO
│  Ver conexiones     │  SI          │  Ver conexiones     │  NO
│  Explorar tablas    │  SI          │  Explorar tablas    │  NO
│  Ejecutar consultas │  SI          │  Ejecutar consultas │  NO
│  Exportar Excel     │  SI          │  Exportar Excel     │  NO
│  Ver auditoria      │  SI          │  Ver auditoria      │  NO
└─────────────────────┘              └─────────────────────┘
```

**Analogia:** Es como una app bancaria. Si no hay internet puedes abrir la app y ver la pantalla, pero no puedes consultar saldo ni hacer transferencias porque el banco (servidor) no responde.

### Requisitos tecnicos para PWA

| Archivo | Funcion | Estado |
|---------|---------|--------|
| manifest.json | Define nombre, icono, colores, modo pantalla completa | Implementado |
| service-worker.js | Cachea archivos para carga rapida | Implementado |
| HTTPS | Obligatorio para PWA (excepto localhost) | Depende del despliegue |

### Escenario de uso como herramienta de trabajo

1. El admin instala la app en un servidor de la empresa (o PC con XAMPP).
2. Envia el link por WhatsApp/email: `http://192.168.1.50/phpadmin/`
3. Cada persona abre el link en su tablet y toca "Instalar".
4. El admin crea usuarios desde el modulo de Usuarios.
5. Cada usuario abre la app tocando el icono, como cualquier otra app.
6. **Requisito:** El servidor (XAMPP) debe estar encendido mientras se use la app.

### Opciones para servidor siempre disponible

| Opcion | Costo | Disponibilidad |
|--------|-------|----------------|
| PC de oficina con XAMPP | $0 (hardware existente) | Solo cuando esta encendida |
| Mini servidor (Raspberry Pi, NAS, PC viejo) | ~$50-100 una vez | 24/7 si se deja encendido |
| VPS en la nube (DigitalOcean, AWS, etc.) | ~$5-12/mes | 24/7, acceso desde cualquier lugar |
| Hosting compartido con PHP+SQLite | ~$3-8/mes | 24/7, limitaciones de rendimiento |

---

## Servidor PHP en Android con Termux

Esta seccion explica como convertir un celular o tablet Android en un servidor PHP completo usando Termux, para ejecutar Query Manager sin necesidad de una PC.

### Arquitectura

```
┌─────────────────────────────────────┐
│         Tablet/Celular Android       │
│                                      │
│  ┌─────────┐    ┌────────────────┐  │
│  │ Chrome  │───►│ Termux         │  │         ┌──────────────┐
│  │ (naveg.)│    │ Apache + PHP   │──┼────────►│ MySQL/SQL Srv│
│  └─────────┘    │ Puerto 8080    │  │  WiFi   │ (otro equipo)│
│                 └────────────────┘  │         └──────────────┘
│  http://localhost:8080/phpadmin/    │
└─────────────────────────────────────┘
```

Otros dispositivos en la misma red tambien pueden acceder usando la IP del tablet.

### Requisitos

- Android 7.0 o superior
- Minimo 2GB de RAM disponible
- ~500MB de espacio libre
- Conexion a la misma red donde estan las bases de datos

### Paso 1: Instalar Termux

1. **NO instalar desde Google Play** (version desactualizada).
2. Descargar desde F-Droid (tienda de apps libre):
   - Abrir en el navegador del celular: `https://f-droid.org/en/packages/com.termux/`
   - Descargar e instalar el APK.
   - Si pide permiso para "instalar desde origenes desconocidos", aceptar.
3. Abrir Termux. Aparece una terminal negra.

### Paso 2: Actualizar paquetes e instalar PHP + Apache

Copiar y pegar estos comandos **uno por uno** en Termux:

```bash
# Actualizar repositorios
pkg update -y && pkg upgrade -y

# Instalar PHP, Apache, git y herramientas
pkg install -y php-apache apache2 git zip unzip

# Instalar extensiones PHP necesarias
pkg install -y php-pdo php-openssl
```

### Paso 3: Configurar Apache

```bash
# Editar configuracion de Apache
nano $PREFIX/etc/apache2/httpd.conf
```

Buscar y modificar estas lineas (usar Ctrl+W para buscar en nano):

```apache
# Cambiar el puerto (Android no permite el 80 sin root)
Listen 8080

# Habilitar PHP - buscar la linea de LoadModule php y descomentarla
LoadModule php_module libexec/apache2/libphp.so

# Buscar DirectoryIndex y agregar index.php
DirectoryIndex index.php index.html
```

Guardar con `Ctrl+O`, Enter, `Ctrl+X`.

### Paso 4: Descargar Query Manager

```bash
# Ir a la carpeta web de Apache
cd $PREFIX/share/apache2/default-site/htdocs

# Opcion A: Si tienes el codigo en git
git clone <tu-repositorio> phpadmin

# Opcion B: Copiar desde el almacenamiento del celular
# Primero dar permiso de almacenamiento a Termux:
termux-setup-storage
# Luego copiar (ajustar la ruta segun donde descargaste los archivos):
cp -r ~/storage/downloads/phpadmin ./phpadmin

# Dar permisos de escritura a las carpetas de datos
chmod -R 777 phpadmin/data
chmod -R 777 phpadmin/config
```

### Paso 5: Ajustar la app para Termux

Editar el `.htaccess` de la app porque Apache en Termux tiene una estructura diferente:

```bash
nano phpadmin/.htaccess
```

Verificar que el `RewriteBase` sea correcto:

```apache
RewriteEngine On
RewriteBase /phpadmin/

# Proteger directorios sensibles
RewriteRule ^data/ - [F,L]
RewriteRule ^config/ - [F,L]

# API routing
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^api/(.*)$ api/index.php [QSA,L]
```

### Paso 6: Iniciar el servidor

```bash
# Iniciar Apache
apachectl start
```

Abrir Chrome en el **mismo celular** y navegar a:
```
http://localhost:8080/phpadmin/
```

Si quieres acceder desde **otro dispositivo** en la misma red, primero averigua la IP:
```bash
ifconfig wlan0 | grep "inet "
```
Luego desde el otro dispositivo: `http://192.168.X.X:8080/phpadmin/`

### Paso 7: Script de inicio rapido

Crear un script para arrancar el servidor con un solo comando:

```bash
# Crear el script
cat > ~/start-qm.sh << 'SCRIPT'
#!/data/data/com.termux/files/usr/bin/bash
echo "================================"
echo "  Query Manager - Servidor PHP"
echo "================================"

# Iniciar Apache
apachectl start 2>/dev/null

# Obtener IP
IP=$(ifconfig wlan0 2>/dev/null | grep "inet " | awk '{print $2}')

echo ""
echo "Servidor iniciado correctamente"
echo ""
echo "Acceso local:  http://localhost:8080/phpadmin/"
if [ -n "$IP" ]; then
    echo "Acceso en red:  http://$IP:8080/phpadmin/"
fi
echo ""
echo "Usuario: admin (clave inicial en data/.initial_password)"
echo ""
echo "Para detener: apachectl stop"
echo "================================"
SCRIPT

# Dar permisos de ejecucion
chmod +x ~/start-qm.sh
```

Ahora para iniciar el servidor solo necesitas abrir Termux y ejecutar:

```bash
~/start-qm.sh
```

### Paso 8: Inicio automatico al abrir Termux (opcional)

Si quieres que el servidor arranque automaticamente cada vez que abres Termux:

```bash
echo "~/start-qm.sh" >> ~/.bashrc
```

### Paso 9: Crear acceso directo en Android (opcional)

1. Instalar **Termux:Widget** desde F-Droid.
2. Crear la carpeta de shortcuts:
   ```bash
   mkdir -p ~/.shortcuts
   cp ~/start-qm.sh ~/.shortcuts/QueryManager
   ```
3. En la pantalla de inicio del celular, agregar un widget de Termux.
4. Aparecera "QueryManager" como opcion. Al tocarlo, inicia el servidor automaticamente.

### Comandos utiles en Termux

| Comando | Descripcion |
|---------|-------------|
| `~/start-qm.sh` | Iniciar el servidor |
| `apachectl stop` | Detener el servidor |
| `apachectl restart` | Reiniciar el servidor |
| `cat $PREFIX/var/log/apache2/error_log` | Ver errores de Apache |
| `php -m` | Ver extensiones PHP instaladas |
| `ifconfig wlan0` | Ver IP del dispositivo |

### Solucion de problemas comunes

| Problema | Solucion |
|----------|----------|
| "Permission denied" al acceder a data/ | `chmod -R 777 phpadmin/data phpadmin/config` |
| "Address already in use" al iniciar | `apachectl stop` y luego `apachectl start` |
| No carga PHP (descarga el archivo) | Verificar que `LoadModule php_module` este descomentado en httpd.conf |
| No conecta a MySQL/SQL Server | Verificar que el celular este en la misma red que el servidor de BD |
| "mod_rewrite not found" | `nano $PREFIX/etc/apache2/httpd.conf` y descomentar `LoadModule rewrite_module` |
| La app no crea la BD SQLite | Instalar: `pkg install php-pdo` y reiniciar Apache |

---

## VPN domestica gratuita con ZeroTier

Esta seccion explica como crear una red VPN privada y gratuita para conectar hasta 25 dispositivos (PC, celulares, tablets) de forma segura, permitiendo acceder a Query Manager como PWA desde cualquier lugar.

### Por que ZeroTier y no Hamachi

| Caracteristica | Hamachi | ZeroTier |
|----------------|---------|----------|
| Usuarios gratuitos | 5 | **25** |
| App Android | No oficial | **Si, oficial** |
| App iOS | No | **Si** |
| Velocidad | Limitada en version gratis | **Sin limites** |
| Open source | No | **Si** |
| Configuracion | Compleja | **Muy simple** |
| Estabilidad | Muchos bugs recientes | **Estable** |
| Funciona detras de NAT | A veces falla | **Siempre** |

### Arquitectura con ZeroTier

```
                    ┌──────────────────┐
                    │   ZeroTier Cloud  │
                    │   (controlador)   │
                    └────────┬─────────┘
                             │ Coordina la red
            ┌────────────────┼────────────────┐
            │                │                │
    ┌───────▼──────┐  ┌─────▼───────┐  ┌─────▼───────┐
    │ PC Oficina    │  │ Celular 1   │  │ Tablet 2    │
    │ XAMPP         │  │ Android     │  │ Android     │
    │ ZeroTier      │  │ ZeroTier    │  │ ZeroTier    │
    │ IP: 10.147.   │  │ IP: 10.147. │  │ IP: 10.147. │
    │     20.1      │  │     20.5    │  │     20.8    │
    └──────────────┘  └─────────────┘  └─────────────┘
         │
    ┌────▼─────┐
    │ MySQL    │
    │ SQL Srvr │
    └──────────┘

    El celular accede a: http://10.147.20.1/phpadmin/
    Como si estuviera en la misma red local, desde cualquier lugar del mundo.
```

La conexion es **peer-to-peer encriptada** (no pasa por servidores de ZeroTier, solo se usa para descubrimiento).

### Paso 1: Crear la red en ZeroTier (desde cualquier navegador)

1. Ir a `https://my.zerotier.com/` y crear una cuenta gratuita.
2. Hacer clic en **"Create A Network"**.
3. Se crea una red con un **Network ID** de 16 caracteres (ej: `a09acf023389e132`). **Anotar este ID**.
4. En la configuracion de la red:
   - **Name**: Poner un nombre descriptivo (ej: "QueryManager VPN").
   - **Access Control**: Dejar en **"Private"** (los dispositivos deben ser aprobados manualmente).
   - **IPv4 Auto-Assign**: Asegurarse de que este habilitado (rango por defecto: `10.147.20.0/24`).

### Paso 2: Instalar ZeroTier en el servidor (PC con XAMPP)

**Windows:**
1. Descargar desde `https://www.zerotier.com/download/`
2. Instalar el ejecutable.
3. Aparece un icono en la bandeja del sistema (junto al reloj).
4. Clic derecho > "Join New Network".
5. Pegar el **Network ID** del paso 1.
6. Clic en "Join".

**Linux (Ubuntu/Debian):**
```bash
curl -s https://install.zerotier.com | sudo bash
sudo zerotier-cli join <TU_NETWORK_ID>
```

**macOS:**
```bash
brew install zerotier-one
sudo zerotier-cli join <TU_NETWORK_ID>
```

### Paso 3: Instalar ZeroTier en celulares/tablets

**Android:**
1. Abrir Google Play Store.
2. Buscar **"ZeroTier One"** (desarrollador: ZeroTier, Inc.).
3. Instalar.
4. Abrir la app > tocar **"+"** > pegar el **Network ID**.
5. Activar el switch de la red.
6. Android pedira permiso para crear una VPN. Aceptar.

**iOS (iPhone/iPad):**
1. Abrir App Store.
2. Buscar **"ZeroTier One"**.
3. Instalar y configurar igual que Android.

### Paso 4: Autorizar los dispositivos

1. Volver a `https://my.zerotier.com/` > tu red.
2. En la seccion **"Members"** apareceran los dispositivos que se unieron.
3. Marcar la casilla **"Auth"** de cada dispositivo para autorizarlo.
4. Cada dispositivo recibira una IP automatica (ej: `10.147.20.1`, `10.147.20.5`, etc.).
5. **Anotar la IP del servidor** (la PC con XAMPP).

### Paso 5: Probar la conexion

Desde el celular (con ZeroTier conectado), abrir Chrome y navegar a:

```
http://10.147.20.1/phpadmin/
```

(Reemplazar `10.147.20.1` con la IP ZeroTier de tu PC servidor).

Si funciona, ya tienes acceso seguro desde cualquier lugar del mundo.

### Paso 6: Convertir en PWA y acceder como app

Una vez que tienes acceso estable via ZeroTier, puedes convertir la app en PWA. Los archivos necesarios son `manifest.json` y `service-worker.js` (ver seccion de PWA).

Con la PWA instalada en el celular:
1. El usuario abre Chrome y accede a `http://10.147.20.X/phpadmin/`.
2. Chrome ofrece "Agregar a pantalla de inicio".
3. Se instala como app.
4. Cada vez que abre la app, se conecta via ZeroTier al servidor.

**Flujo completo:**

```
1. Encender ZeroTier en el celular (siempre activo en segundo plano)
2. Tocar el icono de Query Manager en la pantalla de inicio
3. La app se abre en pantalla completa
4. Iniciar sesion y trabajar normalmente

No necesita:
- Configurar IP cada vez (ZeroTier mantiene la misma IP siempre)
- Estar en la misma WiFi (funciona desde cualquier red, incluso datos moviles)
- Puerto abierto en el router (ZeroTier perfora NAT automaticamente)
```

### Asignar IPs fijas en ZeroTier (recomendado)

Para que las IPs no cambien, en `https://my.zerotier.com/` > tu red > Members:
1. Buscar el dispositivo servidor.
2. En el campo de IP, escribir una IP fija (ej: `10.147.20.1`).
3. Hacer lo mismo para cada dispositivo si se desea.

### Gestion de usuarios VPN

| Accion | Como hacerlo |
|--------|-------------|
| Agregar dispositivo | Instalar ZeroTier + unirse con el Network ID |
| Autorizar | my.zerotier.com > Members > marcar "Auth" |
| Revocar acceso | my.zerotier.com > Members > desmarcar "Auth" |
| Ver conectados | my.zerotier.com > Members > columna "Last Seen" |
| Limite gratuito | **25 dispositivos** por red |

### Comparativa final: red local vs ZeroTier

| Aspecto | Red local (WiFi) | ZeroTier VPN |
|---------|-------------------|--------------|
| Funciona fuera de la oficina | No | **Si** |
| Funciona con datos moviles | No | **Si** |
| Velocidad | Maxima (LAN) | Casi igual (P2P) |
| Seguridad | Solo red interna | Encriptado 256-bit |
| Configuracion | IP puede cambiar | **IP fija siempre** |
| Costo | $0 | **$0 (hasta 25 dispositivos)** |
| Necesita abrir puertos | No | **No** |

### Solucion de problemas ZeroTier

| Problema | Solucion |
|----------|----------|
| No conecta desde el celular | Verificar que ZeroTier este activo (icono en barra de estado) |
| "REQUESTING_CONFIGURATION" | El dispositivo no ha sido autorizado en my.zerotier.com |
| Lento | Verificar que ambos dispositivos tengan buena conexion a internet |
| No carga la pagina | Verificar que XAMPP/Apache este corriendo en el servidor |
| Firewall bloquea | En Windows: permitir Apache en el Firewall de Windows |
| IP cambio | Asignar IP fija en la consola de ZeroTier |

---

## Prompt para Generar Landing Page Comercial

Prompt reutilizable para generar la landing page de este u otros proyectos con SEO completo, pricing y diseño profesional:

```
Genera una landing page comercial profesional en HTML autocontenido (todo el CSS inline,
sin dependencias externas excepto Google Fonts "Inter") para el proyecto [NOMBRE DEL PROYECTO].

## Estructura de secciones:
1. **Navbar** fija con glassmorphism (backdrop-filter blur), logo, links ancla a secciones y boton CTA
2. **Hero** con titulo gradient (text gradient via background-clip), subtitulo, dos botones (primario gradient + secundario outline), badge "Disponible ahora" con dot animado, y un **mockup visual de la app** construido con HTML/CSS (NO imagen) que muestre la interfaz real de la herramienta
3. **Trust bar** con logos/nombres de tecnologias compatibles en opacidad reducida
4. **Stats** en grid de 4 columnas con metricas clave del proyecto (numeros grandes + label)
5. **Features** - grid de cards (hover con elevacion + borde primario) con icono SVG inline (estilo Feather/Lucide, stroke, NO emojis ni entidades HTML unicode), titulo y descripcion
6. **Arquitectura** (seccion con fondo oscuro #0f172a) - capas numeradas con circulos + grid de tecnologias usadas
7. **"Como funciona"** - grid 2 columnas: pasos numerados a la izquierda + mockup visual a la derecha (terminal/codigo con dots rojo/amarillo/verde)
8. **Pricing** - 2 cards comparativas:
   - **Arrendamiento**: precio mensual USD, lista de beneficios con checkmarks SVG, boton outline
   - **Codigo Fuente**: precio unico USD, badge "Mas Popular", card con fondo oscuro y precio gradient, lista de beneficios, boton gradient primario, garantia de satisfaccion con icono SVG shield
9. **FAQ** - seccion con preguntas frecuentes en acordeones interactivos (click para abrir/cerrar con chevron SVG animado). Minimo 6 preguntas relevantes sobre el producto, precios, seguridad y soporte
10. **CTA** (fondo oscuro con radial gradient sutil) - titulo, subtitulo, botones de email y WhatsApp (con mensaje pre-llenado en la URL wa.me)
11. **Footer** con marca, links de navegacion, email, WhatsApp, enlace al sitio web y slogan de servicios

## SEO obligatorio:
- **Meta tags primarios**: title (max 60 chars), description (max 160 chars), keywords, author, robots (index,follow), language, revisit-after, rating, category
- **Canonical URL**: <link rel="canonical"> apuntando a la URL definitiva del producto
- **Open Graph**: og:type (product), og:url, og:title, og:description, og:image (1200x630), og:image:alt, og:site_name, og:locale, product:price:amount, product:price:currency
- **Twitter Cards**: twitter:card (summary_large_image), twitter:url, twitter:title, twitter:description, twitter:image, twitter:image:alt
- **JSON-LD SoftwareApplication**: name, description, url, applicationCategory, operatingSystem, softwareVersion, offers (ambos planes con precio y disponibilidad), featureList, screenshot, author (Organization con name, url, email, telephone, contactPoint)
- **JSON-LD Organization**: name, url, logo, email, telephone, address, sameAs
- **JSON-LD FAQPage**: mainEntity con todas las preguntas del FAQ visible (deben coincidir exactamente)
- **JSON-LD BreadcrumbList**: Empresa > Productos > Este producto
- **Semantic HTML**: role="navigation" en navbar, role="contentinfo" en footer, aria-label en secciones principales, aria-expanded en FAQ buttons, rel="author" en enlace a la empresa, rel="noopener noreferrer" target="_blank" en enlaces externos

## Reglas de diseno:
- Paleta: primary #2563eb, accent #7c3aed, gradients entre ambos, fondo claro/oscuro alternado
- TODOS los iconos deben ser SVG inline (stroke-width 1.5-2, stroke="currentColor"), NUNCA emojis ni entidades HTML de caracteres unicode (&#128xxx;)
- Bordes redondeados (12-24px), sombras sutiles, transiciones hover con translateY y box-shadow
- Responsive con media query 768px (sidebar oculto en mockup, grids a 1 columna)
- Tipografia Inter desde Google Fonts, pesos 300-900
- Smooth scroll en anchors, navbar con clase .scrolled al hacer scroll

## Contenido a personalizar:
- Nombre del producto: [NOMBRE]
- Descripcion corta: [DESCRIPCION]
- Caracteristicas principales: [LISTA DE FEATURES]
- Tecnologias: [LISTA DE TECNOLOGIAS]
- Arquitectura/capas: [CAPAS DEL SISTEMA]
- Flujo de uso (4 pasos): [PASOS]
- Metricas: [4 STATS]
- Precio arrendamiento: USD $[X]/mes con [BENEFICIOS]
- Precio codigo fuente: USD $[X] pago unico con [BENEFICIOS]
- Preguntas FAQ: [MINIMO 6 PREGUNTAS CON RESPUESTAS]
- Email de contacto: [EMAIL]
- WhatsApp (con codigo pais): [NUMERO]
- URL canonica del producto: [URL]
- Nombre de la empresa: [EMPRESA]
- URL del sitio web: [URL EMPRESA]
- Slogan/servicios de la empresa: [SLOGAN]

El archivo debe ser 100% funcional al abrirse en el navegador sin servidor.
```

### Ejemplo de uso con Query Manager:

| Campo | Valor |
|-------|-------|
| Nombre | Query Manager |
| Descripcion | Herramienta web para gestionar, ejecutar y auditar consultas SQL en MySQL y SQL Server |
| Email | comercial@desarrollaloya.com |
| WhatsApp | 573012550175 |
| URL canonica | https://desarrollaloya.com/query-manager |
| Empresa | DesarrollaLoYa |
| URL empresa | https://desarrollaloya.com |
| Precio arrendamiento | USD $49/mes |
| Precio codigo fuente | USD $499 pago unico |
