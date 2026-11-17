param(
    [string]$Saida = 'tests/resultado-sem-navegador-backlog-29-arquivos-licencas.json'
)

$ErrorActionPreference = 'Stop'
node scripts/teste-sem-navegador-backlog-29-arquivos-licencas.js --saida $Saida
if ($LASTEXITCODE -ne 0) {
    throw "Teste do backlog item 29 terminou com codigo $LASTEXITCODE. Consulte $Saida."
}
Write-Host "Teste do backlog item 29 concluido. Resultado: $Saida"
