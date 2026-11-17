[CmdletBinding()]
param(
    [string]$Url = 'http://localhost/greenportal',
    [Parameter(Mandatory)][string]$Empresa,
    [Parameter(Mandatory)][string]$Usuario,
    [Parameter(Mandatory)][string]$Senha,
    [string]$Saida = 'tests/resultado-nao-visual-minhas-licencas.txt'
)
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $repo
try {
    node --check scripts/teste-nao-visual-minhas-licencas.js
    if ($LASTEXITCODE -ne 0) { throw 'Sintaxe JavaScript inválida.' }
    $output = @(& node scripts/teste-nao-visual-minhas-licencas.js --url $Url --empresa $Empresa --usuario $Usuario --senha $Senha 2>&1)
    $code = $LASTEXITCODE
    $output | Tee-Object -FilePath $Saida
    if ($code -ne 0) { throw "Teste não visual terminou com código $code." }
} finally { Pop-Location }
