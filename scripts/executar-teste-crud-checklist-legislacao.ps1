[CmdletBinding()]
param([string]$Url='http://localhost/greenportal',[Parameter(Mandatory)][string]$Empresa,[Parameter(Mandatory)][string]$Usuario,[Parameter(Mandatory)][string]$Senha,[string]$Saida='tests/resultado-crud-checklist-legislacao.json')
$ErrorActionPreference='Stop'; $repo=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path; Push-Location $repo
try { node --check scripts/teste-crud-checklist-legislacao.js; if($LASTEXITCODE -ne 0){throw 'Sintaxe JavaScript inválida.'}; & node scripts/teste-crud-checklist-legislacao.js --url $Url --empresa $Empresa --usuario $Usuario --senha $Senha --saida $Saida; if($LASTEXITCODE -ne 0){throw "Teste CRUD terminou com código $LASTEXITCODE."} } finally { Pop-Location }
