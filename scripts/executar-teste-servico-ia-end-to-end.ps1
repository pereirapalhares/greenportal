[CmdletBinding()]
param(
    [string]$Url = 'http://localhost/greenportal',
    [string]$Empresa = 'Modelo',
    [string]$Usuario = 'admin',
    [string]$Senha = $env:GREEN_TEST_PASSWORD,
    [string]$ServiceUrl = '',
    [int]$TimeoutSegundos = 120,
    [switch]$Processar,
    [string]$Saida = 'tests/evidencias_servico_ia'
)
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $repo
try {
    node --check scripts/teste-servico-ia-end-to-end.js
    if ($LASTEXITCODE -ne 0) { throw 'Sintaxe JavaScript inválida.' }
    $argsNode = @('scripts/teste-servico-ia-end-to-end.js')
    if ($Processar) { $argsNode += '--processar' }
    & node @argsNode
    if ($LASTEXITCODE -ne 0) { throw "Teste terminou com código $LASTEXITCODE." }
    Write-Host 'PASS - Validacao do worker IA concluida sem servico HTTP legado.'
} finally { Pop-Location }
