param(
    [string]$Saida = 'tests/resultado-sem-navegador-backlog-30-nome-exportacoes.json'
)

$ErrorActionPreference = 'Stop'
node scripts/teste-sem-navegador-backlog-30-nome-exportacoes.js --saida $Saida
if ($LASTEXITCODE -ne 0) {
    throw "Teste do backlog item 30 terminou com codigo $LASTEXITCODE. Consulte $Saida."
}
Write-Host "Teste do backlog item 30 concluido. Resultado: $Saida"
