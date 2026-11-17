param(
    [string]$Saida = 'tests/resultado-sem-navegador-backlog-22-objetivos-metas.json'
)

$ErrorActionPreference = 'Stop'
node scripts/teste-sem-navegador-backlog-22-objetivos-metas.js --saida $Saida
if ($LASTEXITCODE -ne 0) {
    Write-Host "Item 22 pendente conforme teste. Resultado: $Saida"
    exit $LASTEXITCODE
}
Write-Host "Teste do backlog item 22 aprovado. Resultado: $Saida"
