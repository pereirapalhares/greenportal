param(
    [string]$Saida = 'tests/resultado-sem-navegador-backlog-13-licenca-responsavel.json'
)

$ErrorActionPreference = 'Stop'
node scripts/teste-sem-navegador-backlog-13-licenca-responsavel.js --saida $Saida
if ($LASTEXITCODE -ne 0) {
    throw "Teste do backlog item 13 terminou com codigo $LASTEXITCODE. Consulte $Saida."
}
Write-Host "Teste do backlog item 13 aprovado. Resultado: $Saida"
