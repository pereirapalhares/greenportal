[CmdletBinding()]
param(
    [string]$Url = 'http://localhost/greenportal',
    [string]$Empresa = 'Modelo',
    [string]$AdmUsuario = 'gestor',
    [string]$GestorUsuario = 'ueditor',
    [string]$Senha = $env:GREEN_TEST_PASSWORD,
    [string]$Mysql = 'E:\wamp\bin\mysql\mysql8.4.7\bin\mysql.exe',
    [string]$Cdp = '',
    [string]$Saida = 'tests/evidencias_permissao_controle_documentos'
)
$ErrorActionPreference = 'Stop'
if (-not (Test-Path -LiteralPath $Mysql)) { throw "mysql.exe não encontrado: $Mysql" }
$atribuir = "UPDATE gmodelo_dbp.usuarios u JOIN gmodelo_dbp.perfis p ON p.codigo='ADM_DA_EMPRESA' SET u.codperfil=p.codperfil WHERE u.userid='$AdmUsuario'; UPDATE gmodelo_dbp.usuarios u JOIN gmodelo_dbp.perfis p ON p.codigo='GESTOR' SET u.codperfil=p.codperfil WHERE u.userid='$GestorUsuario';"
try {
    $ErrorActionPreference = 'Continue'
    $atribuir | & $Mysql -uroot -ppA09031965@ 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "Não foi possível atribuir os perfis temporários." }
    $ErrorActionPreference = 'Stop'
    $nodeArgs=@('scripts/teste-permissao-controle-documentos.js','--url',$Url,'--empresa',$Empresa,'--adm-usuario',$AdmUsuario,'--gestor-usuario',$GestorUsuario,'--senha',$Senha,'--saida',$Saida); if($Cdp){$nodeArgs+=@('--cdp',$Cdp)}; & node @nodeArgs
    if ($LASTEXITCODE -ne 0) { throw "Teste de permissões terminou com código $LASTEXITCODE." }
}
finally {
    $admRestore = if ($AdmUsuario -eq 'admin') { "UPDATE gmodelo_dbp.usuarios u JOIN gmodelo_dbp.perfis p ON p.codigo='ADMINISTRADOR' SET u.codperfil=p.codperfil WHERE u.userid='$AdmUsuario';" } else { "UPDATE gmodelo_dbp.usuarios SET codperfil=NULL WHERE userid='$AdmUsuario';" }
    $restaurar = "$admRestore UPDATE gmodelo_dbp.usuarios u JOIN gmodelo_dbp.perfis p ON p.codigo='GESTOR' SET u.codperfil=p.codperfil WHERE u.userid='$GestorUsuario';"
    $ErrorActionPreference = 'Continue'
    $restaurar | & $Mysql -uroot -ppA09031965@ 2>&1 | Out-Null
    $ErrorActionPreference = 'Stop'
    Write-Host "Perfis temporários restaurados: $AdmUsuario sem perfil e $GestorUsuario como GESTOR."
}
