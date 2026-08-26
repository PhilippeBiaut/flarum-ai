import app from 'flarum/admin/app';

import AiSeederPage from './components/AiSeederPage';

/**
 * Flarum 1.x registers admin pages imperatively from an initializer. (The
 * declarative `Extend.Admin` extender only exists from 2.0 onwards.)
 *
 * The extension id is derived from the Composer package name with the
 * `flarum-` prefix stripped: pbiaut/flarum-ai-seeder -> pbiaut-ai-seeder. It
 * must match the prefix used by the settings on the PHP side.
 */
app.initializers.add('pbiaut-ai-seeder', () => {
  app.extensionData.for('pbiaut-ai-seeder').registerPage(AiSeederPage);
});
