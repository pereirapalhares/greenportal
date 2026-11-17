param(
    [string]$Saida = 'tests/resultado-sem-navegador-backlog-15-lista-distribuicao.json'
)

$ErrorActionPreference = 'Stop'
node scripts/teste-sem-navegador-backlog-15-lista-distribuicao.js --saida $Saida
if ($LASTEXITCODE -ne 0) {
    Write-Host "Item 15 pendente conforme teste. Resultado: $Saida"
    exit $LASTEXITCODE
}
Write-Host "Teste do backlog item 15 aprovado. Resultado: $Saida"
