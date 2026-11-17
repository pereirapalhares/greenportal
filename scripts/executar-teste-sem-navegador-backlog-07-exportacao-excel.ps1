param(
    [string]$Saida = 'tests/resultado-sem-navegador-backlog-07-exportacao-excel.json'
)

$ErrorActionPreference = 'Stop'
node scripts/teste-sem-navegador-backlog-07-exportacao-excel.js --saida $Saida
if ($LASTEXITCODE -ne 0) {
    throw "Teste do backlog item 7 terminou com codigo $LASTEXITCODE. Consulte $Saida."
}
Write-Host "Teste do backlog item 7 aprovado. Resultado: $Saida"
