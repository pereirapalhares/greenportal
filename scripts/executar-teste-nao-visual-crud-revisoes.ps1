param(
    [string]$Saida = 'tests/resultado-nao-visual-crud-revisoes.json'
)

$ErrorActionPreference = 'Stop'
node scripts/teste-nao-visual-crud-revisoes.js --saida ($Saida -replace '\.json$', '-estrutura.json')
if ($LASTEXITCODE -ne 0) {
    throw "Validação estrutural das revisões terminou com código $LASTEXITCODE."
}
php scripts/teste-nao-visual-crud-revisoes.php | Tee-Object -FilePath $Saida
if ($LASTEXITCODE -ne 0) {
    throw "CRUD real de Revisão LAIA/LPRO terminou com código $LASTEXITCODE. Consulte $Saida."
}
Write-Host "Teste não visual de CRUD das revisões aprovado. Resultado: $Saida"
