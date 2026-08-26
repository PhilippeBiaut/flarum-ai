# Ploi deploy script for a Flarum forum running the AI Seeder extension.
#
# Paste into Ploi: Site -> Deploy -> Deploy script.
# Replace the path on the first line with your own site directory.

cd /home/ploi/example.com

echo "🚀 Deploying..."

# The repository entry must be type `git`, not `vcs`. With `vcs`, Composer uses
# its GitHub driver, which calls the GitHub API first and - when that fails for
# want of a token - falls back to an SSH URL it generates itself, giving
# "git@github.com: Permission denied (publickey)" on a server with no deploy
# key. An entry stored as an array element cannot be retyped in place, so it is
# removed first.

composer config --unset repositories.0 || true
composer config repositories.flarum-ai git https://github.com/PhilippeBiaut/flarum-ai.git
composer config --global github-protocol https

# The bare mirror created by an earlier failed attempt remembers the SSH URL and
# gets reused as-is, so it has to go too.
rm -rf ~/.cache/composer/vcs/git-github.com-PhilippeBiaut-flarum-ai.git

# No --no-dev: `composer require` does not accept it (only `--update-no-dev`),
# and this forum installs its dev dependencies today.
if composer show pbiaut/flarum-ai-seeder > /dev/null 2>&1; then
    composer update pbiaut/flarum-ai-seeder --no-interaction --prefer-dist --optimize-autoloader
else
    composer require pbiaut/flarum-ai-seeder:dev-main --no-interaction --prefer-dist --optimize-autoloader
fi

php flarum migrate
php flarum cache:clear

# Reload PHP-FPM, or nothing above is visible to the web request.
#
# OPcache keeps vendor/composer/autoload_static.php compiled in FPM's memory.
# After installing a new package the CLI sees the fresh autoloader while FPM
# still serves the old one, which surfaces as
# "Class Pbiaut\AiSeeder\... does not exist" on every page load, no matter how
# many times you dump-autoload or clear Flarum's cache.
#
# Match the version to the site's PHP.

echo "" | sudo -S service php8.4-fpm reload

# Generation runs as background jobs: a `php flarum queue:work` daemon must be
# running, and restarted here after a deploy. Get its id from Ploi -> Server ->
# Daemons. Flarum has no queue:restart command, hence supervisor.
#
# sudo supervisorctl restart daemon-123456:*

echo "🌥 Deployed."
