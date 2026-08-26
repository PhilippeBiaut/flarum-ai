import app from 'flarum/admin/app';
import ExtensionPage, { ExtensionPageAttrs } from 'flarum/admin/components/ExtensionPage';
import Button from 'flarum/common/components/Button';
import Alert from 'flarum/common/components/Alert';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Select from 'flarum/common/components/Select';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';

import SeederState from '../state/SeederState';
import PlanPreview from './PlanPreview';
import BatchProgress from './BatchProgress';

/**
 * The whole seeder in one page: connect, describe the forum, set volumes and a
 * period, preview the day-by-day calendar, then run it.
 */
export default class AiSeederPage extends ExtensionPage<ExtensionPageAttrs> {
  state!: SeederState;

  oninit(vnode: Mithril.Vnode<ExtensionPageAttrs, this>) {
    super.oninit(vnode);

    this.state = new SeederState();
    this.state.loadBatches();
  }

  onremove() {
    this.state.stopPolling();
  }

  content() {
    return (
      <div className="ExtensionPage-settings AiSeederPage">
        <div className="container">
          {this.state.error ? (
            <Alert type="error" onclose={() => (this.state.error = null)} dismissible={true}>
              {this.state.error}
            </Alert>
          ) : null}

          {this.connectionSection()}
          {this.contextSection()}
          {this.volumeSection()}
          {this.previewSection()}
          {this.runSection()}
          {this.historySection()}
        </div>
      </div>
    );
  }

  // ---------------------------------------------------------------- sections

  connectionSection() {
    const { state } = this;

    return this.section(
      'connection',
      <div>
        <div className="Form-group">
          <label>{app.translator.trans('pbiaut-ai-seeder.admin.settings.api_key_label')}</label>
          <input
            className="FormControl"
            type="password"
            autocomplete="off"
            bidi={this.setting('pbiaut-ai-seeder.api_key')}
            placeholder="sk-..."
          />
          <div className="helpText">{app.translator.trans('pbiaut-ai-seeder.admin.settings.api_key_help')}</div>
        </div>

        <div className="Form-group">
          <label>{app.translator.trans('pbiaut-ai-seeder.admin.settings.base_url_label')}</label>
          <input className="FormControl" type="text" bidi={this.setting('pbiaut-ai-seeder.base_url')} placeholder="https://api.openai.com/v1" />
          <div className="helpText">{app.translator.trans('pbiaut-ai-seeder.admin.settings.base_url_help')}</div>
        </div>

        <div className="AiSeeder-row">
          {this.numberField('pbiaut-ai-seeder.calls_per_run', 'calls_per_run', 12)}
          {this.numberField('pbiaut-ai-seeder.requests_per_minute', 'requests_per_minute', 0)}
          {this.numberField('pbiaut-ai-seeder.max_tokens', 'max_tokens', 4000)}
        </div>

        <div className="AiSeeder-row">
          {this.settingField('pbiaut-ai-seeder.temperature', 'temperature', '0.9', 'number')}
          {this.settingField('pbiaut-ai-seeder.email_domain', 'email_domain', 'example.invalid')}
          {this.settingField('pbiaut-ai-seeder.timezone', 'timezone', 'UTC')}
        </div>

        <div className="AiSeeder-row">
          {this.settingField('pbiaut-ai-seeder.price_input', 'price_input', '0', 'number')}
          {this.settingField('pbiaut-ai-seeder.price_output', 'price_output', '0', 'number')}
          {this.settingField('pbiaut-ai-seeder.currency', 'currency', 'USD')}
        </div>

        <div className="AiSeeder-actions">
          {this.submitButton()}
          <Button className="Button" icon="fas fa-plug" loading={state.loadingModels} onclick={() => state.loadModels()}>
            {app.translator.trans('pbiaut-ai-seeder.admin.connection.test')}
          </Button>
        </div>

        {state.notice ? (
          <Alert type="success" dismissible={false}>
            {state.notice}
          </Alert>
        ) : null}

        {state.modelsLoaded ? (
          <div className="Form-group">
            <label>{app.translator.trans('pbiaut-ai-seeder.admin.form.model')}</label>
            <Select
              value={state.form.model}
              options={Object.fromEntries(state.models.map((model) => [model, model]))}
              onchange={(value: string) => (state.form.model = value)}
            />
            <div className="helpText">{app.translator.trans('pbiaut-ai-seeder.admin.form.model_help')}</div>
          </div>
        ) : null}
      </div>
    );
  }

