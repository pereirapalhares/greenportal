param(
    [string]$Saida = 'tests/resultado-sem-navegador-backlog-21-integra-laia.json'
)

$ErrorActionPreference = 'Stop'
node scripts/teste-sem-navegador-backlog-21-integra-laia.js --saida $Saida
if ($LASTEXITCODE -ne 0) {
    Write-Host "Item 21 pendente conforme teste. Resultado: $Saida"
    exit $LASTEXITCODE
}
Write-Host "Teste do backlog item 21 aprovado. Resultado: $Saida"
