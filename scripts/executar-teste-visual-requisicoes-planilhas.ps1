[CmdletBinding()]
param([string]$Url='http://localhost/greenportal',[Parameter(Mandatory)][string]$Empresa,[Parameter(Mandatory)][string]$Usuario,[Parameter(Mandatory)][string]$Senha,[switch]$Visivel,[string]$Saida='tests/evidencias_requisicoes_planilhas')
$ErrorActionPreference='Stop';$repo=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path;Push-Location $repo
try{node --check scripts/teste-visual-requisicoes-planilhas.js;if($LASTEXITCODE -ne 0){throw 'JavaScript inválido.'};$a=@('scripts/teste-visual-requisicoes-planilhas.js','--url',$Url,'--empresa',$Empresa,'--usuario',$Usuario,'--senha',$Senha,'--saida',$Saida);if($Visivel){$a+='--visivel'};& node @a;if($LASTEXITCODE -ne 0){throw 'Teste visual das requisições terminou com erro.'}}finally{Pop-Location}
