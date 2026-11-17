param(
    [string]$Saida = 'tests/resultado-sem-navegador-correlacionamentos-legado.json'
)

$ErrorActionPreference = 'Stop'
$metricas = & php scripts/coletar-metricas-correlacionamentos-legado.php
if ($LASTEXITCODE -ne 0) { throw 'Falha ao coletar metricas dos bancos' }
$metricas | node scripts/teste-sem-navegador-correlacionamentos-legado.js --saida $Saida
if ($LASTEXITCODE -ne 0) {
    Write-Host "Teste de regressao dos correlacionamentos reprovado. Resultado: $Saida"
    exit $LASTEXITCODE
}
Write-Host "Teste de regressao dos correlacionamentos aprovado. Resultado: $Saida"
