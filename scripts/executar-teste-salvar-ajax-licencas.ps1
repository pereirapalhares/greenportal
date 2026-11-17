[CmdletBinding()] param(
    [string]$Url='http://localhost/greenportal',
    [Parameter(Mandatory)][string]$Empresa,
    [Parameter(Mandatory)][string]$Usuario,
    [Parameter(Mandatory)][string]$Senha,
    [string]$Saida='tests/resultado-salvar-ajax-licencas.json'
)
$ErrorActionPreference='Stop'; $repo=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path; Push-Location $repo
try {
    node --check scripts/teste-salvar-ajax-licencas.js
    if($LASTEXITCODE -ne 0){ throw 'Sintaxe JavaScript inválida.' }
    & node scripts/teste-salvar-ajax-licencas.js --url $Url --empresa $Empresa --usuario $Usuario --senha $Senha --saida $Saida
    if($LASTEXITCODE -ne 0){ throw "Teste de salvamento AJAX terminou com código $LASTEXITCODE." }
} finally { Pop-Location }
