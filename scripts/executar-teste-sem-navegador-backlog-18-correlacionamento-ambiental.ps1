param(
    [string]$Saida = 'tests/resultado-sem-navegador-backlog-18-correlacionamento-ambiental.json'
)

$ErrorActionPreference = 'Stop'
node scripts/teste-sem-navegador-backlog-18-correlacionamento-ambiental.js --saida $Saida
if ($LASTEXITCODE -ne 0) {
    Write-Host "Item 18 pendente conforme teste. Resultado: $Saida"
    exit $LASTEXITCODE
}
Write-Host "Teste do backlog item 18 aprovado. Resultado: $Saida"
