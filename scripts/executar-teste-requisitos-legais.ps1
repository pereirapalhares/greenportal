[CmdletBinding()]
param(
    [string]$Url = 'http://localhost/greenportal',
    [Parameter(Mandatory)][string]$Empresa,
    [Parameter(Mandatory)][string]$Usuario,
    [Parameter(Mandatory)][string]$Senha,
    [switch]$Visivel,
    [switch]$EsperarSemAcesso,
    [string]$Saida = 'tests/evidencias_requisitos_legais',
    [string]$ArquivoResultado = ''
)

$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $repo
$etapas = New-Object System.Collections.Generic.List[object]
$inicio = Get-Date

function Etapa {
    param([string]$Nome, [scriptblock]$Acao)
    try {
        $detalhes = & $Acao
        $etapas.Add([pscustomobject]@{ nome = $Nome; sucesso = $true; detalhes = [string]($detalhes -join ' ') })
        Write-Host "PASS - $Nome" -ForegroundColor Green
        return $true
    } catch {
        $etapas.Add([pscustomobject]@{ nome = $Nome; sucesso = $false; detalhes = $_.Exception.Message })
        Write-Host "FAIL - $Nome : $($_.Exception.Message)" -ForegroundColor Red
        return $false
    }
}

try {
    $ok = $true
    $ok = (Etapa 'PHP disponível' { (Get-Command php -ErrorAction Stop).Source }) -and $ok
    $ok = (Etapa 'Node.js disponível' { $v = (& node --version 2>&1 | Out-String).Trim(); if ($LASTEXITCODE -ne 0) { throw 'Node.js indisponível.' }; $v }) -and $ok
    $ok = (Etapa 'Playwright instalado' { $cli = Join-Path $repo 'node_modules/playwright/cli.js'; if (-not (Test-Path $cli)) { throw 'Playwright não encontrado.' }; (& node $cli --version 2>&1 | Out-String).Trim() }) -and $ok

    foreach ($arquivo in @(
        'principal/modulolegal_requisitoslegais.php',
        'principal/manutencaolegalrequisitoslegais_situacao.php',
        'principal/manutencaolegalrequisitoslegais_licencas.php',
        'principal/manutencaolegalrequisitoslegais_controles.php',
        'principal/manutencaolegalrequisitoslegais_medicaomonitoramento.php',
        'principal/manutencaolegalrequisitoslegais_checklist.php',
        'principal/manutencaolegalrequisitoslegais_alteracoes.php',
        'principal/requisito_responsabilidades.php',
        'principal/monitoramento_responsabilidades.php',
        'classes/ModuloLegalRequisitosGrid.php',
        'classes/ModuloLegalSituacaoGrid.php'
    )) {
        $path = Join-Path $repo $arquivo
        $ok = (Etapa "Sintaxe PHP: $arquivo" { $out = (& php -l $path 2>&1 | Out-String).Trim(); if ($LASTEXITCODE -ne 0) { throw $out }; $out }) -and $ok
    }
    foreach ($arquivo in @(
        'assets/js/modulo-legal-requisitos.js',
        'assets/js/requisitos-responsabilidades.js',
        'assets/js/medicao-monitoramento-responsabilidades.js',
        'assets/js/grid-pagination.js',
        'assets/js/grid-loading.js',
        'scripts/teste-visual-requisitos-legais.js'
    )) {
        $path = Join-Path $repo $arquivo
        $ok = (Etapa "Sintaxe JavaScript: $arquivo" { $out = (& node --check $path 2>&1 | Out-String).Trim(); if ($LASTEXITCODE -ne 0) { throw $out }; 'Sintaxe válida' }) -and $ok
    }

    $nodeArgs = @('scripts/teste-visual-requisitos-legais.js', '--url', $Url, '--empresa', $Empresa, '--usuario', $Usuario, '--senha', $Senha, '--saida', $Saida)
    if ($Visivel) { $nodeArgs += '--visivel' }
    if ($EsperarSemAcesso) { $nodeArgs += '--expect-no-access' }
    $visualOk = Etapa 'Teste visual e funcional de Requisitos Legais' {
        & node @nodeArgs
        if ($LASTEXITCODE -ne 0) { throw "Teste visual terminou com código $LASTEXITCODE." }
        'Execução concluída'
    }
    $ok = $visualOk -and $ok

    $visualResult = Join-Path $repo (Join-Path $Saida 'resultado.json')
    $resultOk = Etapa 'Resultado de Requisitos Legais aprovado' {
        if (-not (Test-Path $visualResult)) { throw "Resultado não encontrado: $visualResult" }
        $r = Get-Content $visualResult -Raw | ConvertFrom-Json
        if ($r.status -ne 'APROVADO') { throw "Status visual: $($r.status)" }
        $fails = @($r.checks | Where-Object { -not $_.ok })
        if ($fails.Count) { throw "$($fails.Count) verificação(ões) falharam." }
        "$(@($r.checks).Count) verificações aprovadas"
    }
    $ok = $resultOk -and $ok

    if (-not $ArquivoResultado) { $ArquivoResultado = "tests/validacao_requisitos_legais_$(Get-Date -Format 'yyyyMMdd_HHmmss').json" }
    [pscustomobject]@{ inicio = $inicio.ToString('o'); fim = (Get-Date).ToString('o'); status = if ($ok) { 'APROVADO' } else { 'REPROVADO' }; etapas = $etapas.ToArray() } |
        ConvertTo-Json -Depth 6 | Set-Content -LiteralPath (Join-Path $repo $ArquivoResultado) -Encoding UTF8
    Write-Host "Resultado: $ArquivoResultado"
    if (-not $ok) { exit 1 }
    exit 0
} finally {
    Pop-Location
}
