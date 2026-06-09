#=============================================================================
# Query Manager - Script de Backup
# DesarrollaLoYa by Diego Hernandez
#
# Respalda los archivos IRREEMPLAZABLES de la aplicacion:
#   - data/app.sqlite        (todos los datos: usuarios, conexiones, auditoria)
#   - config/.encryption_key (clave AES que descifra las contraseñas de conexion)
#
# IMPORTANTE: app.sqlite y .encryption_key son una PAREJA INSEPARABLE.
# Sin la clave, las contraseñas guardadas en la BD quedan ilegibles. Por eso
# este script SIEMPRE respalda ambos juntos en la misma copia.
#
# Uso:
#   .\backup.ps1                          # Copia a .\backups\<fecha>\
#   .\backup.ps1 -Dest "D:\Backups\QM"    # Carpeta destino personalizada
#   .\backup.ps1 -Zip                     # Genera un .zip por copia
#   .\backup.ps1 -Keep 10                 # Conserva solo las 10 copias mas recientes
#   .\backup.ps1 -Help                    # Ayuda
#=============================================================================

param(
    [string]$Source = $PSScriptRoot,
    [string]$Dest   = "",
    [int]$Keep      = 30,
    [switch]$Zip,
    [switch]$Help
)

function Write-Step($msg)  { Write-Host "  [+] $msg" -ForegroundColor Green }
function Write-Info($msg)  { Write-Host "  [i] $msg" -ForegroundColor Cyan }
function Write-Warn($msg)  { Write-Host "  [!] $msg" -ForegroundColor Yellow }
function Write-Err($msg)   { Write-Host "  [X] $msg" -ForegroundColor Red }
function Write-Title($msg) { Write-Host "`n  $msg" -ForegroundColor White -BackgroundColor DarkBlue }

if ($Help) {
    Write-Host @"

  Query Manager - Script de Backup
  =================================

  Respalda data/app.sqlite + config/.encryption_key (siempre juntos).

  Parametros:
    -Source <ruta>   Raiz del proyecto (default: carpeta del script)
    -Dest <ruta>     Carpeta donde guardar las copias (default: <proyecto>\backups)
    -Keep <n>        Cuantas copias conservar (default: 30, 0 = todas)
    -Zip             Comprimir cada copia en un .zip
    -Help            Mostrar esta ayuda

  Ejemplos:
    .\backup.ps1
    .\backup.ps1 -Dest "D:\Backups\QM" -Zip -Keep 15

"@ -ForegroundColor Gray
    exit 0
}

if (-not $Source) { $Source = (Get-Location).Path }
if (-not $Dest)   { $Dest = Join-Path $Source "backups" }

Write-Host ""
Write-Host "  ======================================================" -ForegroundColor Blue
Write-Host "   Query Manager - Backup de datos" -ForegroundColor White
Write-Host "  ======================================================" -ForegroundColor Blue
Write-Host ""

# ── Validar archivos de origen ───────────────────────────────────────────────
$dbFile  = Join-Path $Source "data\app.sqlite"
$keyFile = Join-Path $Source "config\.encryption_key"

if (-not (Test-Path $dbFile)) {
    Write-Err "No se encontro data\app.sqlite en $Source"
    Write-Err "Verifique la ruta o ejecute el script desde la raiz del proyecto."
    exit 1
}

$keyExists = Test-Path $keyFile
if (-not $keyExists) {
    Write-Warn "No se encontro config\.encryption_key."
    Write-Warn "Si usa PHPADMIN_ENCRYPTION_KEY por variable de entorno, respaldela aparte."
    Write-Warn "Si NO la usa, la BD tendra contraseñas que no se podran descifrar sin la clave."
}

# ── Crear carpeta de la copia (con fecha) ────────────────────────────────────
$stamp     = Get-Date -Format "yyyy-MM-dd_HHmmss"
$backupDir = Join-Path $Dest $stamp
New-Item -ItemType Directory -Force -Path "$backupDir\data"   | Out-Null
New-Item -ItemType Directory -Force -Path "$backupDir\config" | Out-Null

Write-Title " Copiando archivos "

# app.sqlite (+ archivos WAL/SHM si existen, para una copia consistente)
Copy-Item $dbFile -Destination "$backupDir\data\app.sqlite" -Force
Write-Step "data\app.sqlite"
foreach ($ext in @("-wal", "-shm")) {
    $sidecar = "$dbFile$ext"
    if (Test-Path $sidecar) {
        Copy-Item $sidecar -Destination "$backupDir\data\app.sqlite$ext" -Force
        Write-Step "data\app.sqlite$ext"
    }
}

# .encryption_key
if ($keyExists) {
    Copy-Item $keyFile -Destination "$backupDir\config\.encryption_key" -Force
    Write-Step "config\.encryption_key"
}

# Nota de recuperacion dentro de la copia
$readme = @"
Backup de Query Manager - $stamp

Para restaurar:
  1. Copie data\app.sqlite a la carpeta data\ del proyecto.
  2. Copie config\.encryption_key a la carpeta config\ del proyecto.
  AMBOS archivos deben restaurarse JUNTOS: la clave descifra las contraseñas
  de conexion guardadas en la base de datos.
"@
Set-Content -Path "$backupDir\LEEME-restaurar.txt" -Value $readme -Encoding UTF8

# ── ZIP opcional ─────────────────────────────────────────────────────────────
if ($Zip) {
    Write-Title " Comprimiendo "
    $zipPath = "$backupDir.zip"
    if (Test-Path $zipPath) { Remove-Item $zipPath -Force }
    Compress-Archive -Path "$backupDir\*" -DestinationPath $zipPath -CompressionLevel Optimal
    Remove-Item -Recurse -Force $backupDir
    Write-Step "Copia comprimida: $(Split-Path $zipPath -Leaf)"
    $finalPath = $zipPath
} else {
    $finalPath = $backupDir
}

$sizeKB = [math]::Round((Get-Item $finalPath -ErrorAction SilentlyContinue).Length / 1KB, 1)
Write-Info "Copia creada en: $finalPath"

# ── Rotacion: conservar solo las ultimas N copias ────────────────────────────
if ($Keep -gt 0) {
    Write-Title " Rotacion de copias "
    $pattern = if ($Zip) { "*.zip" } else { "*" }
    $items = Get-ChildItem $Dest -Filter $pattern | Where-Object {
        $_.Name -match '^\d{4}-\d{2}-\d{2}_\d{6}'
    } | Sort-Object Name -Descending

    if ($items.Count -gt $Keep) {
        $toRemove = $items | Select-Object -Skip $Keep
        foreach ($old in $toRemove) {
            Remove-Item $old.FullName -Recurse -Force
            Write-Warn "Eliminada copia antigua: $($old.Name)"
        }
    }
    Write-Step "Conservando las ultimas $Keep copias ($($items.Count) actuales)"
}

# ── Resumen ──────────────────────────────────────────────────────────────────
Write-Host ""
Write-Host "  ======================================================" -ForegroundColor Green
Write-Host "   Backup completado" -ForegroundColor White
Write-Host "  ======================================================" -ForegroundColor Green
Write-Host ""
Write-Info "Recuerde: guarde estas copias en OTRO disco o en la nube."
Write-Info "Un backup en el mismo disco no protege ante fallo de disco."
Write-Host ""
Write-Host "  (c) $(Get-Date -Format 'yyyy') DesarrollaLoYa by Diego Hernandez" -ForegroundColor DarkGray
Write-Host ""
