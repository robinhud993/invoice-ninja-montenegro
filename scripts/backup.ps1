<#
.SYNOPSIS
    Back up the Invoice Ninja Docker stack into one portable archive.

.DESCRIPTION
    Runs the opt-in backup service (docker/backup) once. The service writes a
    timestamped directory under backups\ with one MySQL dump per database, the
    application storage and the deployment configuration, plus SHA256SUMS and a
    manifest. This script verifies the checksums and then bundles the backup
    together with .env.docker into a single .tar.gz (or .zip) file that can be
    copied to another Windows machine and restored with restore.ps1.

.PARAMETER EnvFile
    Environment file to use. Default: .env.docker in the repository root.

.PARAMETER OutputDir
    Directory for the portable bundle. Default: exports in the repository root.

.PARAMETER NoBundle
    Only create the local backup under backups\, do not build the portable
    archive.

.PARAMETER NoStorage
    Back up the databases and the configuration only. The application storage
    (uploads, documents, logs) is skipped, which makes the backup small and
    fast. Docker images are never included in a backup.

.PARAMETER IncludeCode
    Build a self contained bundle: the application source code, the databases,
    the configuration and the storage. A new machine then only needs Docker to
    rebuild the images and run the service. Docker images are not bundled; they
    are rebuilt from the included Dockerfile, or you can export them separately
    with `docker save` for a fully offline install.

.PARAMETER IncludeImages
    Export the built Docker images with `docker save` into exports\images\.
    They are written next to the bundle, not inside it, because they are large
    (several GB). Copy them alongside the bundle for an offline install and load
    them on the new machine with `docker load`.

.PARAMETER SkipVerify
    Skip the SHA256SUMS verification of the new backup.

.PARAMETER List
    List the local backups and exit.

.PARAMETER TimeoutSeconds
    How long to wait for the database service to become healthy. Default: 300.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\backup.ps1

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\backup.ps1 -NoBundle

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\backup.ps1 -List
#>
[CmdletBinding()]
param(
    [string]$EnvFile = '.env.docker',
    [string]$OutputDir,
    [switch]$NoBundle,
    [switch]$NoStorage,
    [switch]$IncludeCode,
    [switch]$IncludeImages,
    [switch]$SkipVerify,
    [switch]$List,
    [int]$TimeoutSeconds = 300
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'common.ps1')

$repoRoot = Split-Path -Parent $PSScriptRoot
$envPath = Resolve-EnvPath -RepoRoot $repoRoot -EnvFile $EnvFile
$backupDir = Join-Path $repoRoot 'backups'

if (-not $OutputDir) {
    $OutputDir = Join-Path $repoRoot 'exports'
}
elseif (-not [System.IO.Path]::IsPathRooted($OutputDir)) {
    $OutputDir = Join-Path $repoRoot $OutputDir
}

