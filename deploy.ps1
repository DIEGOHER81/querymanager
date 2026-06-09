#=============================================================================
# Query Manager v1.1.0 - Script de Publicacion para Hosting
# DesarrollaLoYa by Diego Hernandez
#
# Genera un directorio limpio listo para subir a hosting compartido o privado.
# Excluye archivos sensibles, de desarrollo y datos locales.
#
# Uso:
#   .\deploy.ps1                        # Genera en .\deploy\
#   .\deploy.ps1 -Output "C:\mi-deploy" # Genera en ruta personalizada
#   .\deploy.ps1 -BasePath "/qm/"       # Cambia el RewriteBase
#   .\deploy.ps1 -IncludeDocs           # Incluye documentacion
#   .\deploy.ps1 -IncludeSQL            # Incluye scripts SQL de stored procedures
#   .\deploy.ps1 -Zip                   # Genera ZIP adicional
#   .\deploy.ps1 -Production            # Modo produccion (DEBUG_MODE=false, sin docs)
#
#=============================================================================

param(
    [string]$Output = ".\deploy",
    [string]$BasePath = "/",
    [switch]$IncludeDocs,
    [switch]$IncludeSQL,
    [switch]$Zip,
    [switch]$Production,
    [switch]$Help
)

# ── Colores ────────────────────────────────────────────────────────────────
function Write-Step($msg)    { Write-Host "  [+] $msg" -ForegroundColor Green }
function Write-Info($msg)    { Write-Host "  [i] $msg" -ForegroundColor Cyan }
function Write-Warn($msg)    { Write-Host "  [!] $msg" -ForegroundColor Yellow }
function Write-Err($msg)     { Write-Host "  [X] $msg" -ForegroundColor Red }
function Write-Title($msg)   { Write-Host "`n  $msg" -ForegroundColor White -BackgroundColor DarkBlue }

# ── Ayuda ──────────────────────────────────────────────────────────────────
if ($Help) {
    Write-Host @"

  Query Manager - Script de Publicacion
  ======================================

  Genera un directorio limpio para desplegar en hosting.

  Parametros:
    -Output <ruta>     Directorio destino (default: .\deploy)
    -BasePath <path>   RewriteBase del .htaccess (default: / para raiz del dominio)
    -IncludeDocs       Incluir carpeta docs/ en el deploy
    -IncludeSQL        Incluir carpeta sql/ (stored procedures)
    -Zip               Generar archivo .zip adicional
    -Production        Modo produccion (sin docs, debug off)
    -Help              Mostrar esta ayuda

  Ejemplos:
    .\deploy.ps1 -Output "C:\publish" -BasePath "/" -Production -Zip
    .\deploy.ps1 -IncludeDocs -IncludeSQL
    .\deploy.ps1 -BasePath "/mi-app/" -Zip

"@ -ForegroundColor Gray
    exit 0
}

# ── Variables ──────────────────────────────────────────────────────────────
$SourceDir = $PSScriptRoot
if (-not $SourceDir) { $SourceDir = (Get-Location).Path }
$DeployDir = $Output
$Timestamp = Get-Date -Format "yyyy-MM-dd_HHmmss"
$Version   = "1.1.0"

Write-Host ""
Write-Host "  ======================================================" -ForegroundColor Blue
Write-Host "   Query Manager v$Version - Generador de Deploy" -ForegroundColor White
Write-Host "   DesarrollaLoYa by Diego Hernandez" -ForegroundColor Gray
Write-Host "  ======================================================" -ForegroundColor Blue
Write-Host ""

# ── Validar origen ─────────────────────────────────────────────────────────
if (-not (Test-Path "$SourceDir\index.php")) {
    Write-Err "No se encontro index.php en $SourceDir"
    Write-Err "Ejecute este script desde la raiz del proyecto."
    exit 1
}

Write-Info "Origen: $SourceDir"
Write-Info "Destino: $DeployDir"
Write-Info "BasePath: $BasePath"
Write-Info "Modo: $(if ($Production) { 'PRODUCCION' } else { 'Desarrollo' })"
Write-Host ""

# ── Limpiar destino previo ─────────────────────────────────────────────────
if (Test-Path $DeployDir) {
    Write-Warn "El directorio $DeployDir ya existe. Limpiando..."
    Remove-Item -Recurse -Force $DeployDir
}

New-Item -ItemType Directory -Force -Path $DeployDir | Out-Null
Write-Step "Directorio de deploy creado"

