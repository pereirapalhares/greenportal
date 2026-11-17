[CmdletBinding()]
param(
    [string]$Projeto = 'E:\wamp\apps\greenportal',
    [switch]$InstalarNavegador
)

$ErrorActionPreference = 'Stop'

function Require-Command {
    param([Parameter(Mandatory)][string]$Name)
    $command = Get-Command $Name -ErrorAction SilentlyContinue
    if (-not $command) {
        throw "Comando '$Name' não encontrado. Instale o Node.js com npm antes de continuar."
    }
    return $command
}

function Invoke-PlaywrightCli {
    param([Parameter(ValueFromRemainingArguments)][string[]]$Arguments)
    $cli = Join-Path $Projeto 'node_modules\playwright\cli.js'
    if (-not (Test-Path -LiteralPath $cli -PathType Leaf)) {
        throw "CLI local do Playwright não encontrado: $cli"
    }
    $output = @(& node $cli @Arguments 2>&1)
    $exitCode = $LASTEXITCODE
    foreach ($line in $output) { Write-Host ([string]$line) }
    if ($exitCode -ne 0) { throw "O CLI do Playwright terminou com código $exitCode." }
}

if (-not (Test-Path -LiteralPath $Projeto -PathType Container)) {
    throw "Diretório do projeto não encontrado: $Projeto"
}

$null = Require-Command -Name 'node'
$null = Require-Command -Name 'npm'
$null = Require-Command -Name 'npx'

Push-Location -LiteralPath $Projeto
try {
    Write-Host 'Node.js:' -ForegroundColor Cyan
    node --version
    Write-Host 'npm:' -ForegroundColor Cyan
    npm --version

    if (-not (Test-Path -LiteralPath (Join-Path $Projeto 'package.json') -PathType Leaf)) {
        Write-Host 'Criando package.json...' -ForegroundColor Yellow
        npm init -y
    }

    $playwrightPackage = Join-Path $Projeto 'node_modules\playwright\package.json'
    if (Test-Path -LiteralPath $playwrightPackage -PathType Leaf) {
        Write-Host 'Playwright já está instalado localmente; nenhuma nova baixa será executada.' -ForegroundColor Green
    } else {
        Write-Host 'Instalando Playwright como dependência de desenvolvimento...' -ForegroundColor Yellow
        & npm install --save-dev playwright
        if ($LASTEXITCODE -ne 0) { throw "A instalação do Playwright falhou com código $LASTEXITCODE." }
    }

    Write-Host 'Validando instalação...' -ForegroundColor Yellow
    npm list playwright --depth=0
    Invoke-PlaywrightCli --version

    if ($InstalarNavegador) {
        $chromium = Get-ChildItem -LiteralPath (Join-Path $env:LOCALAPPDATA 'ms-playwright') -Recurse -Filter chrome.exe -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($chromium) {
            Write-Host "Chromium do Playwright já está instalado: $($chromium.FullName)" -ForegroundColor Green
        } else {
            Write-Host 'Instalando/validando o Chromium do Playwright...' -ForegroundColor Yellow
            Invoke-PlaywrightCli install chromium
        }
    }

    Write-Host ''
    Write-Host 'Playwright instalado com sucesso.' -ForegroundColor Green
    Write-Host 'Para instalar/validar o navegador posteriormente:' -ForegroundColor Gray
    Write-Host '  .\scripts\instalar-playwright.ps1 -InstalarNavegador' -ForegroundColor Gray
}
finally {
    Pop-Location
}
