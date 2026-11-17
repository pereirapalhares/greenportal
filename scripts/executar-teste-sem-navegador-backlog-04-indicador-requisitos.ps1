param(
    [string]$Saida = 'tests/resultado-sem-navegador-backlog-04-indicador-requisitos.json'
)

$ErrorActionPreference = 'Stop'
node scripts/teste-sem-navegador-backlog-04-indicador-requisitos.js --saida $Saida
if ($LASTEXITCODE -ne 0) {
    Write-Host "Item 4 pendente conforme teste. Resultado: $Saida"
    exit $LASTEXITCODE
}
Write-Host "Teste do backlog item 4 aprovado. Resultado: $Saida"
