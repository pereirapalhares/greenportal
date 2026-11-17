[CmdletBinding()]
param(
    [string]$Url = 'http://localhost/greenportal',
    [string]$Empresa = 'Modelo',
    [string]$Usuario = 'admin',
    [string]$Senha = $env:GREEN_TEST_PASSWORD,
    [switch]$Visual,
    [switch]$Mutar,
    [string]$Saida = 'tests/evidencias_fluxo_ia_requisitos'
)
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $repo
try {
    $js = 'scripts/teste-fluxo-ia-requisitos-legais.js'
    node --check $js
    if ($LASTEXITCODE -ne 0) { throw 'Sintaxe JavaScript inválida.' }
    $nodeArgs = @($js, '--url', $Url, '--empresa', $Empresa, '--usuario', $Usuario, '--senha', $Senha, '--saida', $Saida)
    if ($Visual) { $nodeArgs += '--visual' }
    if ($Mutar) { $nodeArgs += '--mutar' }
    & node @nodeArgs
    if ($LASTEXITCODE -ne 0) { throw "Teste terminou com código $LASTEXITCODE." }
} finally { Pop-Location }
