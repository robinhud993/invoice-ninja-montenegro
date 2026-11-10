<#
.SYNOPSIS
    Restore an Invoice Ninja backup on a Windows machine running Docker.

.DESCRIPTION
    Restores the database dumps and the application storage from a local backup
    under backups\, or from a portable bundle created by backup.ps1. The bundle
    is unpacked into backups\ automatically.

    With -RestoreEnv the .env.docker from the bundle (or from the backup's
    config.tar.gz) replaces the local one, which keeps APP_KEY identical so
    encrypted values such as stored certificates stay readable. When the
    environment is replaced, existing data volumes are removed first so MySQL is
    initialized with the restored credentials.

    Without -RestoreEnv the current environment is kept. A differing APP_KEY is
    then an error unless -Force is passed, because encrypted columns would no
    longer decrypt.

.PARAMETER Backup
    Backup timestamp under backups\, or 'latest'. Default: latest.

.PARAMETER Bundle
    Path to a portable .tar.gz or .zip bundle created by backup.ps1.

.PARAMETER EnvFile
    Environment file to use. Default: .env.docker in the repository root.

.PARAMETER RestoreEnv
    Replace .env.docker with the environment from the backup. Recommended when
    the target machine has a different APP_KEY.

.PARAMETER Force
    Do not ask for confirmation and overwrite existing local files.

.PARAMETER SkipBuild
    Do not build missing images.

.PARAMETER SkipRecreate
    Only restore the data, do not recreate the stack afterwards.

