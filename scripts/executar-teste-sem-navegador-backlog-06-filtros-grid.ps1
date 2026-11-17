param(
    [string]$Saida = 'tests/resultado-sem-navegador-backlog-06-filtros-grid.json'
)

$ErrorActionPreference = 'Stop'
node scripts/teste-sem-navegador-backlog-06-filtros-grid.js --saida $Saida
if ($LASTEXITCODE -ne 0) {
    throw "Teste do backlog item 6 terminou com codigo $LASTEXITCODE. Consulte $Saida."
}
Write-Host "Teste do backlog item 6 aprovado. Resultado: $Saida"
