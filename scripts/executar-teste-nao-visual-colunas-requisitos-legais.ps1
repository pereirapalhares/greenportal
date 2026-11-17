[CmdletBinding()]
param(
    [string]$Url = 'http://localhost/greenportal',
    [Parameter(Mandatory)][string]$Empresa,
    [Parameter(Mandatory)][string]$Usuario,
    [Parameter(Mandatory)][string]$Senha,
    [string]$ArquivoResultado = 'tests/resultado-colunas-requisitos-legais.json'
)
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $repo
try {
    node --check scripts/teste-nao-visual-colunas-requisitos-legais.js
    if ($LASTEXITCODE -ne 0) { throw 'Sintaxe JavaScript inválida.' }
    & node scripts/teste-nao-visual-colunas-requisitos-legais.js --url $Url --empresa $Empresa --usuario $Usuario --senha $Senha --saida $ArquivoResultado
    if ($LASTEXITCODE -ne 0) { throw "Teste não visual de colunas terminou com código $LASTEXITCODE." }
} finally { Pop-Location }
