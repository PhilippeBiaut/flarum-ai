#!/usr/bin/env bash
#
# Installs the AI Seeder extension into a Flarum forum.
#
# Run it from the FORUM root (the directory holding composer.json and the
# `flarum` binary), not from the extension:
#
#     bash install.sh                # tracks the main branch
#     bash install.sh '^0.1'         # or any Composer constraint
#
# It registers the GitHub repository with Composer, relaxes minimum-stability
# just enough to accept Flarum 2.0's release candidate, installs the package,
# migrates and clears the cache. Anything it changed is rolled back on failure.

set -euo pipefail

REPO_URL="https://github.com/PhilippeBiaut/flarum-ai.git"
REPO_KEY="flarum-ai"
PACKAGE="pbiaut/flarum-ai-seeder"
CONSTRAINT="${1:-dev-main}"

say() { printf '\033[1;34m==>\033[0m %s\n' "$1"; }
warn() { printf '\033[1;33m!! \033[0m %s\n' "$1" >&2; }
die() { printf '\033[1;31mxx \033[0m %s\n' "$1" >&2; exit 1; }

CHANGED_STABILITY=0

# --- sanity checks ----------------------------------------------------------

command -v composer >/dev/null 2>&1 || die "Composer is not on the PATH."

[ -f composer.json ] || die "No composer.json here. Run this from the forum root, not from the extension folder."

grep -q '"flarum/core"' composer.json \
  || die "This composer.json does not require flarum/core. Are you in the forum root?"

[ -f flarum ] || warn "No 'flarum' binary in this directory. Migrations will be skipped."

# --- refuse to silently upgrade the forum -----------------------------------
#
# This extension targets Flarum 2.0. On a 1.x forum, installing it with a
# relaxed stability would let Composer "solve" the conflict by dragging
# flarum/core from 1.8 to a 2.0 release candidate - a major upgrade that breaks
# every extension not ready for it. That must be a deliberate decision, never a
# side effect of installing a seeder.

say "Detecting the installed Flarum version..."
CORE_VERSION="$(composer show flarum/core 2>/dev/null | awk '/^versions/ {print $NF; exit}' || true)"

if [ -z "$CORE_VERSION" ]; then
  warn "Could not read the installed flarum/core version; continuing anyway."
else
  say "flarum/core $CORE_VERSION"

  case "$CORE_VERSION" in
    v1.*|1.*)
      echo
      die "This forum runs Flarum $CORE_VERSION, and the extension requires ^2.0.0-rc.1.
    Installing it here would upgrade flarum/core to a 2.0 release candidate and
    break every extension that is not 2.0-ready. Refusing to do that silently.

    Either upgrade the forum to 2.0 deliberately (with a backup and a staging
    run first), or ask for a 1.x version of the extension."
      ;;
  esac
fi

# --- register the repository ------------------------------------------------
#
# Composer only ever looks at Packagist unless told otherwise, and this package
# is not published there.

say "Registering $REPO_URL with Composer..."
composer config "repositories.$REPO_KEY" vcs "$REPO_URL"

# --- allow release candidates -----------------------------------------------
#
# Flarum 2.0 is still an RC. With the default `stable` setting Composer sees the
# package and refuses it: "does not match your minimum-stability". RC is the
# smallest relaxation that works; prefer-stable keeps every *other* dependency
# on its stable release.

CURRENT_STABILITY="$(composer config minimum-stability 2>/dev/null || echo stable)"

case "$CURRENT_STABILITY" in
  dev|alpha|beta|RC)
    say "minimum-stability is already '$CURRENT_STABILITY', leaving it alone."
    ;;
  *)
    say "Relaxing minimum-stability from '$CURRENT_STABILITY' to 'RC' (Flarum 2.0 is a release candidate)..."
    composer config minimum-stability RC
    composer config prefer-stable true
    CHANGED_STABILITY=1
    ;;
esac

rollback() {
  warn "Installation failed - undoing the changes to composer.json."
  composer config --unset "repositories.$REPO_KEY" || true

  if [ "$CHANGED_STABILITY" = "1" ]; then
    composer config minimum-stability "$CURRENT_STABILITY" || true
  fi
}

# --- install ----------------------------------------------------------------

say "Installing $PACKAGE:$CONSTRAINT ..."
say "Dry run first, so you can see what would change..."

if ! composer require "$PACKAGE:$CONSTRAINT" --no-interaction --dry-run; then
  rollback
  echo
  die "The dry run failed - nothing was installed. The Composer output above says why."
fi

echo
read -r -p "Proceed with the installation above? [y/N] " answer

case "$answer" in
  [yY]|[yY][eE][sS]) ;;
  *) rollback; die "Cancelled." ;;
esac

if ! composer require "$PACKAGE:$CONSTRAINT" --no-interaction; then
  rollback
  echo
  die "Composer could not install the package. The output above says why."
fi

# --- finish -----------------------------------------------------------------

if [ -f flarum ]; then
  say "Running migrations..."
  php flarum migrate

  say "Clearing the cache..."
  php flarum cache:clear
fi

cat <<'DONE'

Installed.

Next:
  1. Admin panel -> Extensions -> enable "AI Seeder", then open its page.
  2. Paste your OpenAI API key and hit "Test the connection".
  3. Make sure a queue worker is running, otherwise nothing will generate:

       php flarum queue:work

     Flarum's default queue driver is `sync`, which runs jobs inside the HTTP
     request - a large run would simply time out. Install a real driver
     (flarum/redis or equivalent) first.

     No worker possible? Everything also works from the command line:

       php flarum ai-seeder:run --users=20 --discussions=50 --replies=300 \
           --from=2026-01-01 --to=2026-05-31 --dry-run

DONE
