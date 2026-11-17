param(
    [string]$Saida = 'tests/resultado-sem-navegador-backlog-14-protocolado.json'
)

$ErrorActionPreference = 'Stop'
node scripts/teste-sem-navegador-backlog-14-protocolado.js --saida $Saida
if ($LASTEXITCODE -ne 0) {
    throw "Teste do backlog item 14 terminou com codigo $LASTEXITCODE. Consulte $Saida."
}
Write-Host "Teste do backlog item 14 aprovado. Resultado: $Saida"
