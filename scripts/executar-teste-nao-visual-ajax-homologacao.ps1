[CmdletBinding()]
param(
    [string]$Url = 'http://localhost/greenportal',
    [Parameter(Mandatory)][string]$Empresa,
    [Parameter(Mandatory)][string]$Usuario,
    [Parameter(Mandatory)][string]$Senha,
    [string]$Saida = 'tests/resultado-nao-visual-ajax-homologacao.json'
)
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $repo
try {
    node --check scripts/teste-nao-visual-ajax-homologacao.js
    if ($LASTEXITCODE -ne 0) { throw 'Sintaxe JavaScript inválida.' }
    $nodeArgs = @('scripts/teste-nao-visual-ajax-homologacao.js','--url',$Url,'--empresa',$Empresa,'--usuario',$Usuario,'--senha',$Senha,'--saida',$Saida)
    & node @nodeArgs
    if ($LASTEXITCODE -ne 0) { throw "Teste AJAX terminou com código $LASTEXITCODE." }
} finally { Pop-Location }
