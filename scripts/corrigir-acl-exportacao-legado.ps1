[CmdletBinding(SupportsShouldProcess=$true)]
param(
    [string]$Projeto = 'E:\wamp\apps\greenportal'
)

$ErrorActionPreference = 'Stop'

if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Execute este script em um PowerShell como Administrador.'
}

$services = Join-Path $Projeto 'services'
$legacy = Join-Path $services 'ExportacaoGridService.php'
$disabled = Join-Path $services 'disabled'
$target = Join-Path $disabled 'ExportacaoGridService.php.disabled'
$adminSid = '*S-1-5-32-544'

if (-not (Test-Path -LiteralPath $services -PathType Container)) {
    throw "Diretório de serviços não localizado: $services"
}

if (-not [System.IO.File]::Exists($legacy)) {
    Write-Output 'O arquivo legado já não existe. Nenhuma alteração necessária.'
    exit 0
}

if (Test-Path -LiteralPath $target) {
    throw "O destino já existe e não será sobrescrito: $target"
}

New-Item -ItemType Directory -Path $disabled -Force | Out-Null

if ($PSCmdlet.ShouldProcess($legacy, 'Corrigir ACL e mover para services\disabled')) {
    & takeown.exe /F $services /A /D Y /R
    if ($LASTEXITCODE -ne 0) { throw "takeown falhou com código $LASTEXITCODE." }

    & icacls.exe $services /remove:d $adminSid /T /C
    & icacls.exe $services /grant "$adminSid`:F" /T /C
    if ($LASTEXITCODE -ne 0) { throw "icacls no diretório falhou com código $LASTEXITCODE." }

    & icacls.exe $legacy /reset /C
    if ($LASTEXITCODE -ne 0) { throw "icacls /reset falhou com código $LASTEXITCODE." }

    & icacls.exe $legacy /grant "$adminSid`:F" /C
    if ($LASTEXITCODE -ne 0) { throw "icacls no arquivo falhou com código $LASTEXITCODE." }

    Move-Item -LiteralPath $legacy -Destination $target -Force
}

Write-Output "Arquivo legado restringido em: $target"
Write-Output "Arquivo original presente: $(Test-Path -LiteralPath $legacy)"
Write-Output "Artefato desativado presente: $(Test-Path -LiteralPath $target)"
