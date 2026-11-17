[CmdletBinding()]
param([string]$Saida = 'tests/resultado-sem-navegador-backlog-02-links-home.json')
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $repo
try {
    node --check scripts/teste-sem-navegador-backlog-02-links-home.js
    if ($LASTEXITCODE -ne 0) { throw 'Sintaxe JavaScript inválida.' }
    & node scripts/teste-sem-navegador-backlog-02-links-home.js --saida $Saida
    $nodeExitCode = $LASTEXITCODE
    if (-not (Test-Path -LiteralPath $Saida)) { throw "Arquivo de resultado não foi gerado: $Saida" }
    if ($nodeExitCode -ne 0) { throw "Teste do backlog item 2 terminou com código $nodeExitCode. Consulte $Saida." }
} finally { Pop-Location }
