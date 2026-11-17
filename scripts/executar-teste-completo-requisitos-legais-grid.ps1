[CmdletBinding()]
param(
    [string]$Url = 'http://localhost/greenportal',
    [Parameter(Mandatory)][string]$Empresa,
    [Parameter(Mandatory)][string]$Usuario,
    [Parameter(Mandatory)][string]$Senha,
    [switch]$Visual,
    [string]$ArquivoResultado = 'tests/resultado-completo-requisitos-legais-grid.json',
    [string]$Evidencias = 'tests/evidencias_requisitos_legais_grid'
)
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $repo
try {
    node --check scripts/teste-nao-visual-requisitos-legais-grid.js
    if ($LASTEXITCODE -ne 0) { throw 'Sintaxe JavaScript inválida.' }
    $argsNode = @(
        'scripts/teste-nao-visual-requisitos-legais-grid.js',
        '--url', $Url, '--empresa', $Empresa, '--usuario', $Usuario, '--senha', $Senha,
        '--saida', $ArquivoResultado, '--evidencias', $Evidencias
    )
    if ($Visual) { $argsNode += '--visual' }
    & node @argsNode
    if ($LASTEXITCODE -ne 0) { throw "Teste completo terminou com código $LASTEXITCODE." }
} finally { Pop-Location }
