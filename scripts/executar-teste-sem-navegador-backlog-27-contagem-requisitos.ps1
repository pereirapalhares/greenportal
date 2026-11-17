param(
    [string]$Saida = 'tests/resultado-sem-navegador-backlog-27-contagem-requisitos.json'
)

$ErrorActionPreference = 'Stop'
node scripts/teste-sem-navegador-backlog-27-contagem-requisitos.js --saida $Saida
if ($LASTEXITCODE -ne 0) {
    throw "Teste do backlog item 27 terminou com codigo $LASTEXITCODE. Consulte $Saida."
}
Write-Host "Teste do backlog item 27 concluido. Resultado: $Saida"
