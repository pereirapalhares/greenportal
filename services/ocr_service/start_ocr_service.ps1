$ErrorActionPreference = 'Stop'
$serviceRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$python = Join-Path $serviceRoot '.venv\Scripts\python.exe'
if (-not (Test-Path $python)) { throw 'Ambiente virtual nao encontrado. Execute install_ocr_service.ps1 ou crie o .venv conforme o README.md.' }
$rootEnv = Join-Path (Split-Path -Parent (Split-Path -Parent $serviceRoot)) '.env'
if (-not $env:GREENPORTAL_OCR_TOKEN -and (Test-Path $rootEnv)) {
    $line = Get-Content -Encoding utf8 $rootEnv | Where-Object { $_ -match '^OCR_SERVICE_TOKEN=' } | Select-Object -First 1
    if ($line) { $env:GREENPORTAL_OCR_TOKEN = ($line -split '=', 2)[1].Trim().Trim('"') }
}
$env:GREENPORTAL_OCR_TOKEN = if ($env:GREENPORTAL_OCR_TOKEN) { $env:GREENPORTAL_OCR_TOKEN } else { throw 'Defina GREENPORTAL_OCR_TOKEN ou OCR_SERVICE_TOKEN antes de iniciar o servico.' }
$env:GREENPORTAL_OCR_LOG = Join-Path (Split-Path -Parent (Split-Path -Parent $serviceRoot)) 'runtime\ocr_service.log'
& $python -m uvicorn app:app --host 127.0.0.1 --port 8091 --workers 1
