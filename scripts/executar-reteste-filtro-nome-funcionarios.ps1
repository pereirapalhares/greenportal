[CmdletBinding()]
param(
    [string]$Url = 'http://localhost/greenportal',
    [Parameter(Mandatory)][string]$Empresa,
    [Parameter(Mandatory)][string]$Usuario,
    [Parameter(Mandatory)][string]$Senha,
    [switch]$Visivel,
    [string]$Saida = 'tests/evidencias_reteste_filtro_nome_funcionarios'
)

$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $repo
try {
    if (-not (Get-Command node -ErrorAction SilentlyContinue)) { throw 'Node.js não está disponível no PATH.' }
    $playwright = Join-Path $repo 'node_modules/playwright/cli.js'
    if (-not (Test-Path -LiteralPath $playwright)) { throw "Playwright não encontrado em $playwright. Execute scripts/instalar-playwright.ps1." }

    $js = Join-Path $repo 'scripts/teste-visual-filtro-nome-funcionarios.js'
    & node --check $js
    if ($LASTEXITCODE -ne 0) { throw 'O JavaScript do reteste possui erro de sintaxe.' }

    $nodeArgs = @($js, '--url', $Url, '--empresa', $Empresa, '--usuario', $Usuario, '--senha', $Senha, '--saida', $Saida)
    if ($Visivel) { $nodeArgs += '--visivel' }
    & node @nodeArgs
    if ($LASTEXITCODE -ne 0) { throw "O reteste visual terminou com código $LASTEXITCODE." }

    $resultado = Join-Path $repo (Join-Path $Saida 'resultado.json')
    if (-not (Test-Path -LiteralPath $resultado)) { throw "Resultado não encontrado: $resultado" }
    $json = Get-Content -LiteralPath $resultado -Raw | ConvertFrom-Json
    if ($json.status -ne 'APROVADO') { throw "Status do reteste: $($json.status)" }
    Write-Host "Reteste aprovado. Resultado: $resultado" -ForegroundColor Green
    exit 0
}
finally {
    Pop-Location
}