# ── Archivos raiz ──────────────────────────────────────────────────────────
Write-Title " Copiando archivos raiz "

$rootFiles = @(
    "index.php",
    "manifest.json",
    "service-worker.js"
)

foreach ($file in $rootFiles) {
    $src = Join-Path $SourceDir $file
    if (Test-Path $src) {
        Copy-Item $src -Destination $DeployDir
        Write-Step $file
    } else {
        Write-Warn "$file no encontrado, omitido"
    }
}

# ── .htaccess (con RewriteBase personalizado) ──────────────────────────────
Write-Title " Generando .htaccess "

$htaccess = @"
RewriteEngine On
RewriteBase $BasePath

# Protect sensitive directories
RewriteRule ^data/ - [F,L]
RewriteRule ^config/ - [F,L]
RewriteRule ^sql/ - [F,L]
RewriteRule ^docs/ - [F,L]

# Block access to .sqlite files
RewriteRule \.sqlite$ - [F,L]

# Block access to hidden files (.env, .gitignore, etc.)
RewriteRule (^\.|/\.) - [F,L]

# Block maintenance / diagnostic scripts (deben eliminarse, nunca exponerse)
RewriteRule ^(reset_admin|test_api|check_setup)\.php$ - [F,L]

# Security headers
<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
    Header set X-Frame-Options "DENY"
    Header set X-XSS-Protection "1; mode=block"
    Header set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>

# API routing
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^api/(.*)$ api/index.php [QSA,L]
"@

Set-Content -Path "$DeployDir\.htaccess" -Value $htaccess -Encoding UTF8
Write-Step ".htaccess generado con RewriteBase $BasePath"

# ── API (backend completo) ─────────────────────────────────────────────────
Write-Title " Copiando backend (api/) "

$apiDirs = @(
    "api\Controllers",
    "api\Database",
    "api\Models",
    "api\Services"
)

foreach ($dir in $apiDirs) {
    $src = Join-Path $SourceDir $dir
    $dst = Join-Path $DeployDir $dir
    if (Test-Path $src) {
        New-Item -ItemType Directory -Force -Path $dst | Out-Null
        Copy-Item "$src\*" -Destination $dst -Recurse -Force
        $count = (Get-ChildItem $dst -File -Recurse).Count
        Write-Step "$dir ($count archivos)"
    }
}

# Archivos sueltos en api/
foreach ($file in @("index.php", "bootstrap.php")) {
    $src = Join-Path $SourceDir "api\$file"
    if (Test-Path $src) {
        Copy-Item $src -Destination "$DeployDir\api\"
        Write-Step "api\$file"
    }
}

# ── Assets (frontend) ─────────────────────────────────────────────────────
Write-Title " Copiando frontend (assets/) "

$assetDirs = @(
    "assets\css",
    "assets\js",
    "assets\js\components",
    "assets\icons"
)

foreach ($dir in $assetDirs) {
    $src = Join-Path $SourceDir $dir
    $dst = Join-Path $DeployDir $dir
    if (Test-Path $src) {
        New-Item -ItemType Directory -Force -Path $dst | Out-Null
        Copy-Item "$src\*" -Destination $dst -Force -ErrorAction SilentlyContinue
        $count = (Get-ChildItem $dst -File -ErrorAction SilentlyContinue).Count
        Write-Step "$dir ($count archivos)"
    }
}

# help-content.html
$helpSrc = Join-Path $SourceDir "assets\help-content.html"
if (Test-Path $helpSrc) {
    Copy-Item $helpSrc -Destination "$DeployDir\assets\"
    Write-Step "assets\help-content.html"
}

# ── Config (solo app.php, sin encryption key) ──────────────────────────────
Write-Title " Configuracion "

New-Item -ItemType Directory -Force -Path "$DeployDir\config" | Out-Null

# Copiar app.php ajustando para produccion si corresponde
$appConfig = Get-Content "$SourceDir\config\app.php" -Raw
if ($Production) {
    $appConfig = $appConfig -replace "define\('DEBUG_MODE',\s*true\)", "define('DEBUG_MODE', false)"
    $appConfig = $appConfig -replace "define\('APP_VERSION',\s*'[^']*'\)", "define('APP_VERSION', '$Version')"
}
Set-Content -Path "$DeployDir\config\app.php" -Value $appConfig -Encoding UTF8
Write-Step "config\app.php $(if ($Production) { '(produccion: DEBUG_MODE=false)' } else { '' })"

