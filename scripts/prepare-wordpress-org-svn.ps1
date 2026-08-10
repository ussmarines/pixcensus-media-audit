param(
	[Parameter(Mandatory = $true)][string] $PluginZip,
	[Parameter(Mandatory = $true)][string] $WorkDir,
	[Parameter(Mandatory = $true)][string] $AssetsDir,
	[string] $Slug = 'pixcensus-media-audit',
	[Parameter(Mandatory = $true)][ValidatePattern('^\d+\.\d+\.\d+$')][string] $Version,
	[string] $WordPressUser = 'ussmarines',
	[switch] $ValidateOnly,
	[switch] $Commit
)

$ErrorActionPreference = 'Stop'

function Get-DirectoryAssetMetadata {
	param([Parameter(Mandatory = $true)][System.IO.FileInfo] $File)

	if ('icon.svg' -eq $File.Name) {
		$svgContent = Get-Content -LiteralPath $File.FullName -Raw
		if ($svgContent -notmatch '(?is)^\s*(?:<\?xml[^>]*>\s*)?<svg\b') {
			throw "Invalid SVG content: $($File.Name)"
		}

		return [pscustomobject]@{
			File = $File
			MimeType = 'image/svg+xml'
			Width = $null
			Height = $null
		}
	}

	Add-Type -AssemblyName System.Drawing.Common
	$image = [System.Drawing.Image]::FromFile($File.FullName)

	try {
		$extension = $File.Extension.ToLowerInvariant()
		$expectedFormat = if ('.png' -eq $extension) { [System.Drawing.Imaging.ImageFormat]::Png.Guid } else { [System.Drawing.Imaging.ImageFormat]::Jpeg.Guid }
		$mimeType = if ('.png' -eq $extension) { 'image/png' } else { 'image/jpeg' }

		if ($image.RawFormat.Guid -ne $expectedFormat) {
			throw "Image content does not match its extension: $($File.Name)"
		}

		$expectedDimensions = @{
			'banner-772x250' = @(772, 250)
			'banner-1544x500' = @(1544, 500)
			'icon-128x128' = @(128, 128)
			'icon-256x256' = @(256, 256)
		}

		if ($expectedDimensions.ContainsKey($File.BaseName)) {
			$expected = $expectedDimensions[$File.BaseName]
			if ($image.Width -ne $expected[0] -or $image.Height -ne $expected[1]) {
				throw "Unexpected dimensions for $($File.Name): $($image.Width)x$($image.Height)"
			}
		} elseif ($image.Width -lt 1 -or $image.Height -lt 1) {
			throw "Invalid screenshot dimensions: $($File.Name)"
		}

		return [pscustomobject]@{
			File = $File
			MimeType = $mimeType
			Width = $image.Width
			Height = $image.Height
		}
	}
	finally {
		$image.Dispose()
	}
}

if (-not $ValidateOnly -and -not (Get-Command svn -ErrorAction SilentlyContinue)) { throw 'The svn command is required.' }
if (-not (Test-Path -LiteralPath $PluginZip -PathType Leaf)) { throw "Plugin ZIP not found: $PluginZip" }
if (-not (Test-Path -LiteralPath $AssetsDir -PathType Container)) { throw "Assets directory not found: $AssetsDir" }
if (Test-Path -LiteralPath $WorkDir) { throw "Working directory already exists: $WorkDir" }

$assetNamePattern = '^(?:banner-(?:772x250|1544x500)|icon-(?:128x128|256x256))\.(?:png|jpg)$|^icon\.svg$|^screenshot-[1-9][0-9]*\.(?:png|jpg)$'
$requiredAssets = @(
	'^banner-772x250\.(?:png|jpg)$',
	'^banner-1544x500\.(?:png|jpg)$',
	'^icon-128x128\.(?:png|jpg)$',
	'^icon-256x256\.(?:png|jpg)$',
	'^icon\.svg$'
)
$publicAssets = @(Get-ChildItem -LiteralPath $AssetsDir -File | Where-Object { $_.Name -cmatch $assetNamePattern } | Sort-Object Name)

foreach ($requiredAsset in $requiredAssets) {
	$matchingAssets = @($publicAssets | Where-Object { $_.Name -cmatch $requiredAsset })
	if (1 -ne $matchingAssets.Count) { throw "Expected exactly one WordPress.org asset matching: $requiredAsset" }
}

