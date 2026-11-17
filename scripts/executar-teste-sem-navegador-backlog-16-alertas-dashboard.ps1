param(
    [string]$Saida = 'tests/resultado-sem-navegador-backlog-16-alertas-dashboard.json'
)

$ErrorActionPreference = 'Stop'
node scripts/teste-sem-navegador-backlog-16-alertas-dashboard.js --saida $Saida
if ($LASTEXITCODE -ne 0) {
    Write-Host "Item 16 pendente conforme teste. Resultado: $Saida"
    exit $LASTEXITCODE
}
Write-Host "Teste do backlog item 16 aprovado. Resultado: $Saida"
