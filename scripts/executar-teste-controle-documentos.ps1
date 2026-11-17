[CmdletBinding()]
param(
    [string]$Url = 'http://localhost/greenportal',
    [Parameter(Mandatory)][string]$Empresa,
    [Parameter(Mandatory)][string]$Usuario,
    [Parameter(Mandatory)][string]$Senha,
    [switch]$Visual,
    [switch]$Mutar,
    [switch]$Headless,
    [string]$Saida = ''
)
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $repo
try {
    if (-not (Get-Command node -ErrorAction SilentlyContinue)) { throw 'Node.js não está disponível no PATH.' }
    $playwright = Join-Path $repo 'node_modules/playwright/cli.js'
    if (-not (Test-Path -LiteralPath $playwright)) { throw 'Playwright não encontrado. Execute scripts/instalar-playwright.ps1.' }
    $js = Join-Path $repo 'scripts/teste-controle-documentos.js'
    & node --check $js
    if ($LASTEXITCODE -ne 0) { throw 'Sintaxe JavaScript inválida.' }

    $argsNode = @($js, '--url', $Url, '--empresa', $Empresa, '--usuario', $Usuario, '--senha', $Senha)
    if ($Visual) { $argsNode += '--visual' }
    if ($Mutar) { $argsNode += '--mutar' }
    if ($Headless) { $argsNode += '--headless' }
    if ($Saida) { $argsNode += @('--saida', $Saida) }
    & node @argsNode
    if ($LASTEXITCODE -ne 0) { throw "Teste de Controle terminou com código $LASTEXITCODE." }
} finally { Pop-Location }
