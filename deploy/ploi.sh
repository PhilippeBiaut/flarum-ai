# Ploi deploy script for a Flarum forum running the AI Seeder extension.
#
# Paste this into Ploi: Site -> Deploy -> Deploy script.
# Replace the path on the first line with your own site directory.
#
# It is idempotent: the first deploy installs the extension, every later deploy
# updates it. `composer config` simply rewrites the same entry each time.

cd /home/ploi/example.com

echo "🚀 Deploying..."

# --- Where the extension lives ----------------------------------------------
#
# pbiaut/flarum-ai-seeder is not published on Packagist, and `composer require`
# only ever looks at Packagist. Without this entry Composer reports
# "could not be found in any version", whatever the repository's visibility.
#
# Type `git`, not `vcs`, on purpose. `vcs` lets Composer pick its GitHub driver,
# which talks to the GitHub API first - and when that fails (no OAuth token, or
# the unauthenticated IP quota is spent) it falls back by *generating an SSH
# URL* of its own, giving "git@github.com: Permission denied (publickey)" on a
# server that has no deploy key. The plain git driver just clones the HTTPS URL
# it was given, which needs no credentials at all for a public repository.

composer config repositories.flarum-ai git https://github.com/PhilippeBiaut/flarum-ai.git

# For github.com URLs Composer tries a list of protocols in turn and only ever
# reports the last failure - which is why a broken HTTPS clone surfaces as
# "git@github.com: Permission denied (publickey)" on a server with no deploy
# key. Pinning the protocol keeps it on HTTPS and makes real errors visible.

composer config --global github-protocol https

# --- Install or update -------------------------------------------------------
#
# The extension requires flarum/core ^1.8, so nothing needs to be relaxed:
# no minimum-stability change, no forum upgrade. `dev-main` carries its own
# stability flag, which Composer adds to composer.json automatically.
#
# No --no-dev here: `composer require` does not accept it (only
# `--update-no-dev`), and this forum installs its dev dependencies today. A
# deploy should not quietly change that.

if composer show pbiaut/flarum-ai-seeder > /dev/null 2>&1; then
    composer update pbiaut/flarum-ai-seeder --no-interaction --prefer-dist --optimize-autoloader
else
    composer require pbiaut/flarum-ai-seeder:dev-main --no-interaction --prefer-dist --optimize-autoloader
fi

# --- Flarum ------------------------------------------------------------------

php flarum migrate
php flarum cache:clear

# --- Queue worker ------------------------------------------------------------
#
# Generation runs as background jobs, so a worker must be running as a Ploi
# daemon (`php flarum queue:work`). After deploying new code the daemon is still
# running the old one, so restart it. Find the id under Ploi -> Server ->
# Daemons and uncomment the line below with it.
#
# Flarum has no `queue:restart` command, hence going through supervisor.
#
# sudo supervisorctl restart daemon-123456:*

echo "🌥 Deployed."
