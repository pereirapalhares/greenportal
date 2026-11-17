[CmdletBinding()]
param(
    [string]$Url = 'http://localhost/greenportal',
    [Parameter(Mandatory)][string]$Empresa,
    [Parameter(Mandatory)][string]$Usuario,
    [Parameter(Mandatory)][string]$Senha,
    [switch]$Visivel,
    [string]$Saida = 'tests/evidencias_licencas'
)
$ErrorActionPreference = 'Stop'
$argsNode = @('scripts/teste-visual-licencas.js','--url',$Url,'--empresa',$Empresa,'--usuario',$Usuario,'--senha',$Senha,'--saida',$Saida)
if ($Visivel) { $argsNode += '--visivel' }
& node @argsNode
if ($LASTEXITCODE -ne 0) { throw "Teste visual de Licenças terminou com código $LASTEXITCODE." }
