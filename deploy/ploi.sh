# Ploi deploy script for a Flarum forum running the AI Seeder extension.
#
# Paste this into Ploi: Site -> Deploy -> Deploy script.
# Replace the path on the first line with your own site directory.
#
# It is idempotent: the first deploy installs the extension, every later deploy
# updates it. `composer config` simply rewrites the same entries each time.

cd /home/ploi/example.com

echo "🚀 Deploying..."

# --- Where the extension lives ----------------------------------------------
#
# pbiaut/flarum-ai-seeder is not published on Packagist, and `composer require`
# only ever looks at Packagist. Without this entry Composer reports
# "could not be found in any version", whatever the repository's visibility.

composer config repositories.flarum-ai vcs https://github.com/PhilippeBiaut/flarum-ai.git

# --- Release candidates ------------------------------------------------------
#
# The extension requires flarum/core ^2.0.0-rc.1, and an RC is not "stable".
# With Composer's default setting it is found and then refused:
# "does not match your minimum-stability".
#
# prefer-stable keeps every OTHER dependency on its stable release, so this
# relaxation only applies where it is actually needed.
#
# Drop these two lines once Flarum 2.0 final is out.

composer config minimum-stability RC
composer config prefer-stable true

# --- Install or update -------------------------------------------------------

if composer show pbiaut/flarum-ai-seeder > /dev/null 2>&1; then
    composer update pbiaut/flarum-ai-seeder --no-interaction --prefer-dist --no-dev --optimize-autoloader
else
    composer require pbiaut/flarum-ai-seeder:dev-main -W --no-interaction --prefer-dist --no-dev --optimize-autoloader
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