.PARAMETER TimeoutSeconds
    How long to wait for the database and the application to become healthy.
    Default: 600.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\restore.ps1 -Backup latest

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\restore.ps1 `
        -Bundle C:\temp\invoiceninja-backup-20260925-120000.tar.gz -RestoreEnv
#>
[CmdletBinding()]
param(
    [Parameter(Position = 0)][string]$Backup = 'latest',
    [string]$Bundle,
    [string]$EnvFile = '.env.docker',
    [switch]$RestoreEnv,
    [switch]$Force,
    [switch]$SkipBuild,
    [switch]$SkipRecreate,
    [int]$TimeoutSeconds = 600
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'common.ps1')

$repoRoot = Split-Path -Parent $PSScriptRoot
$envPath = Resolve-EnvPath -RepoRoot $repoRoot -EnvFile $EnvFile
$backupDir = Join-Path $repoRoot 'backups'

$workDir = $null
$sourceEnvPath = $null
$envRestored = $false

Push-Location $repoRoot
try {
    Write-Step 'Checking Docker'
    Assert-Docker -StartDockerDesktop
    $composeVersion = Assert-ComposeV2
    Write-Note $composeVersion

    New-Item -ItemType Directory -Force -Path $backupDir | Out-Null

    if ($Bundle) {
        if (-not (Test-Path -LiteralPath $Bundle)) {
            throw "ERROR: bundle not found: $Bundle"
        }
        $bundlePath = (Resolve-Path -LiteralPath $Bundle).Path

        Write-Step "Unpacking bundle: $bundlePath"
        $workDir = New-TempDir 'invoiceninja-restore'
        Expand-BundleArchive -ArchivePath $bundlePath -Destination $workDir

        $sourceBackup = Find-BackupInBundle -Root $workDir
        $name = Split-Path -Leaf $sourceBackup
        Write-Note "backup in bundle: $name"

        $candidateEnv = Join-Path $workDir '.env.docker'
        if (Test-Path -LiteralPath $candidateEnv) { $sourceEnvPath = $candidateEnv }

        $destination = Join-Path $backupDir $name
        if (Test-Path -LiteralPath $destination) {
            if (-not $Force) {
                $answer = Read-Host "Backup '$name' already exists locally. Type 'yes' to overwrite it"
                if ($answer -ne 'yes') { throw 'Restore aborted.' }
            }
            Remove-Item -LiteralPath $destination -Recurse -Force
        }
        Copy-Item -LiteralPath $sourceBackup -Destination $backupDir -Recurse -Force
        Write-TextFile -Path (Join-Path $backupDir 'LATEST') -Text ($name + "`n")
        Write-Note "imported into $destination"
        $Backup = $name
    }
    if ($Backup -eq 'latest') {
        $Backup = Get-LatestBackupStamp -BackupDir $backupDir
        if (-not $Backup) {
            throw "ERROR: no backup found in $backupDir. Use -Bundle or run scripts\backup.ps1 first."
        }
    }

    $backupPath = Join-Path $backupDir $Backup
    if (-not (Test-Path -LiteralPath (Join-Path $backupPath 'manifest.txt'))) {
        throw "ERROR: not a backup directory: $backupPath"
    }

    # The environment can also be recovered from config.tar.gz. This runs when
    # -RestoreEnv asks for it or when there is no local environment file yet.
    if (-not $sourceEnvPath -and ($RestoreEnv -or -not (Test-Path -LiteralPath $envPath))) {
        if (-not $workDir) { $workDir = New-TempDir 'invoiceninja-env' }
        $envFromConfig = Join-Path $workDir '.env.docker'
        if (Export-EnvFromConfigArchive -BackupPath $backupPath -Destination $envFromConfig) {
            $sourceEnvPath = $envFromConfig
        }
        else {
            Write-Warn 'the backup has no .env.docker in config.tar.gz; keeping the current environment'
        }
    }

    if (-not (Test-Path -LiteralPath $envPath) -and -not $sourceEnvPath) {
        throw "ERROR: $envPath does not exist and the backup has no .env.docker. Run scripts\deploy.ps1 first, or restore a bundle with -Bundle."
    }

    if ($sourceEnvPath) {
        if (-not (Test-Path -LiteralPath $envPath)) {
            Copy-Item -LiteralPath $sourceEnvPath -Destination $envPath
            $envRestored = $true
            Write-Note "created $EnvFile from the backup"
        }
        elseif ($RestoreEnv) {
            $stamp = (Get-Date).ToUniversalTime().ToString('yyyyMMdd-HHmmss')
            $envBackup = $envPath + '.bak-' + $stamp
            Copy-Item -LiteralPath $envPath -Destination $envBackup -Force
            Copy-Item -LiteralPath $sourceEnvPath -Destination $envPath -Force
            $envRestored = $true
            Write-Note "replaced $EnvFile (previous copy saved as $(Split-Path -Leaf $envBackup))"
        }
        else {
            $backupKey = Read-EnvValue -Path $sourceEnvPath -Key 'APP_KEY'
            $currentKey = Read-EnvValue -Path $envPath -Key 'APP_KEY'
            if ($backupKey -and $currentKey -and $backupKey -ne $currentKey) {
                if ($Force) {
                    Write-Warn 'APP_KEY in the backup differs from the current one; encrypted values may not be readable. Pass -RestoreEnv to adopt the environment from the backup.'
                }
                else {
                    throw 'ERROR: APP_KEY in the backup differs from the current environment. Re-run with -RestoreEnv to adopt the environment from the backup (recommended), or with -Force to keep the current environment.'
                }
            }
        }
    }

    $removeVolumes = $false
    if ($envRestored) {
        $project = Get-ProjectName -EnvPath $envPath
        $volumeNames = @(($project + '_db_data'), ($project + '_redis_data'), ($project + '_app_storage'))
        $existingVolumes = @($volumeNames | Where-Object { Test-DockerVolumeExists -Name $_ })
        if ($existingVolumes.Count -gt 0) {
            $removeVolumes = $true
            Write-Note ('existing volumes to remove: ' + ($existingVolumes -join ', '))
        }
    }

    Write-Step "Restoring backup: $Backup"
    Write-Note 'the database and the application storage will be overwritten'
    if ($removeVolumes) {
        Write-Note 'existing data volumes will be deleted so the restored credentials apply'
    }

    if (-not $Force) {
        $answer = Read-Host "Type 'yes' to continue"
        if ($answer -ne 'yes') { throw 'Restore aborted.' }
    }

    if ($removeVolumes) {
        Write-Step 'Removing existing data volumes'
        Invoke-Compose -EnvFile $envPath -Arguments @('down', '-v') -AllowFailure | Out-Null
    }
    else {
        Invoke-Compose -EnvFile $envPath -Arguments @('stop', 'app', 'worker', 'scheduler') -AllowFailure | Out-Null
    }

    if (-not $SkipBuild) {
        if (-not (Test-ImageExists -Name 'invoiceninja-backup:latest')) {
            Write-Step 'Building the backup image'
            Invoke-Compose -EnvFile $envPath -Arguments @('--profile', 'backup', 'build', 'backup') | Out-Null
        }
        if (-not (Test-ImageExists -Name 'invoiceninja-local:latest')) {
            Write-Step 'Building the application image'
            Invoke-Compose -EnvFile $envPath -Arguments @('build') | Out-Null
        }
    }

    Write-Step 'Starting the database and Redis'
    Invoke-Compose -EnvFile $envPath -Arguments @('up', '-d', 'db', 'redis') | Out-Null
    Wait-ForServiceHealthy -EnvFile $envPath -Service 'db' -TimeoutSeconds $TimeoutSeconds
    Wait-ForServiceHealthy -EnvFile $envPath -Service 'redis' -TimeoutSeconds $TimeoutSeconds

    Write-Step 'Restoring database and storage'
    Invoke-Compose -EnvFile $envPath -Arguments @('--profile', 'backup', 'run', '--rm', '-T', '--user', 'root', '-e', 'FORCE=1', 'backup', 'restore', $Backup) | Out-Null

    Write-Step 'Flushing the Redis cache and queue'
    Invoke-Compose -EnvFile $envPath -Arguments @('exec', '-T', 'redis', 'redis-cli', 'FLUSHALL') -AllowFailure | Out-Null

    if ($SkipRecreate) {
        Write-Step 'Restore complete (stack not recreated: -SkipRecreate)'
        return
    }

    Write-Step 'Recreating the stack'
    Invoke-Compose -EnvFile $envPath -Arguments @('up', '-d', '--force-recreate') | Out-Null
    Wait-ForServiceHealthy -EnvFile $envPath -Service 'app' -TimeoutSeconds $TimeoutSeconds

    $accountCount = Get-AccountCount -EnvFile $envPath
    $appUrl = Read-EnvValue -Path $envPath -Key 'APP_URL'
    if (-not $appUrl) {
        $appPort = Read-EnvValue -Path $envPath -Key 'APP_FORWARD_PORT'
        if (-not $appPort) { $appPort = '9080' }
        $appUrl = 'http://localhost:' + $appPort
    }

    Write-Step 'Restore complete'
    Write-Note "backup:      $backupPath"
    Write-Note "environment: $envPath"
    Write-Note "application: $appUrl"
    if ($accountCount -ge 0) { Write-Note "accounts:    $accountCount" }
    Write-Host ''
    Write-Note 'the deployment configuration in the backup is not applied automatically, review it with:'
    Write-Note "  tar -tzf `"$backupPath\config.tar.gz`""
    if ($envRestored) {
        Write-Note 'the environment was taken from another machine; check APP_URL and the *_FORWARD_PORT values'
    }
}
finally {
    Pop-Location
    if ($workDir -and (Test-Path -LiteralPath $workDir)) {
        Remove-Item -LiteralPath $workDir -Recurse -Force -ErrorAction SilentlyContinue
    }
}
