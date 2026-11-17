param(
    [string]$Saida = 'tests/resultado-sem-navegador-backlog-20-revisoes-lpro-lais.json'
)

$ErrorActionPreference = 'Stop'
node scripts/teste-sem-navegador-backlog-20-revisoes-lpro-lais.js --saida $Saida
if ($LASTEXITCODE -ne 0) {
    Write-Host "Item 20 pendente conforme teste. Resultado: $Saida"
    exit $LASTEXITCODE
}
Write-Host "Teste do backlog item 20 aprovado. Resultado: $Saida"