  contextSection() {
    const { form } = this.state;

    return this.section(
      'context',
      <div>
        <div className="AiSeeder-row">
          {this.formField('language', app.translator.trans('pbiaut-ai-seeder.admin.form.language'), 'text', 'français')}
          {this.formField('tone', app.translator.trans('pbiaut-ai-seeder.admin.form.tone'), 'text')}
        </div>

        <div className="Form-group">
          <label>{app.translator.trans('pbiaut-ai-seeder.admin.form.theme')}</label>
          <textarea
            className="FormControl"
            rows="3"
            value={form.theme}
            oninput={(e: InputEvent) => (form.theme = (e.target as HTMLTextAreaElement).value)}
          />
          <div className="helpText">{app.translator.trans('pbiaut-ai-seeder.admin.form.theme_help')}</div>
        </div>

        <div className="Form-group">
          <label>{app.translator.trans('pbiaut-ai-seeder.admin.form.audience')}</label>
          <textarea
            className="FormControl"
            rows="2"
            value={form.audience}
            oninput={(e: InputEvent) => (form.audience = (e.target as HTMLTextAreaElement).value)}
          />
        </div>

        {this.tagsField()}
      </div>
    );
  }

  volumeSection() {
    const { form } = this.state;

    return this.section(
      'volumes',
      <div>
        <div className="AiSeeder-row">
          {this.formField('users', app.translator.trans('pbiaut-ai-seeder.admin.form.users'), 'number')}
          {this.formField('discussions', app.translator.trans('pbiaut-ai-seeder.admin.form.discussions'), 'number')}
          {this.formField('replies', app.translator.trans('pbiaut-ai-seeder.admin.form.replies'), 'number')}
        </div>

        <div className="AiSeeder-row">
          {this.formField('date_start', app.translator.trans('pbiaut-ai-seeder.admin.form.date_start'), 'date')}
          {this.formField('date_end', app.translator.trans('pbiaut-ai-seeder.admin.form.date_end'), 'date')}
          <div className="Form-group">
            <label>{app.translator.trans('pbiaut-ai-seeder.admin.form.distribution')}</label>
            <Select
              value={form.distribution}
              options={{
                organic: extractText(app.translator.trans('pbiaut-ai-seeder.admin.form.distribution_organic')),
                uniform: extractText(app.translator.trans('pbiaut-ai-seeder.admin.form.distribution_uniform')),
                random: extractText(app.translator.trans('pbiaut-ai-seeder.admin.form.distribution_random')),
              }}
              onchange={(value: string) => (form.distribution = value)}
            />
          </div>
        </div>

        <div className="AiSeeder-row">
          {this.formField('hour_start', app.translator.trans('pbiaut-ai-seeder.admin.form.hour_start'), 'number')}
          {this.formField('hour_end', app.translator.trans('pbiaut-ai-seeder.admin.form.hour_end'), 'number')}
          {this.formField('replies_min', app.translator.trans('pbiaut-ai-seeder.admin.form.replies_min'), 'number')}
          {this.formField('replies_max', app.translator.trans('pbiaut-ai-seeder.admin.form.replies_max'), 'number')}
        </div>

        <div className="AiSeeder-row">
          {this.formField('reply_window_days', app.translator.trans('pbiaut-ai-seeder.admin.form.reply_window'), 'number')}
          {this.formField('seed', app.translator.trans('pbiaut-ai-seeder.admin.form.seed'), 'number')}
        </div>

        <div className="AiSeeder-actions">
          <Button className="Button Button--primary" icon="fas fa-calendar-alt" loading={this.state.planning} onclick={() => this.state.plan()}>
            {app.translator.trans('pbiaut-ai-seeder.admin.form.compute_plan')}
          </Button>
          <span className="helpText">{app.translator.trans('pbiaut-ai-seeder.admin.form.compute_plan_help')}</span>
        </div>
      </div>
    );
  }

  previewSection() {
    const { preview } = this.state;

    if (!preview) return null;

    return this.section(
      'preview',
      <div>
        <PlanPreview days={preview.days} totals={preview.totals} warnings={preview.warnings} estimate={preview.estimate} />

        {preview.estimate.prices_missing ? (
          <Alert type="warning" dismissible={false}>
            {app.translator.trans('pbiaut-ai-seeder.admin.preview.no_prices')}
          </Alert>
        ) : null}

        <div className="AiSeeder-actions">
          <Button className="Button Button--primary" icon="fas fa-rocket" loading={this.state.starting} onclick={() => this.confirmStart()}>
            {app.translator.trans('pbiaut-ai-seeder.admin.form.start')}
          </Button>
          <span className="helpText">
            {app.translator.trans('pbiaut-ai-seeder.admin.form.start_help', { runs: preview.estimate.queue_runs })}
          </span>
        </div>
      </div>
    );
  }

  runSection() {
    const { active } = this.state;

    if (!active) return null;

    return this.section('run', <BatchProgress batch={active} state={this.state} />);
  }

