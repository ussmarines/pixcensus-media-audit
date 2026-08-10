param(
	[Parameter(Mandatory = $true)]
	[string] $ZipPath,
	[Parameter(Mandatory = $true)]
	[ValidatePattern('^\d+\.\d+\.\d+$')]
	[string] $Version,
	[string] $ExpectedSha256 = '',
	[string] $ChecksumPath = ''
)
$ErrorActionPreference = 'Stop'
if (-not (Test-Path -LiteralPath $ZipPath -PathType Leaf)) { throw "ZIP not found: $ZipPath" }
if ([string]::IsNullOrWhiteSpace($ChecksumPath)) { $ChecksumPath = $ZipPath + '.sha256' }
if ([string]::IsNullOrWhiteSpace($ExpectedSha256) -and (Test-Path -LiteralPath $ChecksumPath -PathType Leaf)) {
	$ExpectedSha256 = (((Get-Content -LiteralPath $ChecksumPath -Raw).Trim()) -split '\s+')[0]
}
$actualHash = (Get-FileHash -LiteralPath $ZipPath -Algorithm SHA256).Hash.ToLowerInvariant()
if (-not [string]::IsNullOrWhiteSpace($ExpectedSha256) -and $actualHash -ne $ExpectedSha256.ToLowerInvariant()) { throw 'Checksum mismatch.' }
$temp = Join-Path ([System.IO.Path]::GetTempPath()) ('pixcensus-submit-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $temp | Out-Null
try {
	Expand-Archive -LiteralPath $ZipPath -DestinationPath $temp -Force
	$root = Join-Path $temp 'pixcensus-media-audit'
	$mainFile = Join-Path $root 'pixcensus-media-audit.php'
	$readme = Join-Path $root 'readme.txt'
	$brandMark = Join-Path $root 'assets/pixcensus-media-audit-mark.svg'
	foreach ($requiredPath in @($root, $mainFile, $readme, $brandMark)) { if (-not (Test-Path -LiteralPath $requiredPath)) { throw "Missing: $requiredPath" } }
	$escapedVersion = [regex]::Escape($Version)
	$mainContent = Get-Content -LiteralPath $mainFile -Raw
	$readmeContent = Get-Content -LiteralPath $readme -Raw
	if ($mainContent -notmatch "(?m)^\s*\*\s*Version:\s*$escapedVersion\s*$") { throw "Plugin header version $Version not found." }
	if ($mainContent -notmatch "define\(\s*'PIXCENSUS_VERSION'\s*,\s*'$escapedVersion'\s*\)") { throw "PIXCENSUS_VERSION $Version not found." }
	if ($mainContent -notmatch "(?m)^\s*\*\s*Text Domain:\s*pixcensus-media-audit\s*$") { throw 'Text Domain not found.' }
	if ($readmeContent -notmatch "(?m)^Stable tag:\s*$escapedVersion\s*$") { throw "Stable tag $Version not found." }
	$forbiddenNames = @('.git','.github','.agents','.codex','.security','.wordpress-org','node_modules','vendor','tests','scripts','docs','dist')
	foreach ($name in $forbiddenNames) {
		if (Get-ChildItem -LiteralPath $root -Recurse -Force | Where-Object { $_.Name -eq $name }) { throw "Development-only entry: $name" }
	}
	if (Get-ChildItem -LiteralPath $root -Recurse -File -Filter '*.zip') { throw 'Nested ZIP detected.' }
	Write-Host "Validation passed. SHA-256: $actualHash" -ForegroundColor Green
}
finally { if (Test-Path -LiteralPath $temp) { Remove-Item -LiteralPath $temp -Recurse -Force } }
