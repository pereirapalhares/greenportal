param(
    [string]$Saida = 'tests/resultado-sem-navegador-backlog-24-cadastros-especificos-empresa.json'
)

$ErrorActionPreference = 'Stop'
node scripts/teste-sem-navegador-backlog-24-cadastros-especificos-empresa.js --saida $Saida
if ($LASTEXITCODE -ne 0) {
    Write-Host "Item 24 pendente conforme teste. Resultado: $Saida"
    exit $LASTEXITCODE
}
Write-Host "Teste do backlog item 24 aprovado. Resultado: $Saida"
