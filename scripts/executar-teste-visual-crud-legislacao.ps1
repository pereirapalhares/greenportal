[CmdletBinding()]
param(
    [string]$Url = 'http://localhost/greenportal',
    [Parameter(Mandatory)][string]$Empresa,
    [Parameter(Mandatory)][string]$Usuario,
    [Parameter(Mandatory)][string]$Senha,
    [switch]$Visivel,
    [string]$Saida = 'tests/evidencias_crud_visual_legislacao'
)
$ErrorActionPreference = 'Stop'; $repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path; Push-Location $repo
try {
    node --check scripts/teste-visual-crud-legislacao.js
    if ($LASTEXITCODE -ne 0) { throw 'Sintaxe JavaScript inválida.' }
    $nodeArgs = @('scripts/teste-visual-crud-legislacao.js','--url',$Url,'--empresa',$Empresa,'--usuario',$Usuario,'--senha',$Senha,'--saida',$Saida)
    if ($Visivel) { $nodeArgs += '--visivel' }
    & node @nodeArgs
    if ($LASTEXITCODE -ne 0) { throw 'Teste visual CRUD de legislação terminou com erro.' }
} finally { Pop-Location }
