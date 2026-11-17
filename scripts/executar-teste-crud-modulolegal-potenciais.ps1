[CmdletBinding()]
param(
    [string]$Url = 'http://localhost/greenportal',
    [string]$Empresa = 'Modelo',
    [string]$AdminUsuario = 'admin',
    [string]$AdmEmpresaUsuario = 'ueditor',
    [string]$GestorUsuario = 'gestor',
    [string]$Senha = $env:GREEN_TEST_PASSWORD,
    [string]$Mysql = 'E:\wamp\bin\mysql\mysql8.4.7\bin\mysql.exe',
    [switch]$Visual,
    [switch]$Mutar,
    [string]$Cdp = '',
    [string]$Saida = ''
)
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $repo
$previousMysqlPwd = $env:MYSQL_PWD
$env:MYSQL_PWD = 'pA09031965@'
$prefix = 'teste-ia-' + ([guid]::NewGuid().ToString('N').Substring(0, 10))
$originalProfiles = @{}
function SqlEscape([string]$value) { return $value.Replace("'", "''") }
function Get-Profile([string]$userid) {
    $safe = SqlEscape $userid
    $row = (& $Mysql -uroot gmodelo_dbp --batch --raw --skip-column-names -e "SELECT userid,COALESCE(codperfil,'') FROM usuarios WHERE userid='$safe';" 2>$null)
    if ($LASTEXITCODE -ne 0 -or -not $row) { throw "Usuário não encontrado: $userid" }
    $parts = ($row | Select-Object -First 1).ToString().Split("`t")
    return [pscustomobject]@{ Userid=$parts[0]; Codperfil=$parts[1] }
}
function Set-Profile([string]$userid, [string]$profileCode) {
    $safeUser = SqlEscape $userid; $safeProfile = SqlEscape $profileCode
    $sql = "UPDATE gmodelo_dbp.usuarios u JOIN gmodelo_dbp.perfis p ON p.codigo='$safeProfile' SET u.codperfil=p.codperfil WHERE u.userid='$safeUser';"
    $sql | & $Mysql -uroot 2>$null | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "Falha ao atribuir perfil $profileCode para $userid." }
}
function Seed-Fixture {
    $safe = SqlEscape $prefix
    $sql = @"
INSERT INTO gmodelo_dbp.ml_potenciais_solicitacoes (codusuario,idioma,contexto_json,status) VALUES (NULL,'pt',JSON_OBJECT('CODLEGISLACAO',1,'teste_prefixo','$safe'),'CONCLUIDA');
SET @ia_teste_solicitacao = LAST_INSERT_ID();
INSERT INTO gmodelo_dbp.ml_potenciais (codsolicitacao,tipo_potencial,titulo,descricao,justificativa_ia,confianca_ia,idioma_conteudo,status) VALUES (@ia_teste_solicitacao,'CONTROLE','$safe-aprovar','Fixture de teste','Fixture de teste',99.00,'pt','SUGERIDO');
INSERT INTO gmodelo_dbp.ml_potenciais (codsolicitacao,tipo_potencial,titulo,descricao,justificativa_ia,confianca_ia,idioma_conteudo,status) VALUES (@ia_teste_solicitacao,'TREINAMENTO','$safe-rejeitar','Fixture de teste','Fixture de teste',98.00,'pt','SUGERIDO');
"@
    $sql | & $Mysql -uroot 2>$null | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Falha ao criar fixtures dos potenciais.' }
}
function Remove-Fixture {
    $safe = SqlEscape $prefix
    $sql = "DELETE FROM gmodelo_dbp.ml_potenciais WHERE titulo LIKE '$safe%'; DELETE FROM gmodelo_dbp.ml_potenciais_solicitacoes WHERE JSON_UNQUOTE(JSON_EXTRACT(contexto_json,'$.teste_prefixo'))='$safe';"
    $sql | & $Mysql -uroot 2>$null | Out-Null
    if ($LASTEXITCODE -ne 0) { Write-Warning 'A limpeza dos fixtures retornou código diferente de zero.' }
}
try {
    if (-not (Get-Command node -ErrorAction SilentlyContinue)) { throw 'Node.js não está disponível no PATH.' }
    if (-not (Test-Path -LiteralPath $Mysql)) { throw "mysql.exe não encontrado: $Mysql" }
    foreach ($user in @($AdminUsuario,$AdmEmpresaUsuario,$GestorUsuario) | Select-Object -Unique) { $originalProfiles[$user] = Get-Profile $user }
    Set-Profile $AdminUsuario 'ADMINISTRADOR'
    Set-Profile $AdmEmpresaUsuario 'ADM_DA_EMPRESA'
    Set-Profile $GestorUsuario 'GESTOR'
    if ($Mutar) { Seed-Fixture }
    $js = Join-Path $repo 'scripts\teste-crud-modulolegal-potenciais.js'
    & node --check $js
    if ($LASTEXITCODE -ne 0) { throw 'Sintaxe JavaScript inválida.' }
    $nodeArgs = @($js,'--url',$Url,'--empresa',$Empresa,'--admin-usuario',$AdminUsuario,'--adm-empresa-usuario',$AdmEmpresaUsuario,'--gestor-usuario',$GestorUsuario,'--senha',$Senha,'--fixture-prefix',$prefix)
    if ($Visual) { $nodeArgs += '--visual' }
    if ($Mutar) { $nodeArgs += '--mutar' }
    if ($Cdp) { $nodeArgs += @('--cdp', $Cdp) }
    if ($Saida) { $nodeArgs += @('--saida',$Saida) }
    & node @nodeArgs
    if ($LASTEXITCODE -ne 0) { throw "Teste terminou com código $LASTEXITCODE." }
} finally {
    if ($Mutar) { Remove-Fixture }
    foreach ($entry in $originalProfiles.GetEnumerator()) {
        $safeUser = SqlEscape $entry.Key
        $profile = $entry.Value.Codperfil
        $value = if ([string]::IsNullOrWhiteSpace($profile)) { 'NULL' } else { [int64]$profile }
        "UPDATE gmodelo_dbp.usuarios SET codperfil=$value WHERE userid='$safeUser';" | & $Mysql -uroot 2>$null | Out-Null
    }
    Write-Host 'Perfis originais e fixtures temporários restaurados.'
    if ($null -eq $previousMysqlPwd) { Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue } else { $env:MYSQL_PWD = $previousMysqlPwd }
    Pop-Location
}
