[CmdletBinding()]
param(
    [string]$Url = 'http://localhost/greenportal',
    [Parameter(Mandatory)][string]$Empresa,
    [Parameter(Mandatory)][string]$Usuario,
    [Parameter(Mandatory)][string]$Senha,
    [string]$Saida = 'tests/resultado-nao-visual-performance-licencas.txt'
)
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $repo
try {
    node --check scripts/teste-nao-visual-performance-licencas.js
    if ($LASTEXITCODE -ne 0) { throw 'Sintaxe JavaScript inválida.' }

    $sql = @"
SELECT f.codigo AS perfil, p.pode_visualizar, p.pode_visualizar_total
FROM gmodelo_dbp.permissoes p
JOIN gmodelo_dbp.perfis f ON f.codperfil = p.codperfil
JOIN gmodelo_dbp.menus m ON m.codmenu = p.codmenu
WHERE m.codigo = 'LICENCAS'
  AND f.codigo IN ('ADMINISTRADOR','ADM_DA_EMPRESA');
"@
    $dbLines = @($sql | cmd.exe /d /c "mysql -uroot -ppA09031965@ gmodelo_dbp 2>NUL")
    $dbExitCode = $LASTEXITCODE
    $dbOutput = ($dbLines | ForEach-Object { [string]$_ }) -join "`n"
    if ($dbExitCode -ne 0 -or $dbOutput -notmatch 'ADM_DA_EMPRESA\s+1\s+1' -or $dbOutput -notmatch 'ADMINISTRADOR\s+1\s+1') {
        throw 'Permissões de visualização total para Licenças não estão configuradas.'
    }
    Write-Output 'PASS - ADM_DA_EMPRESA e ADMINISTRADOR com Visualizar Total'

    $nodeArgs = @('scripts/teste-nao-visual-performance-licencas.js','--url',$Url,'--empresa',$Empresa,'--usuario',$Usuario,'--senha',$Senha)
    $nodeOutput = @(& node @nodeArgs 2>&1)
    $nodeExitCode = $LASTEXITCODE
    $nodeOutput | Tee-Object -FilePath $Saida
    if ($nodeExitCode -ne 0) { throw "Teste não visual de performance terminou com código $nodeExitCode." }
} finally { Pop-Location }
