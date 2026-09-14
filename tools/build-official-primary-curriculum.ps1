$ErrorActionPreference = 'Stop'

$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $repoRoot

$pyLauncher = Get-Command py -ErrorAction SilentlyContinue
$pythonCmd = $null
$pythonArgs = @()

if ($pyLauncher) {
    $pythonCmd = $pyLauncher.Source
    $pythonArgs = @('-3')
} else {
    $python = Get-Command python -ErrorAction SilentlyContinue
    if ($python) {
        $pythonCmd = $python.Source
    }
}

if (-not $pythonCmd) {
    Write-Error 'Python 3 no esta disponible. Instala Python 3 y vuelve a ejecutar este script.'
    exit 2
}

$venvDir = Join-Path $repoRoot '.runtime\curriculum-venv'
$venvPython = Join-Path $venvDir 'Scripts\python.exe'

if (-not (Test-Path $venvPython)) {
    Write-Host 'Creando entorno Python aislado para la extraccion curricular...'
    & $pythonCmd @pythonArgs -m venv $venvDir
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

# Do not try `import fitz` directly here. On Windows PowerShell 5.1 a failed
# native import writes a traceback to stderr, which becomes NativeCommandError
# because ErrorActionPreference is Stop before we can inspect LASTEXITCODE.
& $venvPython -c "import importlib.util,sys; sys.exit(0 if importlib.util.find_spec('fitz') else 1)"
if ($LASTEXITCODE -ne 0) {
    Write-Host 'Instalando PyMuPDF en el entorno aislado...'
    & $venvPython -m pip install --disable-pip-version-check 'PyMuPDF>=1.24,<2'
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

Write-Host 'Extrayendo contenidos y PDA desde los Programas Sinteticos oficiales de la SEP...'
& $venvPython (Join-Path $repoRoot 'tools\build_official_primary_curriculum.py')
$exitCode = $LASTEXITCODE

if ($exitCode -eq 0) {
    Write-Host ''
    Write-Host 'Archivo generado correctamente:'
    Write-Host (Join-Path $repoRoot 'curriculum_mx_nem_primary_v1.json')
    Write-Host ''
    Write-Host 'No lo importes todavia. Revisa primero .runtime\curriculum-official\extraction-report.json.'
} else {
    Write-Host ''
    Write-Host 'La extraccion se detuvo antes de crear el JSON final.'
    Write-Host 'Revisa .runtime\curriculum-official\extraction-report.json y comparte la salida de esta consola.'
}

exit $exitCode
