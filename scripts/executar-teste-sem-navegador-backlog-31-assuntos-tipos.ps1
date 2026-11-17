param(
    [string]$Saida = 'tests/resultado-sem-navegador-backlog-31-assuntos-tipos.json'
)

$ErrorActionPreference = 'Stop'
node scripts/teste-sem-navegador-backlog-31-assuntos-tipos.js --saida $Saida
if ($LASTEXITCODE -ne 0) {
    throw "Teste do backlog item 31 terminou com codigo $LASTEXITCODE. Consulte $Saida."
}
Write-Host "Teste do backlog item 31 concluido. Resultado: $Saida"
