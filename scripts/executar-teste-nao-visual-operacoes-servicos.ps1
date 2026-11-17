[CmdletBinding()]
param([switch]$ExecutarAcoes)
$ErrorActionPreference='Stop'
if($ExecutarAcoes){Write-Warning 'Modo executar ações não está habilitado neste teste não visual; nenhum serviço será parado, iniciado ou processado.'}
node scripts/teste-nao-visual-operacoes-servicos.js
if($LASTEXITCODE -ne 0){throw "Teste de operações de serviços terminou com código $LASTEXITCODE"}
Write-Host 'Teste não visual das operações de serviços aprovado. Modo: validação segura, sem alterar serviços ou filas.'
