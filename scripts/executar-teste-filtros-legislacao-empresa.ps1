[CmdletBinding()] param(
    [string]$Url='http://localhost/greenportal',
    [Parameter(Mandatory)][string]$Empresa,
    [Parameter(Mandatory)][string]$Usuario,
    [Parameter(Mandatory)][string]$Senha,
    [string]$CodigoEmpresa='4',
    [string]$Saida='tests/resultado-filtros-legislacao-empresa.json'
)
$ErrorActionPreference='Stop'; $repo=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path; Push-Location $repo
try {
    node --check scripts/teste-filtros-legislacao-empresa.js
    if($LASTEXITCODE -ne 0){ throw 'Sintaxe JavaScript inválida.' }
    & node scripts/teste-filtros-legislacao-empresa.js --url $Url --empresa $Empresa --usuario $Usuario --senha $Senha --codigo-empresa $CodigoEmpresa --saida $Saida
    if($LASTEXITCODE -ne 0){ throw "Teste de filtros terminou com código $LASTEXITCODE." }
} finally { Pop-Location }
