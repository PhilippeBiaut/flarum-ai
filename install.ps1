<#
    Installs the AI Seeder extension into a Flarum forum (Windows / PowerShell).

    Run it from the FORUM root - the directory holding composer.json and the
    `flarum` binary - not from the extension folder:

        powershell -ExecutionPolicy Bypass -File install.ps1
        powershell -ExecutionPolicy Bypass -File install.ps1 -Constraint '^0.1'
#>

param(
    [string] $Constraint = 'dev-main'
)

$ErrorActionPreference = 'Continue'

$RepoUrl = 'https://github.com/PhilippeBiaut/flarum-ai.git'
$RepoKey = 'flarum-ai'
$Package = 'pbiaut/flarum-ai-seeder'

function Say  ($m) { Write-Host "==> $m" -ForegroundColor Cyan }
function Warn ($m) { Write-Host "!!  $m" -ForegroundColor Yellow }
function Die  ($m) { Write-Host "xx  $m" -ForegroundColor Red; exit 1 }

# --- sanity checks ----------------------------------------------------------

if (-not (Get-Command composer -ErrorAction SilentlyContinue)) { Die 'Composer is not on the PATH.' }
if (-not (Test-Path composer.json)) { Die 'No composer.json here. Run this from the forum root, not from the extension folder.' }
if (-not (Select-String -Path composer.json -Pattern '"flarum/core"' -Quiet)) { Die 'This composer.json does not require flarum/core. Are you in the forum root?' }

$hasFlarumBinary = Test-Path flarum
if (-not $hasFlarumBinary) { Warn "No 'flarum' binary in this directory. Migrations will be skipped." }

# --- refuse to silently upgrade the forum -----------------------------------
#
# This extension targets Flarum 2.0. On a 1.x forum, relaxing stability would
# let Composer "solve" the conflict by dragging flarum/core up to a 2.0 release
# candidate - a major upgrade that breaks every extension not ready for it.

Say 'Detecting the installed Flarum version...'

$coreVersion = ''
$shown = composer show flarum/core 2>$null

if ($shown) {
    $line = $shown | Select-String -Pattern '^versions' | Select-Object -First 1
    if ($line) { $coreVersion = ($line -split '\s+')[-1] }
}

if (-not $coreVersion) {
    Warn 'Could not read the installed flarum/core version; continuing anyway.'
} else {
    Say "flarum/core $coreVersion"

    if ($coreVersion -match '^v?1\.') {
        Die "This forum runs Flarum $coreVersion, and the extension requires ^2.0.0-rc.1.
    Installing it here would upgrade flarum/core to a 2.0 release candidate and
    break every extension that is not 2.0-ready. Refusing to do that silently.

    Either upgrade the forum to 2.0 deliberately (with a backup and a staging
    run first), or ask for a 1.x version of the extension."
    }
}

# --- register the repository ------------------------------------------------

Say "Registering $RepoUrl with Composer..."
composer config "repositories.$RepoKey" vcs $RepoUrl

# --- allow release candidates -----------------------------------------------
#
# Flarum 2.0 is still an RC. With the default `stable` setting Composer sees the
# package and refuses it: "does not match your minimum-stability".

$currentStability = (composer config minimum-stability 2>$null)
if (-not $currentStability) { $currentStability = 'stable' }

$changedStability = $false

if ('dev', 'alpha', 'beta', 'RC' -contains $currentStability) {
    Say "minimum-stability is already '$currentStability', leaving it alone."
} else {
    Say "Relaxing minimum-stability from '$currentStability' to 'RC' (Flarum 2.0 is a release candidate)..."
    composer config minimum-stability RC
    composer config prefer-stable true
    $changedStability = $true
}

function Rollback {
    Warn 'Installation failed - undoing the changes to composer.json.'
    composer config --unset "repositories.$RepoKey"
    if ($changedStability) { composer config minimum-stability $currentStability }
}

# --- install ----------------------------------------------------------------

Say "Dry run for ${Package}:${Constraint}, so you can see what would change..."
composer require "${Package}:${Constraint}" --no-interaction --dry-run

if (-not $?) { Rollback; Die 'The dry run failed - nothing was installed. The Composer output above says why.' }

$answer = Read-Host "`nProceed with the installation above? [y/N]"

if ($answer -notmatch '^(y|yes)$') { Rollback; Die 'Cancelled.' }

composer require "${Package}:${Constraint}" --no-interaction

if (-not $?) { Rollback; Die 'Composer could not install the package. The output above says why.' }

# --- finish -----------------------------------------------------------------

if ($hasFlarumBinary) {
    Say 'Running migrations...'
    php flarum migrate

    Say 'Clearing the cache...'
    php flarum cache:clear
}

Write-Host @'

Installed.

Next:
  1. Admin panel -> Extensions -> enable "AI Seeder", then open its page.
  2. Paste your OpenAI API key and hit "Test the connection".
  3. Make sure a queue worker is running, otherwise nothing will generate:

       php flarum queue:work

     Flarum's default queue driver is `sync`, which runs jobs inside the HTTP
     request - a large run would simply time out. Install a real driver
     (flarum/redis or equivalent) first.

'@
