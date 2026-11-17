[CmdletBinding()]
param(
    [string]$Pasta = 'exports',
    [string]$Saida = 'tests/resultado-nao-visual-exportacao-real-licencas.json'
)
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $repo
try {
    node --check scripts/teste-nao-visual-exportacao-real-licencas.js
    if ($LASTEXITCODE -ne 0) { throw 'Sintaxe JavaScript inválida.' }
    & node scripts/teste-nao-visual-exportacao-real-licencas.js --pasta $Pasta --saida $Saida
    if ($LASTEXITCODE -ne 0) { throw 'Teste não visual da planilha real terminou com erro.' }
} finally { Pop-Location }