# .htaccess de proteccion para config/
Set-Content -Path "$DeployDir\config\.htaccess" -Value "Deny from all" -Encoding UTF8
Write-Step "config\.htaccess (proteccion)"

# ── Data (directorio vacio con proteccion) ─────────────────────────────────
Write-Title " Directorio de datos "

New-Item -ItemType Directory -Force -Path "$DeployDir\data" | Out-Null
Set-Content -Path "$DeployDir\data\.htaccess" -Value "Deny from all" -Encoding UTF8
Write-Step "data\ creado (vacio, se genera automaticamente)"
Write-Info "app.sqlite se creara automaticamente en el primer acceso"
Write-Info ".encryption_key se generara automaticamente"

# ── Docs (opcional) ────────────────────────────────────────────────────────
if ($IncludeDocs -and -not $Production) {
    Write-Title " Documentacion "
    $docsSrc = Join-Path $SourceDir "docs"
    $docsDst = Join-Path $DeployDir "docs"
    if (Test-Path $docsSrc) {
        New-Item -ItemType Directory -Force -Path $docsDst | Out-Null
        Copy-Item "$docsSrc\*.html" -Destination $docsDst -Force
        # .htaccess para docs (proteger si no se quiere exponer)
        Set-Content -Path "$docsDst\.htaccess" -Value "Deny from all" -Encoding UTF8
        $count = (Get-ChildItem $docsDst -Filter "*.html").Count
        Write-Step "docs\ ($count documentos copiados, protegidos por .htaccess)"
        Write-Info "Para hacer docs publicos, elimine docs\.htaccess"
    }
} else {
    Write-Info "Documentacion omitida $(if ($Production) { '(modo produccion)' } else { '(use -IncludeDocs para incluir)' })"
}

# ── SQL scripts (opcional) ─────────────────────────────────────────────────
if ($IncludeSQL) {
    Write-Title " Scripts SQL "
    $sqlSrc = Join-Path $SourceDir "sql"
    $sqlDst = Join-Path $DeployDir "sql"
    if (Test-Path $sqlSrc) {
        New-Item -ItemType Directory -Force -Path $sqlDst | Out-Null
        Copy-Item "$sqlSrc\*.sql" -Destination $sqlDst -Force
        Set-Content -Path "$sqlDst\.htaccess" -Value "Deny from all" -Encoding UTF8
        $count = (Get-ChildItem $sqlDst -Filter "*.sql").Count
        Write-Step "sql\ ($count scripts copiados, protegidos)"
    }
} else {
    Write-Info "Scripts SQL omitidos (use -IncludeSQL para incluir)"
}

# ── Archivos excluidos (verificacion) ──────────────────────────────────────
Write-Title " Verificacion de seguridad "

$sensitiveFiles = @(
    "data\app.sqlite",
    "config\.encryption_key",
    "data\.initial_password",
    ".gitignore",
    ".env",
    "deploy.ps1",
    "Readme.md",
    "generate-icons.php",
    "reset_admin.php",
    "test_api.php",
    "check_setup.php"
)

$allClean = $true
foreach ($file in $sensitiveFiles) {
    $check = Join-Path $DeployDir $file
    if (Test-Path $check) {
        Write-Err "ALERTA: $file encontrado en deploy! Eliminando..."
        Remove-Item $check -Force
        $allClean = $false
    }
}

if ($allClean) {
    Write-Step "Sin archivos sensibles en el deploy"
} else {
    Write-Warn "Se eliminaron archivos sensibles del deploy"
}

# Verificar que los .htaccess de proteccion existan
$protectedDirs = @("config", "data")
foreach ($dir in $protectedDirs) {
    $htaccessPath = Join-Path $DeployDir "$dir\.htaccess"
    if (-not (Test-Path $htaccessPath)) {
        Set-Content -Path $htaccessPath -Value "Deny from all" -Encoding UTF8
        Write-Warn "Se creo .htaccess faltante en $dir\"
    }
}

# ── Resumen ────────────────────────────────────────────────────────────────
Write-Title " Resumen del deploy "

$totalFiles = (Get-ChildItem $DeployDir -File -Recurse).Count
$totalSize  = [math]::Round((Get-ChildItem $DeployDir -File -Recurse | Measure-Object -Property Length -Sum).Sum / 1KB, 1)

Write-Host ""
Write-Info "Archivos totales: $totalFiles"
Write-Info "Tamano total: ${totalSize} KB"
Write-Info "Directorio: $DeployDir"
Write-Host ""

