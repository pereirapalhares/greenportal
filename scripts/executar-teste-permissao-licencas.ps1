[CmdletBinding()]
param(
    [string]$Url = 'http://localhost/greenportal',
    [string]$Empresa = 'Modelo',
    [string]$AdminUsuario = 'admin',
    [Parameter(Mandatory = $true)][string]$AdminSenha,
    [string]$Usuario = 'ueditor',
    [Parameter(Mandatory = $true)][string]$Senha,
    [switch]$Visivel,
    [string]$Saida = 'tests/evidencias_licencas'
)

$ErrorActionPreference = 'Stop'
$nodeArgs = @(
    'scripts/teste-permissao-licencas-v2.js',
    '--url', $Url,
    '--empresa', $Empresa,
    '--admin-usuario', $AdminUsuario,
    '--admin-senha', $AdminSenha,
    '--usuario', $Usuario,
    '--senha', $Senha,
    '--saida', $Saida
)
if ($Visivel) { $nodeArgs += '--visivel' }
& node @nodeArgs
if ($LASTEXITCODE -ne 0) {
    throw "Teste de permissões de Licenças terminou com código $LASTEXITCODE."
}
