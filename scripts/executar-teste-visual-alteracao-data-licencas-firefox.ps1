[CmdletBinding()]
param(
    [string]$Url = 'http://localhost/greenportal',
    [Parameter(Mandatory)][string]$Empresa,
    [Parameter(Mandatory)][string]$Usuario,
    [Parameter(Mandatory)][string]$Senha,
    [switch]$Visivel
)
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $repo
try {
    node --check scripts\teste-visual-alteracao-data-licencas-firefox.js
    if ($LASTEXITCODE -ne 0) { throw 'Sintaxe JavaScript invalida.' }
    $argsNode = @('scripts/teste-visual-alteracao-data-licencas-firefox.js','--url',$Url,'--empresa',$Empresa,'--usuario',$Usuario,'--senha',$Senha)
    if ($Visivel) { $argsNode += '--visivel' }
    & node @argsNode
    if ($LASTEXITCODE -ne 0) { throw "Teste visual terminou com codigo $LASTEXITCODE." }
} finally { Pop-Location }
