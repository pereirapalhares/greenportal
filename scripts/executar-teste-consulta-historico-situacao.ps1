[CmdletBinding()] param(
    [string]$Url='http://localhost/greenportal',
    [Parameter(Mandatory)][string]$Empresa,
    [Parameter(Mandatory)][string]$Usuario,
    [Parameter(Mandatory)][string]$Senha,
    [string]$Situacao='39',
    [string]$Saida='tests/resultado-consulta-historico-situacao.json'
)
$ErrorActionPreference='Stop'; $repo=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path; Push-Location $repo
try {
    node --check scripts/teste-consulta-historico-situacao.js
    if($LASTEXITCODE -ne 0){ throw 'Sintaxe JavaScript inválida.' }
    & node scripts/teste-consulta-historico-situacao.js --url $Url --empresa $Empresa --usuario $Usuario --senha $Senha --situacao $Situacao --saida $Saida
    if($LASTEXITCODE -ne 0){ throw "Teste de consulta terminou com código $LASTEXITCODE." }
} finally { Pop-Location }
