$ErrorActionPreference='Stop'
node scripts/teste-nao-visual-acesso-servicos.js
if($LASTEXITCODE -ne 0){throw "Teste de acesso a servicos terminou com codigo $LASTEXITCODE"}
Write-Host 'Teste nao visual de acesso a servicos aprovado.'
