param(
    [string]$Saida = 'tests/resultado-sem-navegador-backlog-17-proximas-tarefas.json'
)

$ErrorActionPreference = 'Stop'
node scripts/teste-sem-navegador-backlog-17-proximas-tarefas.js --saida $Saida
if ($LASTEXITCODE -ne 0) {
    Write-Host "Item 17 pendente conforme teste. Resultado: $Saida"
    exit $LASTEXITCODE
}
Write-Host "Teste do backlog item 17 aprovado. Resultado: $Saida"
