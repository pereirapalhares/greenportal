[CmdletBinding()]
param(
    [string]$Url = 'http://localhost/greenportal',
    [Parameter(Mandatory)][string]$Empresa,
    [Parameter(Mandatory)][string]$Usuario,
    [Parameter(Mandatory)][string]$Senha,
    [switch]$Visual,
    [string]$Saida = 'tests/resultado-visual-firefox-backlog-27-contagem-requisitos.json'
)

$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $repo
try {
    node --check scripts\teste-visual-firefox-backlog-27-contagem-requisitos.js
    if ($LASTEXITCODE -ne 0) { throw 'Sintaxe JavaScript invalida.' }
    $nodeArgs = @('scripts\teste-visual-firefox-backlog-27-contagem-requisitos.js', '--url', $Url, '--empresa', $Empresa, '--usuario', $Usuario, '--senha', $Senha, '--saida', $Saida)
    if ($Visual) { $nodeArgs += '--visual' }
    & node @nodeArgs
    if ($LASTEXITCODE -ne 0) { throw "Teste visual Firefox terminou com codigo $LASTEXITCODE. Consulte $Saida." }
    Write-Host "Teste visual Firefox do item 27 concluido. Resultado: $Saida"
} finally { Pop-Location }
