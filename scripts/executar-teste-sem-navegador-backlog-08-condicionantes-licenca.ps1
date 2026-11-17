param(
    [string]$Saida = 'tests/resultado-sem-navegador-backlog-08-condicionantes-licenca.json'
)

$ErrorActionPreference = 'Stop'
node scripts/teste-sem-navegador-backlog-08-condicionantes-licenca.js --saida $Saida
if ($LASTEXITCODE -ne 0) {
    throw "Teste do backlog item 8 terminou com codigo $LASTEXITCODE. Consulte $Saida."
}
Write-Host "Teste do backlog item 8 aprovado. Resultado: $Saida"
