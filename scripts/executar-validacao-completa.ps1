[CmdletBinding()]
param(
    [string]$Url = 'http://localhost/greenportal',
    [Parameter(Mandatory)][string]$Empresa,
    [Parameter(Mandatory)][string]$Usuario,
    [Parameter(Mandatory)][string]$Senha,
    [switch]$Visivel,
    [switch]$IgnorarTesteVisual,
    [string]$SaidaVisual = 'tests/evidencias_painel_pendencias',
    [string]$ArquivoResultado = '',
    [string[]]$ArquivosPhp = @(),
    [string[]]$ArquivosJs = @()
)

$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $repo

$inicio = Get-Date
$etapas = New-Object System.Collections.Generic.List[object]

function Registrar-Etapa {
    param([string]$Nome, [bool]$Sucesso, [string]$Detalhes = '')
    $etapas.Add([pscustomobject]@{
        nome = $Nome
        sucesso = $Sucesso
        detalhes = $Detalhes
    })
    if ($Sucesso) {
        Write-Host "PASS - $Nome$(if ($Detalhes) { ': ' + $Detalhes })" -ForegroundColor Green
    } else {
        Write-Host "FAIL - $Nome$(if ($Detalhes) { ': ' + $Detalhes })" -ForegroundColor Red
    }
}

function Executar-Etapa {
    param([string]$Nome, [scriptblock]$Acao)
    try {
        $detalhes = & $Acao
        Registrar-Etapa -Nome $Nome -Sucesso $true -Detalhes ([string]($detalhes -join ' '))
        return $true
    } catch {
        Registrar-Etapa -Nome $Nome -Sucesso $false -Detalhes $_.Exception.Message
        return $false
    }
}

