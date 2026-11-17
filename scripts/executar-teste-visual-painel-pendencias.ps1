[CmdletBinding()]
param(
    [string]$Url = 'http://localhost/greenportal',
    [Parameter(Mandatory)][string]$Empresa,
    [Parameter(Mandatory)][string]$Usuario,
    [Parameter(Mandatory)][string]$Senha,
    [switch]$Visivel,
    [string]$Saida = 'tests/evidencias_painel_pendencias'
)

$ErrorActionPreference = 'Stop'
Push-Location (Join-Path $PSScriptRoot '..')
try {
    $nodeArgs = @('scripts/teste-visual-painel-pendencias.js', '--url', $Url, '--empresa', $Empresa, '--usuario', $Usuario, '--senha', $Senha, '--saida', $Saida)
    if ($Visivel) { $nodeArgs += '--visivel' }
    & node @nodeArgs
    if ($LASTEXITCODE -ne 0) { throw "O teste visual terminou com código $LASTEXITCODE." }
}
finally {
    Pop-Location
}
