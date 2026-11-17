param(
    [string]$Saida = 'tests/resultado-sem-navegador-backlog-05-integra-norma.json'
)

$ErrorActionPreference = 'Stop'
node scripts/teste-sem-navegador-backlog-05-integra-norma.js --saida $Saida
if ($LASTEXITCODE -ne 0) {
    throw "Teste do backlog item 5 terminou com codigo $LASTEXITCODE. Consulte $Saida."
}
Write-Host "Teste do backlog item 5 aprovado. Resultado: $Saida"
