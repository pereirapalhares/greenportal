[CmdletBinding()]
param(
    [string]$Url = 'http://localhost/greenportal',
    [Parameter(Mandatory)][string]$Empresa,
    [Parameter(Mandatory)][string]$Usuario,
    [Parameter(Mandatory)][string]$Senha,
    [string]$Saida = 'tests/resultado-nova-legislacao-layout.json'
)
$ErrorActionPreference = 'Stop'; $repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path; Push-Location $repo
try {
    node --check scripts/teste-nova-legislacao-layout.js
    if ($LASTEXITCODE -ne 0) { throw 'Sintaxe JavaScript inválida.' }
    & node scripts/teste-nova-legislacao-layout.js --url $Url --empresa $Empresa --usuario $Usuario --senha $Senha --saida $Saida
    if ($LASTEXITCODE -ne 0) { throw 'Teste de layout da nova legislação terminou com erro.' }
} finally { Pop-Location }