  historySection() {
    const { batches } = this.state;

    if (!batches.length) return null;

    return this.section(
      'history',
      <div className="AiSeeder-dayTable-scroll">
        <table className="AiSeeder-table">
          <thead>
            <tr>
              <th>#</th>
              <th>{app.translator.trans('pbiaut-ai-seeder.admin.history.status')}</th>
              <th>{app.translator.trans('pbiaut-ai-seeder.admin.history.period')}</th>
              <th>{app.translator.trans('pbiaut-ai-seeder.admin.history.created')}</th>
              <th>{app.translator.trans('pbiaut-ai-seeder.admin.history.tokens')}</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {batches.map((batch) => (
              <tr>
                <td>{batch.id}</td>
                <td>
                  <span className={`AiSeeder-badge AiSeeder-badge--${batch.status}`}>
                    {app.translator.trans(`pbiaut-ai-seeder.admin.status.${batch.status}`)}
                  </span>
                </td>
                <td>
                  {batch.period.start} → {batch.period.end}
                </td>
                <td>
                  {batch.created.users} / {batch.created.discussions} / {batch.created.replies}
                </td>
                <td>{batch.usage.tokens_in + batch.usage.tokens_out}</td>
                <td>
                  <Button className="Button Button--text" onclick={() => this.state.show(batch.id)}>
                    {app.translator.trans('pbiaut-ai-seeder.admin.history.open')}
                  </Button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    );
  }

  // ----------------------------------------------------------------- helpers

  section(key: string, body: Mithril.Children) {
    return (
      <section className="AiSeeder-section">
        <h3>{app.translator.trans(`pbiaut-ai-seeder.admin.sections.${key}`)}</h3>
        <div className="helpText">{app.translator.trans(`pbiaut-ai-seeder.admin.sections.${key}_help`)}</div>
        {body}
      </section>
    );
  }

  formField(key: keyof SeederState['form'], label: Mithril.Children, type = 'text', placeholder = '') {
    const { form } = this.state;

    return (
      <div className="Form-group">
        <label>{label}</label>
        <input
          className="FormControl"
          type={type}
          placeholder={placeholder}
          value={(form as any)[key] ?? ''}
          oninput={(e: InputEvent) => {
            const value = (e.target as HTMLInputElement).value;
            (form as any)[key] = type === 'number' ? (value === '' ? '' : Number(value)) : value;
          }}
        />
      </div>
    );
  }

  /**
   * The default is shown as a placeholder rather than pre-filled, so an
   * untouched field stays empty and the PHP-side default keeps applying (and the
   * Save button does not light up on page load).
   */
  settingField(setting: string, labelKey: string, placeholder = '', type = 'text') {
    return (
      <div className="Form-group">
        <label>{app.translator.trans(`pbiaut-ai-seeder.admin.settings.${labelKey}`)}</label>
        <input className="FormControl" type={type} placeholder={placeholder} bidi={this.setting(setting)} />
      </div>
    );
  }

  numberField(setting: string, labelKey: string, fallback: number) {
    return this.settingField(setting, labelKey, String(fallback), 'number');
  }

  /**
   * Tags are written as one hierarchical path per line. Anything that already
   * exists is reused; anything missing is created when the run starts. Without
   * flarum/tags the field is hidden and discussions are simply left untagged.
   */
  tagsField() {
    if (!app.store.all('tags').length && !this.state.form.tags) return null;

    return (
      <div className="Form-group">
        <label>{app.translator.trans('pbiaut-ai-seeder.admin.form.tags')}</label>
        <textarea
          className="FormControl AiSeeder-tagPaths"
          rows="6"
          spellcheck={false}
          placeholder={extractText(app.translator.trans('pbiaut-ai-seeder.admin.form.tags_placeholder'))}
          value={this.state.form.tags}
          oninput={(e: InputEvent) => (this.state.form.tags = (e.target as HTMLTextAreaElement).value)}
        />
        <div className="helpText">{app.translator.trans('pbiaut-ai-seeder.admin.form.tags_help')}</div>
        <Button className="Button Button--text" icon="fas fa-download" onclick={() => this.loadExistingTags()}>
          {app.translator.trans('pbiaut-ai-seeder.admin.form.tags_load')}
        </Button>
      </div>
    );
  }

  /** Fills the textarea with the forum's current tag hierarchy. */
  loadExistingTags() {
    const tags = (app.store.all('tags') as any[]) || [];
    const lines: string[] = [];

    const isChild = (tag: any) => !!(tag.parent && tag.parent());

    tags
      .filter((tag) => !isChild(tag))
      .sort((a, b) => (a.position() ?? 999) - (b.position() ?? 999))
      .forEach((parent) => {
        const children = tags.filter((tag) => isChild(tag) && tag.parent().id() === parent.id());

        if (!children.length) {
          lines.push(parent.name());
          return;
        }

        // A parent that has children is browsed through them, so only the
        // leaves are worth seeding into.
        children
          .sort((a, b) => (a.position() ?? 999) - (b.position() ?? 999))
          .forEach((child) => lines.push(`${parent.name()} > ${child.name()}`));
      });

    this.state.form.tags = lines.join('\n');
  }

  confirmStart() {
    const preview = this.state.preview;

    if (!preview) return;

    const total = preview.totals.discussions + preview.totals.replies;

    if (total > 200) {
      const message = extractText(
        app.translator.trans('pbiaut-ai-seeder.admin.form.start_confirm', {
          count: total,
          calls: preview.estimate.api_calls,
        })
      );

      if (!confirm(message)) return;
    }

    this.state.start();
  }
}
