param(
    [string]$Empresa = '',
    [string]$Usuario = '',
    [string]$Senha = '',
    [string]$Saida = 'tests/resultado-sem-navegador-backlog-licencas-protocolo.json'
)

$ErrorActionPreference = 'Stop'
node scripts/teste-sem-navegador-backlog-licencas-protocolo.js --saida $Saida
if ($LASTEXITCODE -ne 0) {
    throw "Teste sem navegador de Licenças e Protocolo terminou com codigo $LASTEXITCODE. Consulte $Saida."
}
Write-Host "Teste sem navegador de Licenças e Protocolo concluido. Resultado: $Saida"
