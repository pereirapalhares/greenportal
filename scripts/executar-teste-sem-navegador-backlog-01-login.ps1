[CmdletBinding()]
param(
    [string]$Url = 'http://localhost/greenportal',
    [string]$Saida = 'tests/resultado-sem-navegador-backlog-01-login.json'
)
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $repo
try {
    node --check scripts/teste-sem-navegador-backlog-01-login.js
    if ($LASTEXITCODE -ne 0) { throw 'Sintaxe JavaScript inválida.' }
    & node scripts/teste-sem-navegador-backlog-01-login.js --url $Url --saida $Saida
    $nodeExitCode = $LASTEXITCODE
    if (-not (Test-Path -LiteralPath $Saida)) { throw "Arquivo de resultado não foi gerado: $Saida" }
    if ($nodeExitCode -ne 0) { throw "Teste HTTP do backlog item 1 terminou com código $nodeExitCode. Consulte $Saida." }
} finally { Pop-Location }
