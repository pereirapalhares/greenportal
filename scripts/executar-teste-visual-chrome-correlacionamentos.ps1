param(
    [string]$Url = 'http://localhost/greenportal',
    [string]$Empresa = 'Modelo',
    [Parameter(Mandatory = $true)][string]$Usuario,
    [Parameter(Mandatory = $true)][string]$Senha,
    [switch]$Visual,
    [string]$Saida = 'tests/resultado-visual-chrome-correlacionamentos.json',
    [string]$Evidencias = 'tests/evidencias_visual_chrome_correlacionamentos'
)

$ErrorActionPreference = 'Stop'
$nodeArgs = @('scripts/teste-visual-chrome-correlacionamentos.js', '--url', $Url, '--empresa', $Empresa, '--usuario', $Usuario, '--senha', $Senha, '--saida', $Saida, '--evidencias', $Evidencias)
if ($Visual) { $nodeArgs += '--visual' }
& node @nodeArgs
if ($LASTEXITCODE -ne 0) { Write-Host "Teste visual Chrome reprovado. Resultado: $Saida"; exit $LASTEXITCODE }
Write-Host "Teste visual Chrome aprovado. Resultado: $Saida"
