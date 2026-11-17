$ErrorActionPreference = 'Stop'
$serviceRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$python = Join-Path $serviceRoot '.venv\Scripts\python.exe'
if (-not (Test-Path $python)) {
    $systemPython = (Get-Command python.exe -ErrorAction SilentlyContinue).Source
    if (-not $systemPython) { $systemPython = (Get-Command py.exe -ErrorAction SilentlyContinue).Source }
    if (-not $systemPython) { throw 'Python 3.11+ nao encontrado. Instale o Python e execute novamente este script.' }
    Write-Host 'Criando ambiente virtual OCR...'
    & $systemPython -m venv (Join-Path $serviceRoot '.venv')
    $python = Join-Path $serviceRoot '.venv\Scripts\python.exe'
    if (-not (Test-Path $python)) { throw 'Nao foi possivel criar o ambiente virtual OCR.' }
    Write-Host 'Instalando dependencias OCR...'
    & $python -m pip install --upgrade pip
    & $python -m pip install -r (Join-Path $serviceRoot 'requirements.txt')
}
$nssm = (Get-Command nssm.exe -ErrorAction SilentlyContinue).Source
if (-not $nssm) { throw 'NSSM nao encontrado no PATH. Instale o NSSM e execute novamente como Administrador.' }
$rootEnv = Join-Path (Split-Path -Parent (Split-Path -Parent $serviceRoot)) '.env'
$token = $env:GREENPORTAL_OCR_TOKEN
if ([string]::IsNullOrWhiteSpace($token) -and (Test-Path $rootEnv)) {
    $line = Get-Content -Encoding utf8 $rootEnv | Where-Object { $_ -match '^OCR_SERVICE_TOKEN=' } | Select-Object -First 1
    if ($line) { $token = ($line -split '=', 2)[1].Trim().Trim('"') }
}
if ([string]::IsNullOrWhiteSpace($token)) { throw 'Defina GREENPORTAL_OCR_TOKEN ou OCR_SERVICE_TOKEN antes de instalar o servico.' }
$serviceName = 'GreenPortalOCR'
$existing = Get-Service -Name $serviceName -ErrorAction SilentlyContinue
if ($existing) { & $nssm stop $serviceName 2>$null; & $nssm remove $serviceName confirm 2>$null }
& $nssm install $serviceName $python
& $nssm set $serviceName AppParameters '-m uvicorn app:app --host 127.0.0.1 --port 8091 --workers 1'
& $nssm set $serviceName AppDirectory $serviceRoot
& $nssm set $serviceName Start SERVICE_AUTO_START
& $nssm set $serviceName AppExit Default Restart
& $nssm set $serviceName AppEnvironmentExtra "GREENPORTAL_OCR_TOKEN=$token" "GREENPORTAL_OCR_LOG=E:\wamp\apps\greenportal\runtime\ocr_service.log"
& $nssm start $serviceName
Write-Host "Serviço $serviceName instalado e iniciado em 127.0.0.1:8091."
