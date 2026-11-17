param(
    [string]$Saida = 'tests/resultado-sem-navegador-backlog-26-colunas-requisitos.json'
)

$ErrorActionPreference = 'Stop'
node scripts/teste-sem-navegador-backlog-26-colunas-requisitos.js --saida $Saida
if ($LASTEXITCODE -ne 0) {
    throw "Teste do backlog item 26 terminou com codigo $LASTEXITCODE. Consulte $Saida."
}
Write-Host "Teste do backlog item 26 concluido. Resultado: $Saida"
