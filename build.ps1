# Builds the installable zip: plg_system_sgcache_v<version>_<yyyyMMdd>_<HHmm>.zip
# and a clean-named copy plg_system_sgcache_v<version>.zip for release.
#
#   powershell -ExecutionPolicy Bypass -File build.ps1
#
# Stages only what ships (no tests/, no .git), refuses to build without both
# changelogs, writes integrity.json into the staged copy with Joomla-Brain's
# New-IntegrityList.ps1, then zips with 7-Zip.

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot

[xml]$manifest = Get-Content (Join-Path $root 'sgcache.xml')
$version = $manifest.extension.version

foreach ($f in 'CHANGELOG.md', 'CHANGELOG.html', 'changelog.xml') {
    if (-not (Test-Path (Join-Path $root $f))) { throw "$f is missing: every release ships its changelogs." }
}
if (-not (Select-String -Path (Join-Path $root 'changelog.xml') -Pattern "<version>$([regex]::Escape($version))</version>" -Quiet)) {
    throw "changelog.xml has no entry for $version."
}
foreach ($f in 'CHANGELOG.md', 'CHANGELOG.html') {
    if (-not (Select-String -Path (Join-Path $root $f) -Pattern "Version $([regex]::Escape($version))\b" -Quiet)) {
        throw "$f has no entry for $version."
    }
}

$integrityTool = @(
    (Join-Path $root '.joomla-brain\tools\integrity\New-IntegrityList.ps1'),
    (Join-Path (Split-Path $root -Parent) 'Joomla-Brain\tools\integrity\New-IntegrityList.ps1')
) | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $integrityTool) { throw 'Joomla-Brain tools/integrity not found.' }

$sevenZip = 'C:\Program Files\7-Zip\7z.exe'
if (-not (Test-Path $sevenZip)) { throw '7-Zip not found.' }

$stage = Join-Path ([IO.Path]::GetTempPath()) ("sgcache-build-" + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $stage | Out-Null
try {
    foreach ($item in 'sgcache.xml', 'script.php', 'CHANGELOG.md', 'CHANGELOG.html', 'changelog.xml', 'LICENSE', 'NOTICE',
                      'services', 'src', 'tmpl', 'forms', 'language', 'media') {
        $src = Join-Path $root $item
        if (-not (Test-Path $src)) { throw "$item is missing." }
        Copy-Item $src -Destination $stage -Recurse
    }

    & $integrityTool -ExtensionDir $stage
    if (-not (Test-Path (Join-Path $stage 'integrity.json'))) { throw 'integrity.json was not written.' }

    $stamp = Get-Date -Format 'yyyyMMdd_HHmm'
    $zip   = Join-Path $root "plg_system_sgcache_v${version}_$stamp.zip"
    $clean = Join-Path $root "plg_system_sgcache_v$version.zip"
    foreach ($z in $zip, $clean) { if (Test-Path $z) { Remove-Item $z -Force } }

    Push-Location $stage
    & $sevenZip a -tzip -mx=9 $zip * | Out-Null
    Pop-Location
    if ($LASTEXITCODE -ne 0) { throw "7-Zip failed ($LASTEXITCODE)." }

    Copy-Item $zip $clean
    $hash = (Get-FileHash $clean -Algorithm SHA256).Hash.ToLower()
    $size = (Get-Item $clean).Length
    Write-Host "Built $zip"
    Write-Host "Clean copy $clean ($size bytes)"
    Write-Host "sha256 $hash"
}
finally {
    Remove-Item $stage -Recurse -Force -ErrorAction SilentlyContinue
}
