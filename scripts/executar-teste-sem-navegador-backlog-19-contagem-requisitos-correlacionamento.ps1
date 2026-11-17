param(
    [string]$Saida = 'tests/resultado-sem-navegador-backlog-19-contagem-requisitos-correlacionamento.json'
)

$ErrorActionPreference = 'Stop'
node scripts/teste-sem-navegador-backlog-19-contagem-requisitos-correlacionamento.js --saida $Saida
if ($LASTEXITCODE -ne 0) {
    Write-Host "Item 19 pendente conforme teste. Resultado: $Saida"
    exit $LASTEXITCODE
}
Write-Host "Teste do backlog item 19 aprovado. Resultado: $Saida"
