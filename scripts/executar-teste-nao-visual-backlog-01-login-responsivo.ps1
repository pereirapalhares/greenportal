[CmdletBinding()]
param(
    [string]$Url = 'http://localhost/greenportal',
    [string]$Saida = 'tests/resultado-backlog-01-login-responsivo.json'
)
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $repo
try {
    node --check scripts/teste-nao-visual-backlog-01-login-responsivo.js
    if ($LASTEXITCODE -ne 0) { throw 'Sintaxe JavaScript inválida.' }
    & node scripts/teste-nao-visual-backlog-01-login-responsivo.js --url $Url --saida $Saida
    $nodeExitCode = $LASTEXITCODE
    if (-not (Test-Path -LiteralPath $Saida)) { throw "Arquivo de resultado não foi gerado: $Saida" }
    if ($nodeExitCode -ne 0) { throw "Teste do backlog item 1 terminou com código $nodeExitCode. Consulte $Saida." }
} finally { Pop-Location }