# Listar estructura
Write-Host "  Estructura:" -ForegroundColor Gray
$dirs = Get-ChildItem $DeployDir -Directory -Recurse | Sort-Object FullName
foreach ($d in $dirs) {
    $rel = $d.FullName.Replace($DeployDir, "").TrimStart("\")
    $fileCount = (Get-ChildItem $d.FullName -File -ErrorAction SilentlyContinue).Count
    Write-Host "    /$rel/ ($fileCount archivos)" -ForegroundColor DarkGray
}
Write-Host ""

# ── ZIP (opcional) ─────────────────────────────────────────────────────────
if ($Zip) {
    Write-Title " Generando ZIP "
    $zipName = "query-manager-v${Version}_${Timestamp}.zip"
    $zipPath = Join-Path (Split-Path $DeployDir) $zipName

    if (Test-Path $zipPath) { Remove-Item $zipPath -Force }
    Compress-Archive -Path "$DeployDir\*" -DestinationPath $zipPath -CompressionLevel Optimal
    $zipSize = [math]::Round((Get-Item $zipPath).Length / 1KB, 1)
    Write-Step "ZIP generado: $zipName (${zipSize} KB)"
}

# ── Instrucciones finales ──────────────────────────────────────────────────
Write-Host ""
Write-Host "  ======================================================" -ForegroundColor Green
Write-Host "   Deploy generado exitosamente!" -ForegroundColor White
Write-Host "  ======================================================" -ForegroundColor Green
Write-Host ""
Write-Host "  Pasos siguientes:" -ForegroundColor Yellow
Write-Host "  1. Suba el contenido de $DeployDir al hosting via FTP/cPanel" -ForegroundColor Gray
Write-Host "  2. Verifique que .htaccess y RewriteBase coincidan con la ruta" -ForegroundColor Gray
Write-Host "  3. Asegurese que las carpetas data/ y config/ tengan permisos 755" -ForegroundColor Gray
Write-Host "  4. Acceda a la URL y el sistema se inicializara automaticamente" -ForegroundColor Gray
Write-Host "  5. La contrasena inicial se genera en data/.initial_password" -ForegroundColor Gray
Write-Host ""

if ($BasePath -ne "/") {
    Write-Warn "IMPORTANTE: BasePath = '$BasePath' (subdirectorio)."
    Write-Warn "Verifique que coincida con la ruta real en el hosting."
    Write-Warn "Para despliegue en la raiz del dominio use -BasePath '/' (valor por defecto)."
    Write-Host ""
}

# ── Checklist de seguridad ─────────────────────────────────────────────────
Write-Host "  Checklist de seguridad:" -ForegroundColor Yellow
$checks = @(
    @{ Item = "data/app.sqlite excluido"; OK = -not (Test-Path "$DeployDir\data\app.sqlite") },
    @{ Item = "config/.encryption_key excluido"; OK = -not (Test-Path "$DeployDir\config\.encryption_key") },
    @{ Item = ".htaccess en config/"; OK = Test-Path "$DeployDir\config\.htaccess" },
    @{ Item = ".htaccess en data/"; OK = Test-Path "$DeployDir\data\.htaccess" },
    @{ Item = ".htaccess raiz con protecciones"; OK = Test-Path "$DeployDir\.htaccess" },
    @{ Item = "Sin .gitignore"; OK = -not (Test-Path "$DeployDir\.gitignore") },
    @{ Item = "Sin .env"; OK = -not (Test-Path "$DeployDir\.env") },
    @{ Item = "Sin deploy.ps1"; OK = -not (Test-Path "$DeployDir\deploy.ps1") },
    @{ Item = "Sin Readme.md"; OK = -not (Test-Path "$DeployDir\Readme.md") },
    @{ Item = "Sin reset_admin.php"; OK = -not (Test-Path "$DeployDir\reset_admin.php") },
    @{ Item = "Sin test_api.php"; OK = -not (Test-Path "$DeployDir\test_api.php") },
    @{ Item = "Sin check_setup.php"; OK = -not (Test-Path "$DeployDir\check_setup.php") }
)

foreach ($check in $checks) {
    if ($check.OK) {
        Write-Host "    [OK] $($check.Item)" -ForegroundColor Green
    } else {
        Write-Host "    [!!] $($check.Item)" -ForegroundColor Red
    }
}

Write-Host ""
Write-Host "  (c) $(Get-Date -Format 'yyyy') DesarrollaLoYa by Diego Hernandez" -ForegroundColor DarkGray
Write-Host ""
