<#
.SYNOPSIS
    Build the Invoice Ninja Flutter admin web app and deploy it into this
    repository's public/ directory.

.DESCRIPTION
    This backend repository ships only the *compiled* Flutter admin bundle under
    public/ (main.dart.js, main.foss.dart.js, assets/, canvaskit/, ...). It does
    NOT contain the Flutter source. The admin portal is a separate project.

    The bundle in this repository reports invoiceninja_flutter 5.0.194 in
    public/version.json, which matches the admin-portal project:

        https://github.com/invoiceninja/admin-portal   (default, branch master)

    The newer rebuild at https://github.com/invoiceninja/flutter (branch main)
    is a different client. Pass -Repo and -Ref to target it instead.

    This script clones (or reuses) that project, builds the web target, copies
    the output into public/, and creates the flavor-renamed entrypoints that
    app/Http/Controllers/BaseController::setBuild() serves:

        main.foss.dart.js   self-host default
        main.wasm.dart.js   WebAssembly build
        main.last.dart.js / main.next.dart.js / main.profile.dart.js

    Requires flutter and dart on PATH (plus git when the source must be cloned).

.PARAMETER Source
    Flutter source checkout. Defaults to $env:IN_FLUTTER_SOURCE, else <repo>\..\flutter.

.PARAMETER Ref
    Git ref to clone when the source is missing. Default: main.

.PARAMETER Repo
    Git URL to clone. Default: https://github.com/invoiceninja/flutter.git

.PARAMETER Flavor
    Flavor alias(es) for the JS entrypoint. Default: foss.

.PARAMETER Wasm
    Also build the WebAssembly target (flutter build web --wasm).

.PARAMETER NoWebResourcesCdn
    Bundle CanvasKit into build\web instead of fetching it from the gstatic CDN.

.PARAMETER SkipCodegen
    Skip `dart run build_runner build`.

.PARAMETER SkipAnalyze
    Skip `flutter analyze`.

