[CmdletBinding()]
param(
    [string]$Url = 'http://localhost/greenportal',
    [Parameter(Mandatory)][string]$Empresa,
    [Parameter(Mandatory)][string]$Usuario,
    [Parameter(Mandatory)][string]$Senha,
    [switch]$Visual,
    [string]$ArquivoResultado = 'tests/resultado-documentos-licenciatorios.json',
    [string]$Evidencias = 'tests/evidencias_documentos_licenciatorios'
)
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $repo
try {
    node --check scripts/teste-documentos-licenciatorios.js
    if ($LASTEXITCODE -ne 0) { throw 'Sintaxe JavaScript inválida.' }
    $nodeArgs = @('scripts/teste-documentos-licenciatorios.js', '--url', $Url, '--empresa', $Empresa, '--usuario', $Usuario, '--senha', $Senha, '--saida', $ArquivoResultado, '--evidencias', $Evidencias)
    if ($Visual) { $nodeArgs += '--visual' }
    & node @nodeArgs
    if ($LASTEXITCODE -ne 0) { throw "Teste de Documentos Licenciatórios terminou com código $LASTEXITCODE." }
} finally { Pop-Location }
