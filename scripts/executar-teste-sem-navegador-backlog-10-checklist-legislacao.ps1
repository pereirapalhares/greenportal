param(
    [string]$Saida = 'tests/resultado-sem-navegador-backlog-10-checklist-legislacao.json'
)

$ErrorActionPreference = 'Stop'
node scripts/teste-sem-navegador-backlog-10-checklist-legislacao.js --saida $Saida
if ($LASTEXITCODE -ne 0) {
    throw "Teste do backlog item 10 terminou com codigo $LASTEXITCODE. Consulte $Saida."
}
Write-Host "Teste do backlog item 10 aprovado. Resultado: $Saida"
