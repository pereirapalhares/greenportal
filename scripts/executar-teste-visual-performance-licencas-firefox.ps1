[CmdletBinding()]
param(
    [string]$Url = 'http://localhost/greenportal',
    [Parameter(Mandatory)][string]$Empresa,
    [Parameter(Mandatory)][string]$Usuario,
    [Parameter(Mandatory)][string]$Senha,
    [switch]$Visivel,
    [switch]$Mutar
)
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $repo
try {
    node --check scripts\teste-visual-performance-licencas-firefox.js
    if ($LASTEXITCODE -ne 0) { throw 'Sintaxe JavaScript inválida.' }
    $nodeArgs = @('scripts/teste-visual-performance-licencas-firefox.js','--url',$Url,'--empresa',$Empresa,'--usuario',$Usuario,'--senha',$Senha)
    if ($Visivel) { $nodeArgs += '--visivel' }
    if ($Mutar) { $nodeArgs += '--mutar' }
    $previousErrorAction = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    $nodeOutput = @(& node @nodeArgs 2>&1)
    $nodeExitCode = $LASTEXITCODE
    $ErrorActionPreference = $previousErrorAction
    $nodeOutput | Write-Output
    if ($nodeExitCode -ne 0) { throw "Teste visual Firefox terminou com código $nodeExitCode." }
} finally { Pop-Location }
