[CmdletBinding()]
param(
    [string]$Url = 'http://localhost/greenportal',
    [string]$Empresa = 'Modelo',
    [string]$Usuario = 'admin',
    [string]$Senha = $env:GREEN_TEST_PASSWORD,
    [string]$ServiceUrl = '',
    [switch]$Processar
)
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $repo
$previousMysqlPwd = $env:MYSQL_PWD
try {
    node --check scripts/teste-resiliencia-servico-ia.js
    if ($LASTEXITCODE -ne 0) { throw 'JavaScript invalido.' }
    $mysql = 'E:\wamp\bin\mysql\mysql8.4.7\bin\mysql.exe'
    if (-not (Test-Path -LiteralPath $mysql)) { $mysql = (Get-Command mysql.exe -ErrorAction Stop).Source }
    $passwordLine = Get-Content .env | Where-Object { $_ -match '^GPT_DB_PASSWORD=' } | Select-Object -First 1
    if (-not $passwordLine) { throw 'GPT_DB_PASSWORD nao configurada.' }
    $env:MYSQL_PWD = (($passwordLine -split '=', 2)[1]).Trim('"''')
    $sql = @"
SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='gmodelo_dbp' AND ROUTINE_NAME='sp_ia_potencial_recuperar_expiradas';
SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='gmodelo_dbp' AND TABLE_NAME='ia_prompts_historico';
SELECT COUNT(*) FROM gmodelo_dbp.ia_prompts WHERE ativo=1 AND COALESCE(prompt_pt,'')<>'' AND COALESCE(prompt_en,'')<>'' AND COALESCE(prompt_es,'')<>'';
"@
    $db = (& $mysql -uroot gmodelo_dbp --batch --raw --skip-column-names -e $sql 2>$null) | ForEach-Object { [int]$_ }
    if ($db.Count -lt 3 -or $db[0] -ne 1) { throw 'Procedimento de recuperacao nao encontrado.' }; Write-Host 'PASS - Procedimento de recuperacao instalado'
    if ($db[1] -lt 1) { throw 'Tabela de historico de prompts nao encontrada.' }; Write-Host 'PASS - Historico de prompts instalado'
    if ($db[2] -lt 1) { throw 'Prompts PT, EN e ES nao estao cadastrados.' }; Write-Host 'PASS - Prompts nos tres idiomas cadastrados'
    $adminId = & $mysql -uroot gmodelo_dbp --batch --raw --skip-column-names -e "SELECT codusuario FROM usuarios WHERE userid='$Usuario' LIMIT 1;" 2>$null
    if ($LASTEXITCODE -ne 0 -or -not $adminId) { throw "Usuario de teste nao encontrado: $Usuario" }

    $fixture = "IA_RESILIENCIA_$([DateTime]::Now.ToString('yyyyMMddHHmmss'))"
    try {
        $insert = "INSERT INTO ml_potenciais_solicitacoes(codusuario,idioma,contexto_json,status,processador) VALUES($([int]$adminId),'pt','{}','PROCESSANDO','$fixture'); UPDATE ml_potenciais_solicitacoes SET iniciada_em=DATE_SUB(NOW(),INTERVAL 31 MINUTE) WHERE processador='$fixture';"
        & $mysql -uroot gmodelo_dbp --batch --raw --skip-column-names -e $insert 2>$null | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Nao foi possivel criar a fixture PROCESSANDO.' }
        $fixtureId = & $mysql -uroot gmodelo_dbp --batch --raw --skip-column-names -e "SELECT codsolicitacao FROM ml_potenciais_solicitacoes WHERE processador='$fixture' ORDER BY codsolicitacao DESC LIMIT 1;" 2>$null
        if ($LASTEXITCODE -ne 0 -or -not $fixtureId) { throw 'Nao foi possivel identificar a fixture PROCESSANDO.' }
        & $mysql -uroot gmodelo_dbp --batch --raw --skip-column-names -e "CALL sp_ia_potencial_recuperar_expiradas(30);" 2>$null | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Falha ao executar a recuperacao de solicitacoes expiradas.' }
        $recoveryStatus = & $mysql -uroot gmodelo_dbp --batch --raw --skip-column-names -e "SELECT status FROM ml_potenciais_solicitacoes WHERE codsolicitacao=$([int]$fixtureId);" 2>$null
        if ($LASTEXITCODE -ne 0 -or (($recoveryStatus | Select-Object -First 1).Trim() -ne 'PENDENTE')) { throw 'Solicitacao PROCESSANDO nao foi recuperada para PENDENTE.' }
        Write-Host 'PASS - Solicitacao expirada recuperada para reprocessamento'
    } finally {
        & $mysql -uroot gmodelo_dbp --batch --raw --skip-column-names -e "DELETE FROM ml_potenciais_solicitacoes WHERE processador='$fixture';" 2>$null | Out-Null
    }
    $argsNode = @('scripts/teste-resiliencia-servico-ia.js','--url',$Url,'--empresa',$Empresa,'--usuario',$Usuario,'--senha',$Senha,'--service-url',$ServiceUrl)
    if ($Processar) { $argsNode += '--processar' }
    & node @argsNode
    if ($LASTEXITCODE -ne 0) { throw "Teste terminou com codigo $LASTEXITCODE." }
    Write-Host 'PASS - Teste de resiliencia do servico de IA concluido'
} finally {
    if ($null -eq $previousMysqlPwd) { Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue } else { $env:MYSQL_PWD = $previousMysqlPwd }
    Pop-Location
}
