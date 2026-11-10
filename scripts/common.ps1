<#
    Shared helpers for the Invoice Ninja Windows Docker scripts.

    Dot source this file from deploy.ps1, backup.ps1 or restore.ps1:

        . (Join-Path $PSScriptRoot 'common.ps1')

    Requires Windows PowerShell 5.1 or later.
#>

# Docker's tty progress writer queries the console for its size, which fails
# with "failed to get console: The handle is invalid." when a script runs
# without an attached console (for example from a task runner). Plain progress
# avoids the console query for both Compose and BuildKit.
$env:BUILDKIT_PROGRESS = 'plain'
$env:COMPOSE_PROGRESS = 'plain'

function Write-Step {
    param([Parameter(Mandatory = $true)][string]$Message)
    Write-Host "==> $Message" -ForegroundColor Cyan
}

function Write-Note {
    param([Parameter(Mandatory = $true)][string]$Message)
    Write-Host "    $Message"
}

function Write-Warn {
    param([Parameter(Mandatory = $true)][string]$Message)
    Write-Host "WARNING: $Message" -ForegroundColor Yellow
}

function Read-TextFile {
    param([Parameter(Mandatory = $true)][string]$Path)
    return [System.IO.File]::ReadAllText($Path)
}

function Write-TextFile {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string]$Text
    )
    # Always LF and UTF-8 without BOM: docker compose and the containers read
    # the environment file as a plain Unix style file.
    $encoding = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($Path, $Text, $encoding)
}

function Get-LatestBackupStamp {
    param([Parameter(Mandatory = $true)][string]$BackupDir)

    # Prefer the LATEST marker, but only when it is a regular file. On a case
    # insensitive filesystem (Windows) the backup service's 'latest' symlink can
    # resolve to the same name, so fall back to the newest timestamped directory.
    $marker = Join-Path $BackupDir 'LATEST'
    if (Test-Path -LiteralPath $marker -PathType Leaf) {
        $value = (Read-TextFile -Path $marker).Trim()
        if ($value) { return $value }
    }

    $dir = Get-ChildItem -LiteralPath $BackupDir -Directory -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -match '^\d{8}-\d{6}$' } |
        Sort-Object Name -Descending |
        Select-Object -First 1

    if ($dir) { return $dir.Name }

    return ''
}

function Assert-Command {
    param(
        [Parameter(Mandatory = $true)][string]$Name,
        [Parameter(Mandatory = $true)][string]$Hint
    )
    if (-not (Get-Command $Name -ErrorAction SilentlyContinue)) {
        throw "ERROR: '$Name' is not on PATH. $Hint"
    }
}

function Invoke-NativeSafe {
    # Runs a native command and captures stdout and stderr without letting
    # $ErrorActionPreference = 'Stop' turn stderr lines into terminating errors.
    param(
        [Parameter(Mandatory = $true)][string]$FilePath,
        [Parameter(Mandatory = $true)][string[]]$Arguments
    )
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $output = & $FilePath @Arguments 2>&1
    }
    finally {
        $ErrorActionPreference = $previous
    }
    return [pscustomobject]@{
        ExitCode = $LASTEXITCODE
        Output   = ($output | Out-String)
    }
}

function Test-DockerDaemon {
    $result = Invoke-NativeSafe -FilePath 'docker' -Arguments @('info', '--format', '{{.ServerVersion}}')
    return ($result.ExitCode -eq 0)
}

function Assert-ComposeV2 {
    $result = Invoke-NativeSafe -FilePath 'docker' -Arguments @('compose', 'version')
    if ($result.ExitCode -ne 0) {
        throw "ERROR: Docker Compose v2 is required ('docker compose ...'). $($result.Output.Trim())"
    }
    $firstLine = ($result.Output -split "`r?`n" | Where-Object { $_.Trim() } | Select-Object -First 1)
    return $firstLine.Trim()
}

