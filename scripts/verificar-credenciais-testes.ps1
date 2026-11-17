[CmdletBinding()]
param([string]$Root = '')
if ([string]::IsNullOrWhiteSpace($Root)) { $Root = Split-Path -Parent $PSScriptRoot }
$patterns = @($env:GREEN_CROWN_TEST_PASSWORD, ('admin' + '1234')) | Where-Object { -not [string]::IsNullOrWhiteSpace($_) }
$roots = @('scripts','tests','docs') | ForEach-Object { Join-Path $Root $_ }
$targets = Get-ChildItem -LiteralPath $roots -Recurse -File -ErrorAction SilentlyContinue | Where-Object { $_.FullName -ne $PSCommandPath -and $_.Extension -in '.js','.ps1','.php','.txt','.md','.json' }
$findings = foreach ($file in $targets) {
    $content = [System.IO.File]::ReadAllText($file.FullName)
    foreach ($pattern in $patterns) { if ($content.Contains($pattern)) { [pscustomobject]@{ File=$file.FullName; Pattern=$pattern } } }
}
$result = [pscustomobject]@{ ok = ($findings.Count -eq 0); findings = @($findings); checked_files = $targets.Count; generated_at = (Get-Date).ToUniversalTime().ToString('o') }
$result | ConvertTo-Json -Depth 4
if (-not $result.ok) { exit 1 }