try {
    if (-not $ArquivosPhp.Count) {
        $phpRoots = @('principal', 'classes', 'components') | ForEach-Object { Join-Path $repo $_ }
        $ArquivosPhp = @(Get-ChildItem -LiteralPath $phpRoots -Recurse -File -Filter '*.php' -ErrorAction Stop |
            Where-Object { $_.FullName -notmatch '\\backup(s)?\\' } |
            Sort-Object FullName |
            ForEach-Object { $_.FullName.Substring($repo.Length + 1) })
    }
    if (-not $ArquivosJs.Count) {
        $jsArquivosLocal = @(Get-ChildItem -LiteralPath (Join-Path $repo 'assets/js') -Recurse -File -Filter '*.js' -ErrorAction Stop |
            Where-Object { $_.Name -notlike '*.min.js' } |
            Sort-Object FullName |
            ForEach-Object { $_.FullName.Substring($repo.Length + 1) })
        $jsArquivosScripts = @(Get-ChildItem -LiteralPath (Join-Path $repo 'scripts') -File -Filter '*.js' -ErrorAction Stop |
            Sort-Object FullName |
            ForEach-Object { $_.FullName.Substring($repo.Length + 1) })
        $ArquivosJs = @($jsArquivosLocal + $jsArquivosScripts)
    }

    Write-Host "Escopo PHP: $($ArquivosPhp.Count) arquivos"
    Write-Host "Escopo JavaScript: $($ArquivosJs.Count) arquivos"

    $tudoOk = $true
    $tudoOk = (Executar-Etapa 'PHP disponível' {
        $cmd = Get-Command php -ErrorAction Stop
        "$( $cmd.Source )"
    }) -and $tudoOk

    $tudoOk = (Executar-Etapa 'Node.js disponível' {
        $versao = (& node --version 2>&1 | Out-String).Trim()
        if ($LASTEXITCODE -ne 0) { throw 'Não foi possível executar o Node.js.' }
        $versao
    }) -and $tudoOk

    $tudoOk = (Executar-Etapa 'Playwright instalado' {
        $playwright = Join-Path $repo 'node_modules/playwright/cli.js'
        if (-not (Test-Path -LiteralPath $playwright)) {
            throw "Playwright não encontrado em $playwright."
        }
        $versao = (& node $playwright --version 2>&1 | Out-String).Trim()
        if ($LASTEXITCODE -ne 0) { throw 'Não foi possível validar o Playwright.' }
        $versao
    }) -and $tudoOk

    foreach ($arquivo in $ArquivosPhp) {
        $caminho = Join-Path $repo $arquivo
        $ok = Executar-Etapa "Sintaxe PHP: $arquivo" {
            if (-not (Test-Path -LiteralPath $caminho)) { throw "Arquivo não encontrado: $arquivo" }
            $saida = (& php -l $caminho 2>&1 | Out-String).Trim()
            if ($LASTEXITCODE -ne 0) { throw $saida }
            $saida
        }
        $tudoOk = $ok -and $tudoOk
    }

    foreach ($arquivo in $ArquivosJs) {
        $caminho = Join-Path $repo $arquivo
        $ok = Executar-Etapa "Sintaxe JavaScript: $arquivo" {
            if (-not (Test-Path -LiteralPath $caminho)) { throw "Arquivo não encontrado: $arquivo" }
            $saida = (& node --check $caminho 2>&1 | Out-String).Trim()
            if ($LASTEXITCODE -ne 0) { throw $saida }
            'Sintaxe válida'
        }
        $tudoOk = $ok -and $tudoOk
    }

    if (-not $IgnorarTesteVisual) {
        $nodeArgs = @(
            'scripts/teste-visual-painel-pendencias.js',
            '--url', $Url,
            '--empresa', $Empresa,
            '--usuario', $Usuario,
            '--senha', $Senha,
            '--saida', $SaidaVisual
        )
        if ($Visivel) { $nodeArgs += '--visivel' }

        $visualOk = Executar-Etapa 'Teste visual do painel de pendências' {
            & node @nodeArgs
            if ($LASTEXITCODE -ne 0) { throw "Teste visual terminou com código $LASTEXITCODE." }
            'Execução concluída'
        }
        $tudoOk = $visualOk -and $tudoOk

        $resultadoVisual = Join-Path $repo (Join-Path $SaidaVisual 'resultado.json')
        if ($visualOk) {
            $ok = Executar-Etapa 'Resultado visual aprovado' {
                if (-not (Test-Path -LiteralPath $resultadoVisual)) { throw "Resultado não encontrado: $resultadoVisual" }
                $resultado = Get-Content -LiteralPath $resultadoVisual -Raw | ConvertFrom-Json
                if ($resultado.status -ne 'APROVADO') { throw "Status visual: $($resultado.status)" }
                $falhas = @($resultado.checks | Where-Object { -not $_.ok })
                if ($falhas.Count -gt 0) { throw "$($falhas.Count) validação(ões) visual(is) falharam." }
                "$( @($resultado.checks).Count ) validações visuais aprovadas"
            }
        } else {
            Registrar-Etapa 'Resultado visual aprovado' $false 'Não validado porque o teste visual falhou.'
            $ok = $false
        }
        $tudoOk = $ok -and $tudoOk
    } else {
        Registrar-Etapa 'Teste visual do painel de pendências' $true 'Ignorado por parâmetro'
    }

    if (-not $ArquivoResultado) {
        $stamp = Get-Date -Format 'yyyyMMdd_HHmmss'
        $ArquivoResultado = "tests/validacao_completa_$stamp.json"
    }
    $resultadoCompleto = [pscustomobject]@{
        inicio = $inicio.ToString('o')
        fim = (Get-Date).ToString('o')
        status = if ($tudoOk) { 'APROVADO' } else { 'REPROVADO' }
        etapas = $etapas.ToArray()
    }
    $resultadoCompleto | ConvertTo-Json -Depth 6 | Set-Content -LiteralPath (Join-Path $repo $ArquivoResultado) -Encoding UTF8
    Write-Host "Resultado: $ArquivoResultado"

    if (-not $tudoOk) { exit 1 }
    exit 0
} finally {
    Pop-Location
}
