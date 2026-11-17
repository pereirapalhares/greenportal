param(
    [string]$Saida = 'tests/resultado-sem-navegador-backlog-23-inventario-laia.json'
)

$ErrorActionPreference = 'Stop'
node scripts/teste-sem-navegador-backlog-23-inventario-laia.js --saida $Saida
if ($LASTEXITCODE -ne 0) {
    Write-Host "Item 23 pendente conforme teste. Resultado: $Saida"
    exit $LASTEXITCODE
}
Write-Host "Teste do backlog item 23 aprovado. Resultado: $Saida"
