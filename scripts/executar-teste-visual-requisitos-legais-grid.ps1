[CmdletBinding()]
param(
    [string]$Url = 'http://localhost/greenportal',
    [Parameter(Mandatory)][string]$Empresa,
    [Parameter(Mandatory)][string]$Usuario,
    [Parameter(Mandatory)][string]$Senha,
    [switch]$Headless,
    [string]$Saida = 'tests/evidencias_requisitos_legais_grid'
)
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $repo
try {
    node --check scripts/teste-visual-requisitos-legais-grid.js
    if ($LASTEXITCODE -ne 0) { throw 'Sintaxe JavaScript inválida.' }
    $argsNode = @('scripts/teste-visual-requisitos-legais-grid.js','--url',$Url,'--empresa',$Empresa,'--usuario',$Usuario,'--senha',$Senha,'--saida',$Saida)
    if ($Headless) { $argsNode += '--headless' }
    & node @argsNode
    if ($LASTEXITCODE -ne 0) { throw "Teste visual terminou com código $LASTEXITCODE." }
} finally { Pop-Location }