.PARAMETER Public
    Deploy target. Default: <repo>\public.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tools\build_flutter_admin.ps1

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tools\build_flutter_admin.ps1 `
        -Source C:\src\flutter-admin -Flavor foss,wasm
#>
[CmdletBinding()]
param(
    [string]$Source,
    [string]$Ref = 'master',
    [string]$Repo = 'https://github.com/invoiceninja/admin-portal.git',
    [string[]]$Flavor = @('foss'),
    [switch]$Wasm,
    [switch]$NoWebResourcesCdn,
    [switch]$SkipCodegen,
    [switch]$SkipAnalyze,
    [switch]$SkipVersionCheck,
    [string]$FlutterExe = 'flutter',
    [string]$DartExe,
    [string]$Public
)

$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot

if (-not $Source) {
    $Source = if ($env:IN_FLUTTER_SOURCE) { $env:IN_FLUTTER_SOURCE } else { Join-Path $repoRoot '..\admin-portal' }
}
if (-not $Public) {
    $Public = Join-Path $repoRoot 'public'
}

function Assert-Command([string]$Name, [string]$Hint) {
    if (-not (Get-Command $Name -ErrorAction SilentlyContinue)) {
        throw "ERROR: '$Name' is not on PATH. $Hint"
    }
}

# Resolve the Flutter and Dart executables. A full path may be supplied with
# -FlutterExe to use a side by side SDK (for example a 3.41.9 checkout) without
# changing the SDK that is first on PATH.
if ($FlutterExe -ne 'flutter') {
    if (-not (Test-Path -LiteralPath $FlutterExe)) {
        throw "ERROR: Flutter executable not found: $FlutterExe"
    }
    $FlutterExe = (Resolve-Path -LiteralPath $FlutterExe).Path
} elseif (-not (Get-Command flutter -ErrorAction SilentlyContinue)) {
    throw "ERROR: 'flutter' is not on PATH. Install the Flutter SDK or pass -FlutterExe <path to flutter.bat>."
}

if (-not $DartExe) {
    if ((Test-Path -LiteralPath $FlutterExe) -and $env:OS -eq 'Windows_NT') {
        $candidate = Join-Path (Split-Path -Parent $FlutterExe) 'dart.bat'
        $DartExe = if (Test-Path -LiteralPath $candidate) { $candidate } else { 'dart' }
    } else {
        $DartExe = 'dart'
    }
}

function Assert-WindowsDeveloperMode {
    if ($env:OS -ne 'Windows_NT') { return }

    $key = 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\AppModelUnlock'
    $enabled = $false

    if (Test-Path -LiteralPath $key) {
        $value = (Get-ItemProperty -Path $key -Name AllowDevelopmentWithoutDevLicense -ErrorAction SilentlyContinue).AllowDevelopmentWithoutDevLicense
        $enabled = ($value -eq 1)
    }

    if (-not $enabled) {
        throw @'
ERROR: Windows Developer Mode is disabled. Flutter requires symlink support to resolve plugins.

Enable it once, then re-run this script:
  1. Settings > Privacy & security > For developers > Developer Mode: On
     (or run: start ms-settings:developers)
  2. Or, from an elevated PowerShell:
     New-ItemProperty -Path "HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\AppModelUnlock" -Name AllowDevelopmentWithoutDevLicense -Value 1 -PropertyType DWORD -Force

Alternative: run this script under WSL or Linux, where symlinks are always available.
'@
    }
}

Assert-WindowsDeveloperMode

$pubspec = Join-Path $Source 'pubspec.yaml'

if (-not (Test-Path -LiteralPath $pubspec)) {
    Assert-Command 'git' 'git is required to clone the Flutter source.'
    Write-Host "==> Cloning $Repo ($Ref) into $Source"
    & git clone --depth 1 --branch $Ref $Repo $Source
    if ($LASTEXITCODE -ne 0) { throw "git clone failed with exit code $LASTEXITCODE" }
}

Write-Host "==> Flutter source: $Source"
Push-Location $Source
try {
    # admin-portal pins its SDK in .flutter-version. Building v5 with a much newer
    # Flutter fails on removed APIs (final IconData, super_editor IME changes).
    $versionFile = Join-Path $Source '.flutter-version'
    if ((Test-Path -LiteralPath $versionFile) -and (-not $SkipVersionCheck)) {
        $pinned = (Get-Content -LiteralPath $versionFile -Raw).Trim()
        $machine = & $FlutterExe --version --machine | ConvertFrom-Json
        $active = $machine.frameworkVersion
        if ($active -ne $pinned) {
            throw "Flutter version mismatch: this source requires $pinned but the active Flutter is $active. Switch SDKs (for example 'fvm use $pinned'), or pass -SkipVersionCheck to override."
        }
        Write-Host "    Flutter $active matches .flutter-version"
    }

    # Prepare the app the way the upstream pipeline does.
    $envExample = Join-Path $Source 'lib\.env.dart.example'
    $envFile = Join-Path $Source 'lib\.env.dart'
    if ((Test-Path -LiteralPath $envExample) -and (-not (Test-Path -LiteralPath $envFile))) {
        Copy-Item -LiteralPath $envExample -Destination $envFile
        Write-Host '    created lib/.env.dart from the example'
    }

    if ($Flavor -contains 'foss') {
        $fossPairs = @(
            @('lib\utils\oauth.dart.foss',           'lib\utils\oauth.dart'),
            @('lib\utils\app_review.dart.foss',      'lib\utils\app_review.dart'),
            @('lib\ui\app\upgrade_dialog.dart.foss', 'lib\ui\app\upgrade_dialog.dart'),
            @('lib\ui\app\pinput.dart.foss',         'lib\ui\app\pinput.dart'),
            @('pubspec.foss.yaml',                   'pubspec.yaml')
        )
        foreach ($pair in $fossPairs) {
            $from = Join-Path $Source $pair[0]
            $to = Join-Path $Source $pair[1]
            if (Test-Path -LiteralPath $from) { Copy-Item -LiteralPath $from -Destination $to -Force }
        }
        Write-Host '    applied FOSS sources and pubspec'
    }

    Write-Host '==> flutter pub get'
    & $FlutterExe pub get
    if ($LASTEXITCODE -ne 0) { throw "flutter pub get failed with exit code $LASTEXITCODE" }

    if (-not $SkipCodegen) {
        Write-Host '==> dart run build_runner build --delete-conflicting-outputs'
        & $DartExe run build_runner build --delete-conflicting-outputs
        if ($LASTEXITCODE -ne 0) {
            $generated = (Get-ChildItem -Recurse -File (Join-Path $Source 'lib') -Filter '*.g.dart' -ErrorAction SilentlyContinue | Measure-Object).Count
            throw @"
build_runner failed with exit code $LASTEXITCODE.

This is an upstream dependency conflict in the admin-portal project: the pinned
source_gen version is not compatible with the analyzer version that the current
Flutter SDK resolves, so the build script cannot compile.

The generated sources are committed to the repository ($generated .g.dart files
found), so codegen is optional for a normal build. Re-run with codegen skipped:

    tools\build_flutter_admin.ps1 -SkipCodegen -SkipAnalyze

Only run codegen if you changed a built_value model or another source-gen input.
In that case fix the admin-portal dependency graph first (upgrade
built_value_generator and source_gen, or override analyzer to a compatible 7.x).
"@
        }
    }

    if (-not $SkipAnalyze) {
        Write-Host '==> flutter analyze'
        & $FlutterExe analyze
        if ($LASTEXITCODE -ne 0) { throw "flutter analyze failed with exit code $LASTEXITCODE" }
    }

    $buildArgs = @('build', 'web', '--release')
    if ($Wasm) { $buildArgs += '--wasm' }
    if ($NoWebResourcesCdn) { $buildArgs += '--no-web-resources-cdn' }

    Write-Host "==> flutter $($buildArgs -join ' ')"
    & $FlutterExe @buildArgs
    if ($LASTEXITCODE -ne 0) { throw "flutter build failed with exit code $LASTEXITCODE" }
}
finally {
    Pop-Location
}

$webDir = Join-Path $Source 'build\web'
$mainJs = Join-Path $webDir 'main.dart.js'

if (-not (Test-Path -LiteralPath $webDir)) { throw "ERROR: build output not found: $webDir" }
if (-not (Test-Path -LiteralPath $mainJs)) { throw "ERROR: missing $mainJs" }
if (-not (Test-Path -LiteralPath $Public)) { throw "ERROR: deploy target not found: $Public" }

$stamp = (Get-Date).ToUniversalTime().ToString('yyyyMMdd-HHmmss')
$backupDir = Join-Path $repoRoot "storage\app\flutter-admin-backup-$stamp"
New-Item -ItemType Directory -Force -Path $backupDir | Out-Null

Write-Host "==> Backing up existing Flutter entrypoints into $backupDir"
Get-ChildItem -LiteralPath $Public -Filter 'main.*.dart.js' -File -ErrorAction SilentlyContinue |
    Copy-Item -Destination $backupDir -Force -ErrorAction SilentlyContinue
if (Test-Path (Join-Path $Public 'version.json')) {
    Copy-Item -LiteralPath (Join-Path $Public 'version.json') -Destination $backupDir -Force
}

Write-Host "==> Deploying build\web into $Public (index.html excluded)"
Get-ChildItem -LiteralPath $webDir -Force |
    Where-Object { $_.Name -ne 'index.html' } |
    ForEach-Object {
        Copy-Item -LiteralPath $_.FullName -Destination $Public -Recurse -Force
    }

Write-Host '==> Creating flavor entrypoints from main.dart.js'
foreach ($name in $Flavor) {
    $target = Join-Path $Public "main.$name.dart.js"
    Copy-Item -LiteralPath $mainJs -Destination $target -Force
    Write-Host "    main.$name.dart.js"
}

if ($Wasm) {
    Write-Host '==> WebAssembly outputs produced by this SDK:'
    Get-ChildItem -LiteralPath $webDir -Filter 'main.dart.*' -File -ErrorAction SilentlyContinue |
        ForEach-Object { Write-Host "    $($_.Name)" }
    Write-Host '    NOTE: the backend serves main.wasm.dart.js (legacy name). If this'
    Write-Host '          SDK emits main.dart.wasm / main.dart.mjs instead, wire that'
    Write-Host '          entrypoint through BaseController::setBuild(), or keep using'
    Write-Host '          the JS flavor above.'
}

Write-Host '==> Done.'
Write-Host "    Backend: $repoRoot"
Write-Host "    Public:  $Public"
Write-Host "    Backups: $backupDir"
Write-Host '    Hard-refresh the admin UI (or append ?build=foss) to load the new bundle.'
