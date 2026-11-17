[CmdletBinding()]
param(
  [string]$Url='http://localhost/greenportal',
  [Parameter(Mandatory)][string]$Empresa,
  [Parameter(Mandatory)][string]$Usuario,
  [Parameter(Mandatory)][string]$Senha,
  [switch]$Visivel,
  [string]$Saida='tests/evidencias_acesso_servicos'
)
$ErrorActionPreference='Stop'; $repo=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path; Push-Location $repo
try { node --check scripts/teste-visual-acesso-servicos-v2.js; if($LASTEXITCODE -ne 0){throw 'JavaScript inválido.'}; $a=@('scripts/teste-visual-acesso-servicos-v2.js','--url',$Url,'--empresa',$Empresa,'--usuario',$Usuario,'--senha',$Senha,'--saida',$Saida); if($Visivel){$a+='--visivel'}; & node @a; if($LASTEXITCODE -ne 0){throw 'Teste visual de acesso a serviços terminou com erro.'} } finally { Pop-Location }
