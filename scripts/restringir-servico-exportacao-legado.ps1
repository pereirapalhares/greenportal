[CmdletBinding(SupportsShouldProcess=$true)]
param([string]$Projeto='E:\wamp\apps\greenportal')
$ErrorActionPreference='Stop'
if(-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)){throw 'Execute este script como Administrador.'}
$legacy=Join-Path $Projeto 'services\ExportacaoGridService.php'
$disabledDir=Join-Path $Projeto 'services\disabled'
$disabled=Join-Path $disabledDir 'ExportacaoGridService.php.disabled'
if(-not [System.IO.File]::Exists($legacy)){Write-Output 'Arquivo legado já não está presente.';exit 0}
# O artefato pode possuir uma entrada DENY explícita, impedindo até o Administrador.
# Corrige somente o arquivo legado identificado antes de movê-lo para a área desativada.
& takeown.exe /F $legacy /A | Out-Null
& icacls.exe $legacy /grant '*S-1-5-32-544:F' /remove:d '*S-1-5-32-544' | Out-Null
New-Item -ItemType Directory -Path $disabledDir -Force | Out-Null
if(Test-Path -LiteralPath $disabled){throw "Destino já existe: $disabled"}
if($PSCmdlet.ShouldProcess($legacy,'Mover para área desativada')){Move-Item -LiteralPath $legacy -Destination $disabled -Force}
Write-Output "Serviço legado restringido em: $disabled"
