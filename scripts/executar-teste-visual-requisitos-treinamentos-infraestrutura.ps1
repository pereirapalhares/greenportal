[CmdletBinding()]
param(
    [string]$Url = 'http://localhost/greenportal',
    [Parameter(Mandatory)][string]$Empresa,
    [Parameter(Mandatory)][string]$Usuario,
    [Parameter(Mandatory)][string]$Senha,
    [switch]$Visual,
    [string]$Saida = 'tests/resultado-requisitos-treinamentos-infraestrutura.json',
    [string]$Evidencias = 'tests/evidencias_requisitos_treinamentos_infraestrutura'
)
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $repo
try {
    node --check scripts/teste-visual-requisitos-treinamentos-infraestrutura.js
    if ($LASTEXITCODE -ne 0) { throw 'Sintaxe JavaScript inválida.' }
    $nodeArgs = @('scripts/teste-visual-requisitos-treinamentos-infraestrutura.js','--url',$Url,'--empresa',$Empresa,'--usuario',$Usuario,'--senha',$Senha,'--saida',$Saida,'--evidencias',$Evidencias)
    if ($Visual) { $nodeArgs += '--visual' }
    & node @nodeArgs
    if ($LASTEXITCODE -ne 0) { throw "Teste de relacionamentos terminou com código $LASTEXITCODE." }
} finally { Pop-Location }
