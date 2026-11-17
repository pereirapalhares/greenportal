param(
    [string]$Saida = 'tests/resultado-sem-navegador-backlog-09-pesquisa-ambiental.json'
)

$ErrorActionPreference = 'Stop'
node scripts/teste-sem-navegador-backlog-09-pesquisa-ambiental.js --saida $Saida
if ($LASTEXITCODE -ne 0) {
    Write-Host "Item 9 pendente conforme teste. Resultado: $Saida"
    exit $LASTEXITCODE
}
Write-Host "Teste do backlog item 9 aprovado. Resultado: $Saida"