function Assert-Docker {
    param([switch]$StartDockerDesktop)

    Assert-Command -Name 'docker' -Hint 'Install Docker Desktop: https://docs.docker.com/desktop/setup/install/windows-install/'

    if (Test-DockerDaemon) { return }

    if (-not $StartDockerDesktop) {
        throw 'ERROR: the Docker daemon is not reachable. Start Docker Desktop and try again.'
    }

    $candidates = @()
    if ($env:ProgramFiles) { $candidates += (Join-Path $env:ProgramFiles 'Docker\Docker\Docker Desktop.exe') }
    if (${env:ProgramFiles(x86)}) { $candidates += (Join-Path ${env:ProgramFiles(x86)} 'Docker\Docker\Docker Desktop.exe') }
    if ($env:LOCALAPPDATA) { $candidates += (Join-Path $env:LOCALAPPDATA 'Docker\Docker Desktop.exe') }

    $exe = $candidates | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
    if (-not $exe) {
        throw 'ERROR: the Docker daemon is not reachable and Docker Desktop was not found. Start Docker Desktop and try again.'
    }

    Write-Note "starting Docker Desktop: $exe"
    Start-Process -FilePath $exe | Out-Null

    $deadline = (Get-Date).AddSeconds(240)
    while ((Get-Date) -lt $deadline) {
        Start-Sleep -Seconds 5
        if (Test-DockerDaemon) {
            Write-Note 'Docker daemon is ready'
            return
        }
    }

    throw 'ERROR: Docker Desktop did not become ready within 240 seconds. Start it manually and try again.'
}

function Invoke-Compose {
    # Runs 'docker compose --env-file <EnvFile> <Arguments>'.
    #   -Capture       capture the output and return { ExitCode, Output }
    #   -AllowFailure  do not throw when the command exits non zero
    param(
        [Parameter(Mandatory = $true)][string[]]$Arguments,
        [string]$EnvFile,
        [switch]$Capture,
        [switch]$AllowFailure
    )

    $full = @('compose')
    if ($EnvFile) { $full += @('--env-file', $EnvFile) }
    $full += $Arguments

    if ($Capture) {
        $result = Invoke-NativeSafe -FilePath 'docker' -Arguments $full
        if ($result.ExitCode -ne 0 -and -not $AllowFailure) {
            throw "docker compose $($Arguments -join ' ') failed with exit code $($result.ExitCode).`n$($result.Output)"
        }
        return $result
    }

    & docker @full
    if ($LASTEXITCODE -ne 0 -and -not $AllowFailure) {
        throw "docker compose $($Arguments -join ' ') failed with exit code $LASTEXITCODE."
    }
    return $LASTEXITCODE
}

function Resolve-EnvPath {
    param(
        [Parameter(Mandatory = $true)][string]$RepoRoot,
        [Parameter(Mandatory = $true)][string]$EnvFile
    )
    if ([System.IO.Path]::IsPathRooted($EnvFile)) { return $EnvFile }
    return (Join-Path $RepoRoot $EnvFile)
}

function Read-EnvValue {
    # Reads KEY=value from an environment file without interpreting the rest of
    # the file. Returns $null when the key is missing.
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string]$Key
    )
    if (-not (Test-Path -LiteralPath $Path)) { return $null }

    $text = [System.IO.File]::ReadAllText($Path).TrimStart([char]0xFEFF)
    $pattern = '(?m)^\s*' + [regex]::Escape($Key) + '\s*=\s*(.*?)\s*$'
    $match = [regex]::Match($text, $pattern)
    if (-not $match.Success) { return $null }

    $value = $match.Groups[1].Value.Trim()
    if ($value.Length -ge 2) {
        $quoted = (($value.StartsWith('"') -and $value.EndsWith('"')) -or ($value.StartsWith("'") -and $value.EndsWith("'")))
        if ($quoted) { $value = $value.Substring(1, $value.Length - 2) }
    }
    return $value
}

