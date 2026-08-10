param(
	[string] $OutputPath = 'dist/pixcensus-media-audit.zip'
)

$ErrorActionPreference = 'Stop'

$repoRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$repoPrefix = [System.IO.Path]::TrimEndingDirectorySeparator($repoRoot) + [System.IO.Path]::DirectorySeparatorChar
$outputFullPath = [System.IO.Path]::GetFullPath((Join-Path $repoRoot $OutputPath))
$checksumFullPath = $outputFullPath + '.sha256'

if (-not $outputFullPath.StartsWith($repoPrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
	throw 'The ZIP output path must stay inside the repository.'
}

$tempRoot = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath())
$tempPrefix = [System.IO.Path]::TrimEndingDirectorySeparator($tempRoot) + [System.IO.Path]::DirectorySeparatorChar
$stagingBase = [System.IO.Path]::GetFullPath((Join-Path $tempRoot ('pixcensus-package-' + [System.Guid]::NewGuid().ToString('N'))))

if (-not $stagingBase.StartsWith($tempPrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
	throw 'The staging directory must stay inside the system temporary directory.'
}

$packageRoot = Join-Path $stagingBase 'pixcensus-media-audit'
$runtimeFiles = @(
	'pixcensus-media-audit.php',
	'uninstall.php',
	'readme.txt',
	'LICENSE'
)
$runtimeDirectories = @(
	'assets',
	'includes',
	'languages',
	'views'
)

foreach ($relativePath in @($runtimeFiles + $runtimeDirectories)) {
	$sourcePath = Join-Path $repoRoot $relativePath
	$sourceItems = if (Test-Path -LiteralPath $sourcePath -PathType Container) {
		@(Get-Item -LiteralPath $sourcePath -Force) + @(Get-ChildItem -LiteralPath $sourcePath -Recurse -Force)
	} else {
		@(Get-Item -LiteralPath $sourcePath -Force)
	}

	if ($sourceItems | Where-Object { $_.Attributes -band [System.IO.FileAttributes]::ReparsePoint }) {
		throw "Unexpected symlink or reparse point in runtime source: $relativePath"
	}
}

try {
	New-Item -ItemType Directory -Path $packageRoot -Force | Out-Null

	foreach ($relativePath in $runtimeFiles) {
		Copy-Item -LiteralPath (Join-Path $repoRoot $relativePath) -Destination (Join-Path $packageRoot $relativePath)
	}

	foreach ($relativePath in $runtimeDirectories) {
		Copy-Item -LiteralPath (Join-Path $repoRoot $relativePath) -Destination (Join-Path $packageRoot $relativePath) -Recurse
	}

	$fixedTimestamp = [System.DateTime]::SpecifyKind([System.DateTime]'2000-01-01T00:00:00', [System.DateTimeKind]::Utc)
	Get-ChildItem -LiteralPath $stagingBase -Recurse -File | ForEach-Object { $_.LastWriteTimeUtc = $fixedTimestamp }

	$outputDirectory = Split-Path -Parent $outputFullPath
	New-Item -ItemType Directory -Path $outputDirectory -Force | Out-Null

	if (Test-Path -LiteralPath $outputFullPath) {
		Remove-Item -LiteralPath $outputFullPath -Force
	}
	if (Test-Path -LiteralPath $checksumFullPath) {
		Remove-Item -LiteralPath $checksumFullPath -Force
	}

	Compress-Archive -LiteralPath $packageRoot -DestinationPath $outputFullPath -CompressionLevel Optimal

	Add-Type -AssemblyName System.IO.Compression.FileSystem
	$archive = [System.IO.Compression.ZipFile]::OpenRead($outputFullPath)

	try {
		$entries = @($archive.Entries | ForEach-Object { $_.FullName.Replace('\', '/') })
		$required = @(
			'pixcensus-media-audit/pixcensus-media-audit.php',
			'pixcensus-media-audit/readme.txt',
			'pixcensus-media-audit/LICENSE',
			'pixcensus-media-audit/uninstall.php'
		)

		foreach ($archiveEntry in $archive.Entries) {
			$entry = $archiveEntry.FullName.Replace('\', '/')
			if (-not $entry.StartsWith('pixcensus-media-audit/', [System.StringComparison]::Ordinal)) {
				throw "Unexpected ZIP root entry: $entry"
			}

			if ($entry -match '(^|/)(\.git|\.github|\.agents|\.codex|\.security|\.wordpress-org|docs|tests|node_modules|vendor|scripts|dist)(/|$)') {
				throw "Development-only ZIP entry: $entry"
			}

			if ($entry -match '(^|/)(?:\.env(?:\..*)?|composer\.(?:json|lock)|package(?:-lock)?\.json|phpcs\.xml(?:\.dist)?|phpstan\.neon(?:\.dist)?|phpunit\.xml(?:\.dist)?|.*\.(?:zip|log|tmp|bak))$') {
				throw "Forbidden release file: $entry"
			}

			$externalAttributes = [uint32] ( ([int64] $archiveEntry.ExternalAttributes) -band 0xFFFFFFFFL )
			$unixFileType = (($externalAttributes -shr 16) -band 0xF000)
			if (0xA000 -eq $unixFileType) {
				throw "Unexpected symlink in ZIP: $entry"
			}
		}

		foreach ($entry in $required) {
			if ($entries -notcontains $entry) {
				throw "Missing required ZIP entry: $entry"
			}
		}

		$mainEntry = $archive.GetEntry('pixcensus-media-audit/pixcensus-media-audit.php')
		$reader = [System.IO.StreamReader]::new($mainEntry.Open())
		try { $mainContent = $reader.ReadToEnd() } finally { $reader.Dispose() }

		$versionMatch = [System.Text.RegularExpressions.Regex]::Match($mainContent, '(?m)^\s*\*\s*Version:\s*(\d+\.\d+\.\d+)\s*$')

		if (-not $versionMatch.Success -or $mainContent -notmatch 'License:\s+GPL-2\.0-or-later') {
			throw 'Plugin version or license metadata is missing from the ZIP.'
		}

		$pluginVersion = $versionMatch.Groups[1].Value
		$escapedVersion = [System.Text.RegularExpressions.Regex]::Escape($pluginVersion)

		if ($mainContent -notmatch "define\(\s*'PIXCENSUS_VERSION'\s*,\s*'$escapedVersion'\s*\)" ) {
			throw 'The plugin header and PIXCENSUS_VERSION are inconsistent in the ZIP.'
		}

		$readmeEntry = $archive.GetEntry('pixcensus-media-audit/readme.txt')
		$reader = [System.IO.StreamReader]::new($readmeEntry.Open())
		try { $readmeContent = $reader.ReadToEnd() } finally { $reader.Dispose() }

		if ($readmeContent -notmatch "(?m)^Stable tag:\s*$escapedVersion\s*$") {
			throw 'The plugin header and readme stable tag are inconsistent in the ZIP.'
		}
	} finally {
		$archive.Dispose()
	}

	$sha256 = (Get-FileHash -LiteralPath $outputFullPath -Algorithm SHA256).Hash.ToLowerInvariant()
	$checksumLine = "$sha256  $([System.IO.Path]::GetFileName($outputFullPath))`n"
	[System.IO.File]::WriteAllText($checksumFullPath, $checksumLine, [System.Text.UTF8Encoding]::new($false))

	[pscustomobject]@{
		zip = $outputFullPath
		checksum = $checksumFullPath
		sha256 = $sha256
		entries = $entries.Count
		root = 'pixcensus-media-audit/'
		result = 'pass'
	} | ConvertTo-Json -Compress
} finally {
	if (Test-Path -LiteralPath $stagingBase) {
		Remove-Item -LiteralPath $stagingBase -Recurse -Force
	}
}
