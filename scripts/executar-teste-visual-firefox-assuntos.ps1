[CmdletBinding()]
param(
    [string]$Url = 'http://localhost/greenportal',
    [Parameter(Mandatory)][string]$Empresa,
    [Parameter(Mandatory)][string]$Usuario,
    [Parameter(Mandatory)][string]$Senha,
    [switch]$Visual,
    [string]$Saida = 'tests/evidencias_visual_firefox_assuntos'
)
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $repo
try {
    node --check scripts/teste-visual-firefox-assuntos.js
    if ($LASTEXITCODE -ne 0) { throw 'Sintaxe JavaScript invalida.' }
    $argsNode = @('scripts/teste-visual-firefox-assuntos.js','--url',$Url,'--empresa',$Empresa,'--usuario',$Usuario,'--senha',$Senha,'--saida',$Saida)
    if ($Visual) { $argsNode += '--visual' }
    & node @argsNode
    if ($LASTEXITCODE -ne 0) { throw 'Teste visual de Assuntos terminou com erro.' }
} finally { Pop-Location }
