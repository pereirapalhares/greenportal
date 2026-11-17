param(
    [string]$Saida = 'tests/resultado-sem-navegador-backlog-12-edicao-licencas.json'
)

$ErrorActionPreference = 'Stop'
node scripts/teste-sem-navegador-backlog-12-edicao-licencas.js --saida $Saida
if ($LASTEXITCODE -ne 0) {
    Write-Host "Item 12 pendente conforme teste. Resultado: $Saida"
    exit $LASTEXITCODE
}
Write-Host "Teste do backlog item 12 aprovado. Resultado: $Saida"
