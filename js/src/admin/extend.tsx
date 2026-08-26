import Extend from 'flarum/common/extenders';
import app from 'flarum/admin/app';

import AiSeederPage from './components/AiSeederPage';

export default [
  new Extend.Admin()
    .page(AiSeederPage)
    .setting(
      () => ({
        setting: 'pbiaut-ai-seeder.api_key',
        type: 'password',
        label: app.translator.trans('pbiaut-ai-seeder.admin.settings.api_key_label'),
        help: app.translator.trans('pbiaut-ai-seeder.admin.settings.api_key_help'),
      }),
      100
    )
    .setting(
      () => ({
        setting: 'pbiaut-ai-seeder.base_url',
        type: 'text',
        placeholder: 'https://api.openai.com/v1',
        label: app.translator.trans('pbiaut-ai-seeder.admin.settings.base_url_label'),
        help: app.translator.trans('pbiaut-ai-seeder.admin.settings.base_url_help'),
      }),
      90
    ),
];
