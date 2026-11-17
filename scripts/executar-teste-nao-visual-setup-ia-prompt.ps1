[CmdletBinding()]
param(
    [string]$Url = 'http://localhost/greenportal',
    [string]$Empresa = 'Modelo',
    [string]$Usuario = 'admin',
    [string]$Senha = $env:GREEN_TEST_PASSWORD
)
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $repo
try {
    $mysql = 'E:\wamp\bin\mysql\mysql8.4.7\bin\mysql.exe'
    if (-not (Test-Path -LiteralPath $mysql)) { $mysql = (Get-Command mysql.exe -ErrorAction Stop).Source }
    $envLines = Get-Content .\.env
    $passwordLine = $envLines | Where-Object { $_ -match '^GPT_DB_PASSWORD=' } | Select-Object -First 1
    $previousMysqlPwd = $env:MYSQL_PWD
    if ($passwordLine) { $env:MYSQL_PWD = (($passwordLine -split '=',2)[1]).Trim('"''') }
    $sql = "SELECT COUNT(*) FROM gmodelo_dbp.menus WHERE codigo='SETUP_IA_PROMPT' AND ativo=1; SELECT COUNT(*) FROM gmodelo_dbp.ia_prompts WHERE codigo='ML_REQUISITO_LEGAL' AND ativo=1; SELECT COUNT(*) FROM gmodelo_dbp.permissoes x JOIN gmodelo_dbp.menus m ON m.codmenu=x.codmenu JOIN gmodelo_dbp.perfis p ON p.codperfil=x.codperfil WHERE m.codigo='SETUP_IA_PROMPT' AND p.codigo='ADMINISTRADOR' AND x.pode_visualizar=1 AND x.pode_editar=1;"
    $db = (& $mysql -uroot gmodelo_dbp --batch --raw --skip-column-names -e $sql 2>$null) | ForEach-Object { [int]$_ }
    if ($db.Count -lt 3) { throw 'Não foi possível validar as estruturas do banco.' }
    if ($db[0] -ne 1) { throw 'Menu SETUP_IA_PROMPT não está ativo.' }; Write-Host 'PASS - Menu ativo no banco'
    if ($db[1] -lt 1) { throw 'Prompt inicial não foi cadastrado.' }; Write-Host 'PASS - Prompt inicial cadastrado'
    if ($db[2] -ne 1) { throw 'Permissão do administrador não está configurada.' }; Write-Host 'PASS - Permissão do administrador configurada'
    node --check scripts/teste-nao-visual-setup-ia-prompt.js
    if ($LASTEXITCODE -ne 0) { throw 'Sintaxe JavaScript inválida.' }
    node scripts/teste-nao-visual-setup-ia-prompt.js --url $Url --empresa $Empresa --usuario $Usuario --senha $Senha
    if ($LASTEXITCODE -ne 0) { throw "Teste terminou com código $LASTEXITCODE." }
} finally { if ($null -eq $previousMysqlPwd) { Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue } else { $env:MYSQL_PWD = $previousMysqlPwd }; Pop-Location }
