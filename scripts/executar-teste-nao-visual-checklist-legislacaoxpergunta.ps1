[CmdletBinding()]
param(
    [string]$Url = 'http://localhost/greenportal',
    [Parameter(Mandatory)][string]$Empresa,
    [Parameter(Mandatory)][string]$Usuario,
    [Parameter(Mandatory)][string]$Senha,
    [string]$Codigo = 'LIC0115B',
    [string]$Codlegislacao = '3054',
    [int]$Esperado = 33,
    [string]$Saida = 'tests/resultado-nao-visual-checklist-legislacaoxpergunta.json'
)
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $repo
try {
    node --check scripts/teste-nao-visual-checklist-legislacaoxpergunta.js
    if ($LASTEXITCODE -ne 0) { throw 'Sintaxe JavaScript inválida.' }
    & node scripts/teste-nao-visual-checklist-legislacaoxpergunta.js --url $Url --empresa $Empresa --usuario $Usuario --senha $Senha --codigo $Codigo --codlegislacao $Codlegislacao --esperado $Esperado --saida $Saida
    if (-not (Test-Path -LiteralPath $Saida)) { throw "Arquivo de resultado não foi gerado: $Saida" }
    if ($LASTEXITCODE -ne 0) { throw "Teste não visual do Checklist terminou com código $LASTEXITCODE." }
} finally { Pop-Location }