Push-Location $repoRoot
try {
    if ($List) {
        if (-not (Test-Path -LiteralPath $backupDir)) {
            Write-Note "no backups found in $backupDir"
            return
        }

        $found = $false
        Get-ChildItem -LiteralPath $backupDir -Directory | Sort-Object Name -Descending | ForEach-Object {
            $found = $true
            $manifest = Join-Path $_.FullName 'manifest.txt'
            $size = (Get-ChildItem -LiteralPath $_.FullName -File | Measure-Object -Property Length -Sum).Sum
            $created = ''
            if (Test-Path -LiteralPath $manifest) {
                $created = Read-EnvValue -Path $manifest -Key 'created_at_utc'
            }
            Write-Host ("{0}  {1,8:N1} MB  {2}" -f $_.Name, ($size / 1MB), $created)
        }

        if (-not $found) { Write-Note "no backups found in $backupDir" }
        return
    }

    Write-Step 'Checking Docker'
    Assert-Docker -StartDockerDesktop
    $composeVersion = Assert-ComposeV2
    Write-Note $composeVersion

    if (-not (Test-Path -LiteralPath $envPath)) {
        throw "ERROR: $envPath does not exist. Run scripts\deploy.ps1 first."
    }

    New-Item -ItemType Directory -Force -Path $backupDir | Out-Null

    Write-Step 'Starting the database service'
    Invoke-Compose -EnvFile $envPath -Arguments @('up', '-d', 'db') | Out-Null
    Wait-ForServiceHealthy -EnvFile $envPath -Service 'db' -TimeoutSeconds $TimeoutSeconds

    if (-not (Test-ImageExists -Name 'invoiceninja-backup:latest')) {
        Write-Step 'Building the backup image'
        Invoke-Compose -EnvFile $envPath -Arguments @('--profile', 'backup', 'build', 'backup') | Out-Null
    }

    Write-Step 'Running the backup'
    $runArgs = @('--profile', 'backup', 'run', '--rm', '-T')
    if ($NoStorage) {
        # Databases and configuration only. Docker images are never part of a
        # backup; this only drops the application storage archive.
        $runArgs += @('-e', 'BACKUP_INCLUDE_STORAGE=0')
    }
    $runArgs += @('backup', 'once')
    Invoke-Compose -EnvFile $envPath -Arguments $runArgs | Out-Null

    $stamp = Get-LatestBackupStamp -BackupDir $backupDir
    if (-not $stamp) {
        throw "ERROR: the backup service did not write a LATEST marker or a timestamped directory in $backupDir."
    }

    $backupPath = Join-Path $backupDir $stamp
    if (-not (Test-Path -LiteralPath (Join-Path $backupPath 'manifest.txt'))) {
        throw "ERROR: the backup directory is incomplete: $backupPath"
    }
    Write-Note "backup created: $backupPath"

    if (-not $SkipVerify) {
        Write-Step 'Verifying checksums'
        if (-not (Test-BackupIntegrity -BackupPath $backupPath)) {
            throw "ERROR: checksum verification failed for $backupPath."
        }
        Write-Note 'all checksums match'
    }

    if ($IncludeImages) {
        Write-Step 'Exporting Docker images'
        $imageDir = Join-Path $OutputDir 'images'
        New-Item -ItemType Directory -Force -Path $imageDir | Out-Null

        $images = @{
            'invoiceninja-local:latest'  = 'invoiceninja-local.tar'
            'invoiceninja-backup:latest' = 'invoiceninja-backup.tar'
        }

        foreach ($image in $images.Keys) {
            $target = Join-Path $imageDir $images[$image]
            Write-Note "saving $image"
            $result = Invoke-NativeSafe -FilePath 'docker' -Arguments @('save', $image, '-o', $target)
            if ($result.ExitCode -ne 0) {
                throw "docker save $image failed with exit code $($result.ExitCode).`n$($result.Output)"
            }
        }

        Write-Note "images exported to $imageDir"
        Write-Note 'copy exports\images\ next to the bundle; load them with docker load on the new machine'
    }

    if ($NoBundle) {
        Write-Step 'Done'
        Write-Note "backup directory: $backupPath"
        Write-Note 'run this script without -NoBundle to create a portable archive'
        return
    }

    Write-Step 'Creating the portable bundle'
    $archive = New-BundleArchive -RepoRoot $repoRoot -Timestamp $stamp -OutputDir $OutputDir -EnvPath $envPath -IncludeCode:$IncludeCode
    $archiveSize = [math]::Round((Get-Item -LiteralPath $archive).Length / 1MB, 1)

    Write-Step 'Done'
    Write-Note "backup directory: $backupPath"
    Write-Note "portable bundle:  $archive ($archiveSize MB)"
    Write-Host ''

    if ($IncludeCode) {
        Write-Host 'On the other Windows machine (self contained bundle):' -ForegroundColor Cyan
        Write-Note '1. extract the bundle'
        Write-Note '2. cd into code\ and run: powershell -ExecutionPolicy Bypass -File scripts\deploy.ps1'
        Write-Note '3. run: powershell -ExecutionPolicy Bypass -File scripts\restore.ps1 -Backup latest -RestoreEnv'
        Write-Note 'Docker images are rebuilt from the bundled Dockerfile. For an offline install, export them first with docker save.'
    }
    else {
        Write-Host 'On the other Windows machine:' -ForegroundColor Cyan
        Write-Note '1. copy this repository and the bundle to that machine'
        Write-Note ("2. powershell -ExecutionPolicy Bypass -File scripts\restore.ps1 -Bundle `"" + $archive + "`" -RestoreEnv")
        Write-Note 'For a bundle that already contains the application code, use -IncludeCode.'
    }
}
finally {
    Pop-Location
}
