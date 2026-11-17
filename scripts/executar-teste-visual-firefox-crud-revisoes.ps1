param(
    [string]$Url = 'http://localhost/greenportal',
    [string]$Empresa = 'Modelo',
    [Parameter(Mandatory = $true)][string]$Usuario,
    [Parameter(Mandatory = $true)][string]$Senha,
    [switch]$Visual,
    [string]$Saida = 'tests/resultado-visual-firefox-crud-revisoes.json',
    [string]$Evidencias = 'tests/evidencias_visual_firefox_crud_revisoes'
)
$ErrorActionPreference = 'Stop'
node --check scripts\teste-visual-firefox-crud-revisoes.js
$nodeArgs = @('scripts/teste-visual-firefox-crud-revisoes.js','--url',$Url,'--empresa',$Empresa,'--usuario',$Usuario,'--senha',$Senha,'--saida',$Saida,'--evidencias',$Evidencias)
if ($Visual) { $nodeArgs += '--visual' }
& node @nodeArgs
if ($LASTEXITCODE -ne 0) { Write-Host "Teste visual Firefox de CRUD das revisões reprovado. Resultado: $Saida"; exit $LASTEXITCODE }
Write-Host "Teste visual Firefox de CRUD das revisões aprovado. Resultado: $Saida"
