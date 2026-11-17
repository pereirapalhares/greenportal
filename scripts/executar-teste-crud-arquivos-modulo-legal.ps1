[CmdletBinding()]
param(
    [string]$Url = 'http://localhost/greenportal',
    [Parameter(Mandatory)][string]$Empresa,
    [Parameter(Mandatory)][string]$Usuario,
    [Parameter(Mandatory)][string]$Senha,
    [switch]$Visual,
    [switch]$Mutar,
    [string]$Saida = ''
)
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $repo
try {
    if (-not (Get-Command node -ErrorAction SilentlyContinue)) { throw 'Node.js não está disponível no PATH.' }
    $playwright = Join-Path $repo 'node_modules/playwright/cli.js'
    if (-not (Test-Path -LiteralPath $playwright)) { throw 'Playwright não encontrado. Execute scripts/instalar-playwright.ps1.' }
    $js = Join-Path $repo 'scripts/teste-crud-arquivos-modulo-legal.js'
    & node --check $js
    if ($LASTEXITCODE -ne 0) { throw 'Sintaxe JavaScript inválida.' }
    $nodeArgs = @($js, '--url', $Url, '--empresa', $Empresa, '--usuario', $Usuario, '--senha', $Senha)
    if ($Visual) { $nodeArgs += '--visual' }
    if ($Mutar) { $nodeArgs += '--mutar' }
    if ($Saida) { $nodeArgs += @('--saida', $Saida) }
    & node @nodeArgs
    if ($LASTEXITCODE -ne 0) { throw "Teste terminou com código $LASTEXITCODE." }
} finally { Pop-Location }
