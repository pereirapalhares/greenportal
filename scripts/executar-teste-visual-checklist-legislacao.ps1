[CmdletBinding()]
param([string]$Url='http://localhost/greenportal',[Parameter(Mandatory)][string]$Empresa,[Parameter(Mandatory)][string]$Usuario,[Parameter(Mandatory)][string]$Senha,[switch]$Headless,[string]$Saida='tests/evidencias-checklist-legislacao')
$ErrorActionPreference='Stop'; $repo=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path; Push-Location $repo
try { node --check scripts/teste-visual-checklist-legislacao.js; if($LASTEXITCODE -ne 0){throw 'Sintaxe JavaScript inválida.'}; $a=@('scripts/teste-visual-checklist-legislacao.js','--url',$Url,'--empresa',$Empresa,'--usuario',$Usuario,'--senha',$Senha,'--saida',$Saida); if($Headless){$a+='--headless'}; & node @a; if($LASTEXITCODE -ne 0){throw "Teste visual terminou com código $LASTEXITCODE."} } finally { Pop-Location }