function Set-EnvValue {
    # Replaces KEY=... in an environment file or appends it when missing.
    # The file is rewritten with LF line endings and without a BOM.
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string]$Key,
        [Parameter(Mandatory = $true)][string]$Value
    )
    $text = [System.IO.File]::ReadAllText($Path).TrimStart([char]0xFEFF)
    $lines = @($text -split "`r?`n")
    $pattern = '^\s*' + [regex]::Escape($Key) + '\s*='
    $found = $false

    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -match $pattern) {
            $lines[$i] = $Key + '=' + $Value
            $found = $true
            break
        }
    }

    if (-not $found) { $lines += ($Key + '=' + $Value) }

    Write-TextFile -Path $Path -Text (($lines -join "`n"))
}

function New-RandomPassword {
    param([int]$Length = 28)
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'
    $bytes = New-Object byte[] $Length
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }

    $builder = New-Object System.Text.StringBuilder
    foreach ($byte in $bytes) { [void]$builder.Append($chars[$byte % $chars.Length]) }
    return $builder.ToString()
}

function New-LaravelAppKey {
    $bytes = New-Object byte[] 32
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return 'base64:' + [Convert]::ToBase64String($bytes)
}

function New-TempDir {
    param([string]$Prefix = 'invoiceninja')
    $path = Join-Path ([System.IO.Path]::GetTempPath()) ($Prefix + '-' + [Guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Force -Path $path | Out-Null
    return $path
}

function Test-HostPortInUse {
    param([Parameter(Mandatory = $true)][int]$Port)
    try {
        $connection = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction Stop
        return ($null -ne $connection)
    }
    catch {
        return $false
    }
}

function Test-DockerVolumeExists {
    param([Parameter(Mandatory = $true)][string]$Name)
    $result = Invoke-NativeSafe -FilePath 'docker' -Arguments @('volume', 'inspect', $Name)
    return ($result.ExitCode -eq 0)
}

function Test-ImageExists {
    param([Parameter(Mandatory = $true)][string]$Name)
    $result = Invoke-NativeSafe -FilePath 'docker' -Arguments @('image', 'inspect', $Name)
    return ($result.ExitCode -eq 0)
}

function Wait-ForServiceHealthy {
    # Polls 'docker compose ps -q <Service>' and the container health status.
    # Services without a health check count as ready once they are running.
    param(
        [Parameter(Mandatory = $true)][string]$EnvFile,
        [Parameter(Mandatory = $true)][string]$Service,
        [int]$TimeoutSeconds = 300
    )
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)

    while ((Get-Date) -lt $deadline) {
        $containerId = ''
        $ps = Invoke-Compose -EnvFile $EnvFile -Arguments @('ps', '-q', $Service) -Capture -AllowFailure
        if ($ps.ExitCode -eq 0) { $containerId = $ps.Output.Trim() }

        if ($containerId) {
            $inspect = Invoke-NativeSafe -FilePath 'docker' -Arguments @('inspect', '--format', '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}', $containerId)
            $status = $inspect.Output.Trim()
            if ($inspect.ExitCode -eq 0 -and ($status -eq 'healthy' -or $status -eq 'running')) {
                return
            }
            if ($status -eq 'unhealthy') {
                throw "Service '$Service' reported unhealthy. Inspect the logs with: docker compose --env-file `"$EnvFile`" logs $Service"
            }
        }

        Start-Sleep -Seconds 3
    }

    throw "Timed out after $TimeoutSeconds seconds waiting for service '$Service' to become healthy."
}

function Test-BackupIntegrity {
    # Verifies SHA256SUMS in a backup directory. Returns $true when every listed
    # artifact matches its checksum.
    param([Parameter(Mandatory = $true)][string]$BackupPath)

    $sumsFile = Join-Path $BackupPath 'SHA256SUMS'
    if (-not (Test-Path -LiteralPath $sumsFile)) {
        Write-Warn "no SHA256SUMS in $BackupPath"
        return $false
    }

    $ok = $true
    foreach ($line in [System.IO.File]::ReadAllLines($sumsFile)) {
        if (-not $line.Trim()) { continue }
        $parts = $line -split '\s+', 2
        if ($parts.Count -ne 2) { continue }

        $expected = $parts[0].Trim().ToLowerInvariant()
        $name = $parts[1].Trim().TrimStart('*') -replace '^\./', '' -replace '^\.\\', ''
        $file = Join-Path $BackupPath $name

        if (-not (Test-Path -LiteralPath $file)) {
            Write-Warn "missing artifact: $name"
            $ok = $false
            continue
        }

        $actual = (Get-FileHash -LiteralPath $file -Algorithm SHA256).Hash.ToLowerInvariant()
        if ($actual -ne $expected) {
            Write-Warn "checksum mismatch: $name"
            $ok = $false
        }
    }

    return $ok
}

function Test-TarAvailable {
    return [bool](Get-Command tar -ErrorAction SilentlyContinue)
}

function New-BundleArchive {
    # Creates one portable archive with 'backups/<timestamp>/...' plus the
    # environment file at the root. Uses tar when available (no 2 GB limit),
    # otherwise Compress-Archive.
    param(
        [Parameter(Mandatory = $true)][string]$RepoRoot,
        [Parameter(Mandatory = $true)][string]$Timestamp,
        [Parameter(Mandatory = $true)][string]$OutputDir,
        [string]$EnvPath,
        [switch]$IncludeCode
    )

    New-Item -ItemType Directory -Force -Path $OutputDir | Out-Null
    $backupRel = 'backups/' + $Timestamp

    $includeEnv = $false
    if ($EnvPath -and (Test-Path -LiteralPath $EnvPath)) {
        if ((Split-Path -Parent $EnvPath) -eq $RepoRoot) {
            $includeEnv = $true
        }
        else {
            Write-Warn "the environment file is outside the repository and is not included in the bundle: $EnvPath"
        }
    }

    if ($IncludeCode) {
        return New-CodeBundleArchive -RepoRoot $RepoRoot -Timestamp $Timestamp `
            -OutputDir $OutputDir -EnvPath $(if ($includeEnv) { $EnvPath } else { $null })
    }

    if (Test-TarAvailable) {
        $archive = Join-Path $OutputDir ('invoiceninja-backup-' + $Timestamp + '.tar.gz')
        $items = @($backupRel)
        if ($includeEnv) { $items += (Split-Path -Leaf $EnvPath) }
        $result = Invoke-NativeSafe -FilePath 'tar' -Arguments (@('-czf', $archive, '-C', $RepoRoot) + $items)
        if ($result.ExitCode -ne 0) {
            throw "tar failed with exit code $($result.ExitCode).`n$($result.Output)"
        }
        return $archive
    }

    $archive = Join-Path $OutputDir ('invoiceninja-backup-' + $Timestamp + '.zip')
    $paths = @((Join-Path $RepoRoot $backupRel))
    if ($includeEnv) { $paths += $EnvPath }
    Compress-Archive -LiteralPath $paths -DestinationPath $archive -Force
    return $archive
}

function New-CodeBundleArchive {
    # Self contained bundle: the application code, the backup directory and the
    # environment file, so a new machine only needs Docker to rebuild and run
    # the service. Docker images are not included; they are rebuilt from the
    # bundled Dockerfile, or exported separately with `docker save` for offline
    # installs.
    param(
        [Parameter(Mandatory = $true)][string]$RepoRoot,
        [Parameter(Mandatory = $true)][string]$Timestamp,
        [Parameter(Mandatory = $true)][string]$OutputDir,
        [string]$EnvPath
    )

    $staging = Join-Path $OutputDir ('.staging-' + $Timestamp)
    if (Test-Path -LiteralPath $staging) {
        Remove-Item -LiteralPath $staging -Recurse -Force
    }
    New-Item -ItemType Directory -Force -Path $staging | Out-Null

    try {
        # Copy the repository without runtime data or regenerated output. The
        # app storage is inside the backup already, and images are rebuilt.
        $codeDest = Join-Path $staging 'code'
        $excludeDirs = @('.git', 'node_modules', 'vendor', 'backups', 'exports', 'storage', '.idea', '.vscode')
        $excludeFiles = @('*.log')
        $robocopyArgs = @($RepoRoot, $codeDest, '/E', '/NFL', '/NDL', '/NJH', '/NJS', '/XD') + $excludeDirs + @('/XF') + $excludeFiles
        $rc = Start-Process -FilePath 'robocopy' -ArgumentList $robocopyArgs -Wait -PassThru -NoNewWindow
        if ($rc.ExitCode -ge 8) {
            throw "robocopy failed with exit code $($rc.ExitCode)"
        }

        # Nest the backup and the environment inside the code tree so the
        # extracted code\ directory is a ready to restore repository.
        New-Item -ItemType Directory -Force -Path (Join-Path $codeDest 'backups') | Out-Null
        Copy-Item -LiteralPath (Join-Path $RepoRoot ('backups\' + $Timestamp)) `
            -Destination (Join-Path $codeDest 'backups') -Recurse -Force

        if ($EnvPath) {
            Copy-Item -LiteralPath $EnvPath -Destination (Join-Path $codeDest '.env.docker') -Force
        }

        if (Test-TarAvailable) {
            $archive = Join-Path $OutputDir ('invoiceninja-complete-' + $Timestamp + '.tar.gz')
            $result = Invoke-NativeSafe -FilePath 'tar' -Arguments @('-czf', $archive, '-C', $staging, '.')
            if ($result.ExitCode -ne 0) {
                throw "tar failed with exit code $($result.ExitCode).`n$($result.Output)"
            }
            return $archive
        }

        $archive = Join-Path $OutputDir ('invoiceninja-complete-' + $Timestamp + '.zip')
        Compress-Archive -Path (Join-Path $staging '*') -DestinationPath $archive -Force
        return $archive
    }
    finally {
        if (Test-Path -LiteralPath $staging) {
            Remove-Item -LiteralPath $staging -Recurse -Force
        }
    }
}

function Expand-BundleArchive {
    param(
        [Parameter(Mandatory = $true)][string]$ArchivePath,
        [Parameter(Mandatory = $true)][string]$Destination
    )

    New-Item -ItemType Directory -Force -Path $Destination | Out-Null
    $lower = $ArchivePath.ToLowerInvariant()

    if ($lower.EndsWith('.zip')) {
        Expand-Archive -LiteralPath $ArchivePath -DestinationPath $Destination -Force
        return
    }

    if ($lower.EndsWith('.tar.gz') -or $lower.EndsWith('.tgz')) {
        if (-not (Test-TarAvailable)) {
            throw "ERROR: tar is required to extract $ArchivePath. Use a .zip bundle instead."
        }
        $result = Invoke-NativeSafe -FilePath 'tar' -Arguments @('-xzf', $ArchivePath, '-C', $Destination)
        if ($result.ExitCode -ne 0) {
            throw "tar failed with exit code $($result.ExitCode).`n$($result.Output)"
        }
        return
    }

    throw "ERROR: unsupported archive type: $ArchivePath. Use .zip, .tar.gz or .tgz."
}

function Find-BackupInBundle {
    # Returns the directory inside an extracted bundle that holds manifest.txt.
    # Handles 'backups/<timestamp>', '<timestamp>' and flat layouts.
    param([Parameter(Mandatory = $true)][string]$Root)

    $backupsRoot = Join-Path $Root 'backups'
    if (Test-Path -LiteralPath $backupsRoot) {
        $dir = Get-ChildItem -LiteralPath $backupsRoot -Directory |
            Where-Object { Test-Path -LiteralPath (Join-Path $_.FullName 'manifest.txt') } |
            Sort-Object Name -Descending |
            Select-Object -First 1
        if ($dir) { return $dir.FullName }
    }

    $dir = Get-ChildItem -LiteralPath $Root -Directory |
        Where-Object { Test-Path -LiteralPath (Join-Path $_.FullName 'manifest.txt') } |
        Sort-Object Name -Descending |
        Select-Object -First 1
    if ($dir) { return $dir.FullName }

    if (Test-Path -LiteralPath (Join-Path $Root 'manifest.txt')) {
        return (Resolve-Path -LiteralPath $Root).Path
    }

    throw "ERROR: no backup folder (manifest.txt) found in the bundle."
}

function Export-EnvFromConfigArchive {
    # Extracts .env.docker from a backup's config.tar.gz into Destination.
    # Returns $false when the archive or the file is missing.
    param(
        [Parameter(Mandatory = $true)][string]$BackupPath,
        [Parameter(Mandatory = $true)][string]$Destination
    )

    $configArchive = Join-Path $BackupPath 'config.tar.gz'
    if (-not (Test-Path -LiteralPath $configArchive)) { return $false }
    if (-not (Test-TarAvailable)) {
        throw 'ERROR: tar is required to read config.tar.gz. tar ships with Windows 10 1803 and later.'
    }

    $temp = New-TempDir 'invoiceninja-config'
    try {
        $result = Invoke-NativeSafe -FilePath 'tar' -Arguments @('-xzf', $configArchive, '-C', $temp)
        if ($result.ExitCode -ne 0) {
            throw "tar failed with exit code $($result.ExitCode).`n$($result.Output)"
        }

        $candidate = Get-ChildItem -LiteralPath $temp -Recurse -File -Force |
            Where-Object { $_.Name -eq '.env.docker' } |
            Select-Object -First 1
        if (-not $candidate) { return $false }

        $destinationDir = Split-Path -Parent $Destination
        if ($destinationDir) { New-Item -ItemType Directory -Force -Path $destinationDir | Out-Null }
        Copy-Item -LiteralPath $candidate.FullName -Destination $Destination -Force
        return $true
    }
    finally {
        Remove-Item -LiteralPath $temp -Recurse -Force -ErrorAction SilentlyContinue
    }
}

function Get-ProjectName {
    param([Parameter(Mandatory = $true)][string]$EnvPath)
    $name = Read-EnvValue -Path $EnvPath -Key 'COMPOSE_PROJECT_NAME'
    if (-not $name) { $name = 'invoiceninja' }
    return $name
}

function Get-AccountCount {
    # Returns the number of rows in the accounts table, or -1 when unknown.
    param([Parameter(Mandatory = $true)][string]$EnvFile)

    # Values are read from the environment file rather than the container shell,
    # because the container environment is not reliably available to `sh -c`.
    $dbName = Read-EnvValue -Path $EnvFile -Key 'DB_DATABASE'
    $dbRootPassword = Read-EnvValue -Path $EnvFile -Key 'DB_ROOT_PASSWORD'

    if (-not $dbName -or -not $dbRootPassword) { return -1 }

    $sql = "MYSQL_PWD='$dbRootPassword' mysql -N -s -u root -D '$dbName' -e 'SELECT COUNT(*) FROM accounts' 2>/dev/null"
    $result = Invoke-Compose -EnvFile $EnvFile -Arguments @('exec', '-T', 'db', 'sh', '-c', $sql) -Capture -AllowFailure
    if ($result.ExitCode -ne 0) { return -1 }

    $count = 0
    if ([int]::TryParse($result.Output.Trim(), [ref]$count)) { return $count }
    return -1
}
