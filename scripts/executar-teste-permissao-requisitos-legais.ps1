[CmdletBinding()]
param(
    [string]$Url = 'http://localhost/greenportal',
    [string]$Empresa = 'Modelo',
    [string]$AdminUsuario = 'admin',
    [Parameter(Mandatory)][string]$AdminSenha,
    [string]$Usuario = 'ueditor',
    [Parameter(Mandatory)][string]$Senha,
    [switch]$Visivel,
    [string]$Cdp = '',
    [string]$Saida = 'tests/evidencias_requisitos_legais'
)
$ErrorActionPreference = 'Stop'
$argsNode = @('scripts/teste-permissao-requisitos-legais.js','--url',$Url,'--empresa',$Empresa,'--admin-usuario',$AdminUsuario,'--admin-senha',$AdminSenha,'--usuario',$Usuario,'--senha',$Senha,'--saida',$Saida)
if ($Visivel) { $argsNode += '--visivel' }
if ($Cdp) { $argsNode += @('--cdp', $Cdp) }
& node @argsNode
if ($LASTEXITCODE -ne 0) { throw "Teste de permissões terminou com código $LASTEXITCODE." }
