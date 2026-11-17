[CmdletBinding()]
param(
    [string]$Url = 'http://localhost/greenportal',
    [Parameter(Mandatory)][string]$Empresa,
    [Parameter(Mandatory)][string]$Usuario,
    [Parameter(Mandatory)][string]$Senha,
    [switch]$Visual,
    [string]$ArquivoResultado = 'tests/resultado-crud-situacao-requisitos-legais.json',
    [string]$Evidencias = 'tests/evidencias_crud_situacao_requisitos_legais'
)
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $repo
try {
    node --check scripts/teste-crud-situacao-requisitos-legais.js
    if ($LASTEXITCODE -ne 0) { throw 'Sintaxe JavaScript inválida.' }
    $nodeArgs = @('scripts/teste-crud-situacao-requisitos-legais.js', '--url', $Url, '--empresa', $Empresa, '--usuario', $Usuario, '--senha', $Senha, '--saida', $ArquivoResultado, '--evidencias', $Evidencias)
    if ($Visual) { $nodeArgs += '--visual' }
    & node @nodeArgs
    if ($LASTEXITCODE -ne 0) { throw "Teste CRUD de Situação terminou com código $LASTEXITCODE." }
} finally { Pop-Location }
