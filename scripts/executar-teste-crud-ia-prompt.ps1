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
$previousMysqlPwd = $env:MYSQL_PWD
try {
    node --check scripts/teste-crud-ia-prompt.js
    if ($LASTEXITCODE -ne 0) { throw 'JavaScript invalido.' }
    $mysql = 'E:\wamp\bin\mysql\mysql8.4.7\bin\mysql.exe'
    if (-not (Test-Path -LiteralPath $mysql)) { $mysql = (Get-Command mysql.exe -ErrorAction Stop).Source }
    $passwordLine = Get-Content .env | Where-Object { $_ -match '^GPT_DB_PASSWORD=' } | Select-Object -First 1
    if ($passwordLine) { $env:MYSQL_PWD = (($passwordLine -split '=', 2)[1]).Trim('"''') }
    $sql = "SELECT COUNT(*) FROM gmodelo_dbp.ia_prompts WHERE codigo='ML_REQUISITO_LEGAL' AND ativo=1; SELECT COUNT(*) FROM gmodelo_dbp.menus WHERE codigo='SETUP_IA_PROMPT' AND ativo=1;"
    $db = (& $mysql -uroot gmodelo_dbp --batch --raw --skip-column-names -e $sql 2>$null) | ForEach-Object { [int]$_ }
    if ($db.Count -lt 2 -or $db[0] -lt 1) { throw 'Prompt ML_REQUISITO_LEGAL nao encontrado.' }; Write-Host 'PASS - Prompt operacional cadastrado'
    if ($db[1] -ne 1) { throw 'Menu de prompts nao esta ativo.' }; Write-Host 'PASS - Menu de prompts ativo'
    & node scripts/teste-crud-ia-prompt.js --url $Url --empresa $Empresa --usuario $Usuario --senha $Senha
    if ($LASTEXITCODE -ne 0) { throw "Teste terminou com codigo $LASTEXITCODE." }
} finally {
    if ($null -eq $previousMysqlPwd) { Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue } else { $env:MYSQL_PWD = $previousMysqlPwd }
    Pop-Location
}
