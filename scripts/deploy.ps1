<#
.SYNOPSIS
    Deploy the Invoice Ninja Docker stack on a Windows machine.

.DESCRIPTION
    Checks for Docker Desktop, creates .env.docker from .env.docker.example when
    it is missing, generates an APP_KEY plus database passwords when the file
    still has placeholders, builds the images, starts the stack and waits until
    the database and the application report healthy.

    The script is idempotent: running it again on a machine that already has the
    stack only rebuilds and restarts the services. When the database has no
    accounts yet, pass -AdminEmail and -AdminPassword to create the first
    account, otherwise open the /setup page in the browser.

.PARAMETER EnvFile
    Environment file to use. Default: .env.docker in the repository root.

.PARAMETER SkipBuild
    Do not run 'docker compose build' before starting.

.PARAMETER NoDockerDesktopStart
    Do not try to start Docker Desktop automatically when the daemon is down.

.PARAMETER AdminEmail
    Create the first account with this email when the database is empty.
    Requires -AdminPassword.

.PARAMETER AdminPassword
    Password for the first account. Requires -AdminEmail.

.PARAMETER Production
    Harden the environment for production: APP_ENV=production, APP_DEBUG=false
    and LOG_LEVEL=error. Without this switch the example values are kept (debug
    on), which is what you want for local development.

.PARAMETER TimeoutSeconds
    How long to wait for the database and the application to become healthy.
    Default: 600.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\deploy.ps1

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\deploy.ps1 `
        -AdminEmail admin@example.com -AdminPassword 'change-me-now'

.EXAMPLE
    # Production instance: debug off and the first account created
    powershell -ExecutionPolicy Bypass -File scripts\deploy.ps1 -Production `
        -AdminEmail admin@example.com -AdminPassword 'change-me-now'
#>
[CmdletBinding()]
param(
    [string]$EnvFile = '.env.docker',
    [switch]$SkipBuild,
    [switch]$NoDockerDesktopStart,
    [string]$AdminEmail,
    [string]$AdminPassword,
    [switch]$Production,
    [int]$TimeoutSeconds = 600
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'common.ps1')

$repoRoot = Split-Path -Parent $PSScriptRoot
$envPath = Resolve-EnvPath -RepoRoot $repoRoot -EnvFile $EnvFile

if ($AdminEmail -and -not $AdminPassword) { throw 'ERROR: -AdminEmail requires -AdminPassword.' }
if ($AdminPassword -and -not $AdminEmail) { throw 'ERROR: -AdminPassword requires -AdminEmail.' }

