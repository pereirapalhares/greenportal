[CmdletBinding()]
param(
    [string]$Url = 'http://localhost/greenportal',
    [Parameter(Mandatory)][string]$Empresa,
    [Parameter(Mandatory)][string]$Usuario,
    [Parameter(Mandatory)][string]$Senha,
    [switch]$Visual,
    [string]$ArquivoResultado = 'tests/resultado-completo-requisitos-legais-associados.json',
    [string]$Evidencias = 'tests/evidencias_completo_requisitos_legais_associados'
)
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $repo
try {
    node --check scripts/teste-completo-requisitos-legais-associados.js
    node --check scripts/teste-crud-situacao-requisitos-legais.js
    if ($LASTEXITCODE -ne 0) { throw 'Sintaxe JavaScript inválida.' }
    $nodeArgs = @('scripts/teste-completo-requisitos-legais-associados.js', '--url', $Url, '--empresa', $Empresa, '--usuario', $Usuario, '--senha', $Senha, '--saida', $ArquivoResultado, '--evidencias', $Evidencias)
    if ($Visual) { $nodeArgs += '--visual' }
    & node @nodeArgs
    if ($LASTEXITCODE -ne 0) { throw "Teste completo de Requisitos Legais terminou com código $LASTEXITCODE." }
} finally { Pop-Location }
