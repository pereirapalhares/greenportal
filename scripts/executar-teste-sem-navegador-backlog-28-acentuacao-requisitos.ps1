param(
    [string]$Saida = 'tests/resultado-sem-navegador-backlog-28-acentuacao-requisitos.json'
)

$ErrorActionPreference = 'Stop'
node scripts/teste-sem-navegador-backlog-28-acentuacao-requisitos.js --saida $Saida
if ($LASTEXITCODE -ne 0) {
    throw "Teste do backlog item 28 terminou com codigo $LASTEXITCODE. Consulte $Saida."
}
Write-Host "Teste do backlog item 28 concluido. Resultado: $Saida"
