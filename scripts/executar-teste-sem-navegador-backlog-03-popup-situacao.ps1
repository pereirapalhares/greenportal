param(
    [string]$Saida = 'tests/resultado-sem-navegador-backlog-03-popup-situacao.json'
)

$ErrorActionPreference = 'Stop'
node scripts/teste-sem-navegador-backlog-03-popup-situacao.js --saida $Saida
if ($LASTEXITCODE -ne 0) {
    throw "Teste do backlog item 3 terminou com codigo $LASTEXITCODE. Consulte $Saida."
}
Write-Host "Teste do backlog item 3 aprovado. Resultado: $Saida"