Push-Location $repoRoot
try {
    Write-Step 'Checking Docker'
    if ($NoDockerDesktopStart) { Assert-Docker } else { Assert-Docker -StartDockerDesktop }
    $composeVersion = Assert-ComposeV2
    Write-Note $composeVersion

    Write-Step 'Preparing the environment file'
    if (-not (Test-Path -LiteralPath $envPath)) {
        $examplePath = Join-Path $repoRoot ($EnvFile + '.example')
        if (-not (Test-Path -LiteralPath $examplePath)) {
            throw "ERROR: $EnvFile does not exist and $EnvFile.example was not found. Run this script inside the repository or pass -EnvFile <path>."
        }
        Copy-Item -LiteralPath $examplePath -Destination $envPath
        Write-Note "created $EnvFile from $EnvFile.example"
        Write-Note 'review the port mappings, APP_URL and mail settings in that file'
    }
    else {
        Write-Note "using existing $EnvFile"
    }

    $generated = @()
    $appKey = Read-EnvValue -Path $envPath -Key 'APP_KEY'
    if (-not $appKey -or $appKey -like '*CHANGE_ME*') {
        Set-EnvValue -Path $envPath -Key 'APP_KEY' -Value (New-LaravelAppKey)
        $generated += 'APP_KEY'
    }

    $dbPassword = Read-EnvValue -Path $envPath -Key 'DB_PASSWORD'
    if (-not $dbPassword -or $dbPassword -eq 'change-me') {
        $dbPassword = New-RandomPassword -Length 28
        Set-EnvValue -Path $envPath -Key 'DB_PASSWORD' -Value $dbPassword
        $generated += 'DB_PASSWORD'
    }

    $dbRootPassword = Read-EnvValue -Path $envPath -Key 'DB_ROOT_PASSWORD'
    if (-not $dbRootPassword -or $dbRootPassword -eq 'change-me-root') {
        Set-EnvValue -Path $envPath -Key 'DB_ROOT_PASSWORD' -Value (New-RandomPassword -Length 28)
        $generated += 'DB_ROOT_PASSWORD'
    }

    $dbPassword3 = Read-EnvValue -Path $envPath -Key 'DB_PASSWORD3'
    if (-not $dbPassword3 -or $dbPassword3 -eq 'change-me') {
        # The test connection uses the same MySQL user as the primary database.
        Set-EnvValue -Path $envPath -Key 'DB_PASSWORD3' -Value $dbPassword
        $generated += 'DB_PASSWORD3'
    }

    if ($generated.Count -gt 0) {
        Write-Note ('generated: ' + ($generated -join ', '))
    }
    else {
        Write-Note 'secrets are already set'
    }

    if ($Production) {
        Set-EnvValue -Path $envPath -Key 'APP_ENV' -Value 'production'
        Set-EnvValue -Path $envPath -Key 'APP_DEBUG' -Value 'false'
        Set-EnvValue -Path $envPath -Key 'LOG_LEVEL' -Value 'error'
        Write-Note 'production mode: APP_ENV=production, APP_DEBUG=false, LOG_LEVEL=error'
    }

    foreach ($key in @('APP_KEY', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD')) {
        if (-not (Read-EnvValue -Path $envPath -Key $key)) {
            throw "ERROR: $EnvFile is missing a value for $key."
        }
    }

    $configCheck = Invoke-Compose -EnvFile $envPath -Arguments @('config', '--quiet') -Capture -AllowFailure
    if ($configCheck.ExitCode -ne 0) {
        throw "ERROR: docker compose could not read $EnvFile.`n$($configCheck.Output)"
    }

    $stackRunning = (Invoke-Compose -EnvFile $envPath -Arguments @('ps', '-q') -Capture -AllowFailure).Output.Trim()
    if (-not $stackRunning) {
        foreach ($portKey in @('APP_FORWARD_PORT', 'DB_FORWARD_PORT', 'REDIS_FORWARD_PORT')) {
            $portValue = Read-EnvValue -Path $envPath -Key $portKey
            $portNumber = 0
            if ($portValue -and [int]::TryParse($portValue, [ref]$portNumber) -and (Test-HostPortInUse -Port $portNumber)) {
                Write-Warn "host port $portNumber ($portKey) is already in use; change the port in $EnvFile if the stack fails to start"
            }
        }
    }

    if (-not $SkipBuild) {
        Write-Step 'Building the images (this can take several minutes on the first run)'
        # --progress plain avoids the console query that fails with
        # "failed to get console: The handle is invalid." in non interactive runs.
        Invoke-Compose -EnvFile $envPath -Arguments @('--progress', 'plain', 'build') | Out-Null
    }

    Write-Step 'Starting the stack'
    Invoke-Compose -EnvFile $envPath -Arguments @('up', '-d') | Out-Null

    Write-Step 'Waiting for the database'
    Wait-ForServiceHealthy -EnvFile $envPath -Service 'db' -TimeoutSeconds $TimeoutSeconds
    Write-Step 'Waiting for the application (migrations and seeding run on first start)'
    Wait-ForServiceHealthy -EnvFile $envPath -Service 'app' -TimeoutSeconds $TimeoutSeconds

    Write-Step 'Verifying the storage link (company logos and document uploads)'
    $storageCheck = Invoke-Compose -EnvFile $envPath -Arguments @(
        'exec', '-T', 'app', 'sh', '-c',
        'test -L public/storage && touch public/storage/.write-test && rm public/storage/.write-test && echo ok || echo fail'
    ) -Capture -AllowFailure

    if ($storageCheck.Output.Trim() -eq 'ok') {
        Write-Note 'public/storage is a writable symlink'
    }
    else {
        Write-Warn 'public/storage is missing, is not a symlink, or is not writable'
        Write-Note 'uploads such as company logos would fail. Rebuild the image so the Dockerfile recreates the link:'
        Write-Note '  docker compose --env-file .env.docker build app'
        Write-Note '  docker compose --env-file .env.docker up -d --force-recreate app worker scheduler'
    }

    $accountCount = Get-AccountCount -EnvFile $envPath
    $appUrl = Read-EnvValue -Path $envPath -Key 'APP_URL'
    if (-not $appUrl) {
        $appPort = Read-EnvValue -Path $envPath -Key 'APP_FORWARD_PORT'
        if (-not $appPort) { $appPort = '9080' }
        $appUrl = 'http://localhost:' + $appPort
    }

    if ($accountCount -gt 0) {
        Write-Note "existing installation detected ($accountCount account(s))"
    }
    elseif ($AdminEmail -and $AdminPassword) {
        Write-Step "Creating the first account: $AdminEmail"
        Invoke-Compose -EnvFile $envPath -Arguments @('exec', '-T', 'app', 'php', 'artisan', 'ninja:create-account', "--email=$AdminEmail", "--password=$AdminPassword") | Out-Null
        Write-Note 'account created'
    }
    else {
        Write-Warn 'the database has no accounts yet'
        Write-Note "open $appUrl/setup in a browser to finish setup, or re-run this script with:"
        Write-Note "  -AdminEmail admin@example.com -AdminPassword '...'"
    }

    Write-Step 'Deploy complete'
    Write-Note "environment: $envPath"
    Write-Note "application: $appUrl"
    Write-Note "status:      docker compose --env-file `"$EnvFile`" ps"
    Write-Note "logs:        docker compose --env-file `"$EnvFile`" logs -f app"

    Invoke-Compose -EnvFile $envPath -Arguments @('ps') | Out-Null
}
finally {
    Pop-Location
}
