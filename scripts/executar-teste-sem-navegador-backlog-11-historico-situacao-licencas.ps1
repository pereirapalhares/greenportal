param(
    [string]$Saida = 'tests/resultado-sem-navegador-backlog-11-historico-situacao-licencas.json'
)

$ErrorActionPreference = 'Stop'
node scripts/teste-sem-navegador-backlog-11-historico-situacao-licencas.js --saida $Saida
if ($LASTEXITCODE -ne 0) {
    Write-Host "Item 11 pendente conforme teste. Resultado: $Saida"
    exit $LASTEXITCODE
}
Write-Host "Teste do backlog item 11 aprovado. Resultado: $Saida"
