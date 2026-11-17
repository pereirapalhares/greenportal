param(
  [string]$Senha = $env:GREEN_MYSQL_ROOT_PASSWORD,
  [string]$Mysql = 'E:\wamp\bin\mysql\mysql8.4.7\bin\mysql.exe'
)
$ErrorActionPreference='Stop'
if([string]::IsNullOrEmpty($Senha)){throw 'Informe -Senha ou defina GREEN_MYSQL_ROOT_PASSWORD (a senha nao fica no codigo).'}
if(-not (Test-Path -LiteralPath $Mysql)){throw "mysql nao encontrado: $Mysql"}
$sql="SELECT codigo,HEX(nome_pt),HEX(nome_en),HEX(nome_es),HEX(descricao_pt) FROM guniversal_dbp.servicos WHERE codigo IN ('EXPORTACAO_GRID','INTELIGENCIA_ARTIFICIAL') ORDER BY codigo; SELECT HEX(label_pt),HEX(label_en),HEX(label_es) FROM gmodelo_dbp.menus WHERE codigo='ADMINISTRACAO_SERVICOS'; SELECT HEX(label_es) FROM gmodelo_dbp.menus WHERE codigo='SITUACAO_CUMPRIMENTO';"
$dbRows=& $Mysql -uroot "-p$Senha" -N -B -e $sql
if($LASTEXITCODE -ne 0){throw 'Nao foi possivel consultar o banco'}
$joined=($dbRows -join "`n")
if(($dbRows | Where-Object {$_ -match '^EXPORTACAO_GRID\t'}).Count -ne 1 -or ($dbRows | Where-Object {$_ -match '^INTELIGENCIA_ARTIFICIAL\t'}).Count -ne 1){throw 'Servicos nao localizados no banco'}
if($joined -match '3F3F|3F\t'){throw 'Foram encontrados caracteres corrompidos nos registros de servicos'}
if($joined -notmatch '5365727669C3A76F73' -or $joined -notmatch '53697475616369C3B36E'){throw 'Labels acentuados nao conferem no banco'}
Write-Host 'PASS - Banco de dados sem acentuacao corrompida nos registros validados'
node scripts/teste-nao-visual-acentuacao-servicos.js --mysql $Mysql --senha $Senha --skip-db
if($LASTEXITCODE -ne 0){throw "Teste de acentuacao terminou com codigo $LASTEXITCODE"}
Write-Host 'Teste nao visual de acentuacao aprovado.'
