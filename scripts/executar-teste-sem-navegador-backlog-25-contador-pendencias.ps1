param(
    [string]$Saida = 'tests/resultado-sem-navegador-backlog-25-contador-pendencias.json'
)

$ErrorActionPreference = 'Stop'
node scripts/teste-sem-navegador-backlog-25-contador-pendencias.js --saida $Saida
if ($LASTEXITCODE -ne 0) {
    Write-Host "Item 25 pendente conforme teste. Resultado: $Saida"
    exit $LASTEXITCODE
}
Write-Host "Teste do backlog item 25 aprovado. Resultado: $Saida"