$assetMetadata = @($publicAssets | ForEach-Object { Get-DirectoryAssetMetadata -File $_ })
$svnUrl = "https://plugins.svn.wordpress.org/$Slug"
$temp = Join-Path ([System.IO.Path]::GetTempPath()) ('pixcensus-svn-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $temp | Out-Null

try {
	Expand-Archive -LiteralPath $PluginZip -DestinationPath $temp -Force
	$topLevelEntries = @(Get-ChildItem -LiteralPath $temp -Force)
	if ($topLevelEntries.Count -ne 1 -or $topLevelEntries[0].Name -cne $Slug -or -not $topLevelEntries[0].PSIsContainer) {
		throw "The ZIP must contain exactly one root directory named $Slug."
	}

	$pluginRoot = Join-Path $temp $Slug
	$mainFile = Join-Path $pluginRoot 'pixcensus-media-audit.php'
	$readmeFile = Join-Path $pluginRoot 'readme.txt'
	if (-not (Test-Path -LiteralPath $mainFile -PathType Leaf) -or -not (Test-Path -LiteralPath $readmeFile -PathType Leaf)) {
		throw 'The ZIP is missing its main plugin file or readme.txt.'
	}

	$mainContent = Get-Content -LiteralPath $mainFile -Raw
	$readmeContent = Get-Content -LiteralPath $readmeFile -Raw
	$escapedVersion = [regex]::Escape($Version)
	if ($mainContent -notmatch "(?m)^\s*\*\s*Version:\s*$escapedVersion\s*$") { throw "Plugin header version does not match $Version." }
	if ($mainContent -notmatch "define\(\s*'PIXCENSUS_VERSION'\s*,\s*'$escapedVersion'\s*\)") { throw "PIXCENSUS_VERSION does not match $Version." }
	if ($readmeContent -notmatch "(?m)^Stable tag:\s*$escapedVersion\s*$") { throw "readme.txt Stable tag does not match $Version." }

	if ($ValidateOnly) {
		[pscustomobject]@{
			result = 'pass'
			version = $Version
			zipRoot = "$Slug/"
			publicAssets = @($assetMetadata | ForEach-Object { [pscustomobject]@{ name = $_.File.Name; mimeType = $_.MimeType; width = $_.Width; height = $_.Height } })
		} | ConvertTo-Json -Depth 4 -Compress
		return
	}

	svn checkout $svnUrl $WorkDir
	$trunk = Join-Path $WorkDir 'trunk'
	$tags = Join-Path $WorkDir 'tags'
	$rootAssets = Join-Path $WorkDir 'assets'
	New-Item -ItemType Directory -Path $trunk -Force | Out-Null
	New-Item -ItemType Directory -Path $tags -Force | Out-Null
	New-Item -ItemType Directory -Path $rootAssets -Force | Out-Null
	Copy-Item -Path (Join-Path $pluginRoot '*') -Destination $trunk -Recurse -Force

	foreach ($asset in $assetMetadata) {
		Copy-Item -LiteralPath $asset.File.FullName -Destination (Join-Path $rootAssets $asset.File.Name) -Force
	}

	Push-Location $WorkDir
	try {
		svn add trunk --force
		svn add assets --force

		foreach ($asset in $assetMetadata) {
			svn propset svn:mime-type $asset.MimeType (Join-Path $rootAssets $asset.File.Name)
		}

		if (Test-Path -LiteralPath (Join-Path $tags $Version)) { throw "Tag already exists: $Version" }
		svn copy trunk ("tags/" + $Version)
		svn status

		if ($Commit) {
			if ((Read-Host 'Type PUBLISH to commit') -ne 'PUBLISH') { throw 'SVN publication cancelled.' }
			svn commit -m "Publish PixCensus — Media Usage Audit $Version" --username $WordPressUser
		} else {
			Write-Host 'No SVN commit performed. Review svn status and svn diff.' -ForegroundColor Green
		}
	}
	finally {
		Pop-Location
	}
}
finally {
	if (Test-Path -LiteralPath $temp) { Remove-Item -LiteralPath $temp -Recurse -Force }
}
