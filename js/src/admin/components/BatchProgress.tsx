import Component, { ComponentAttrs } from 'flarum/common/Component';
import app from 'flarum/admin/app';
import Button from 'flarum/common/components/Button';
import Alert from 'flarum/common/components/Alert';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';

import type { Batch } from '../state/SeederState';
import type SeederState from '../state/SeederState';

export interface BatchProgressAttrs extends ComponentAttrs {
  batch: Batch;
  state: SeederState;
}

/** Live view of a run: progress bar, counters, and the lifecycle buttons. */
export default class BatchProgress extends Component<BatchProgressAttrs> {
  view(vnode: Mithril.Vnode<BatchProgressAttrs, this>) {
    const { batch, state } = this.attrs;
    const live = state.isLive(batch);

    return (
      <div className={`AiSeeder-progress AiSeeder-progress--${batch.status}`}>
        <div className="AiSeeder-progress-head">
          <strong>
            {app.translator.trans('pbiaut-ai-seeder.admin.run.title', { id: batch.id })}
          </strong>
          <span className={`AiSeeder-badge AiSeeder-badge--${batch.status}`}>
            {app.translator.trans(`pbiaut-ai-seeder.admin.status.${batch.status}`)}
          </span>
        </div>

        <div className="AiSeeder-bar">
          <div className="AiSeeder-bar-fill" style={{ width: `${Math.min(100, batch.progress)}%` }} />
        </div>
        <div className="AiSeeder-bar-label">{batch.progress}%</div>

        <ul className="AiSeeder-counters">
          <li>{app.translator.trans('pbiaut-ai-seeder.admin.run.members', { done: batch.created.users, total: batch.planned.users })}</li>
          <li>
            {app.translator.trans('pbiaut-ai-seeder.admin.run.discussions', {
              done: batch.created.discussions,
              total: batch.planned.discussions,
            })}
          </li>
          <li>{app.translator.trans('pbiaut-ai-seeder.admin.run.replies', { done: batch.created.replies, total: batch.planned.replies })}</li>
          <li>
            {app.translator.trans('pbiaut-ai-seeder.admin.run.usage', {
              calls: batch.usage.api_calls,
              tokens: batch.usage.tokens_in + batch.usage.tokens_out,
            })}
            {batch.cost.cost !== null ? ` — ${batch.cost.cost} ${batch.cost.currency}` : ''}
          </li>
          {batch.failed > 0 ? <li className="AiSeeder-failed">{app.translator.trans('pbiaut-ai-seeder.admin.run.failed', { count: batch.failed })}</li> : null}
        </ul>

        {batch.error ? (
          <Alert type="error" dismissible={false}>
            {batch.error}
          </Alert>
        ) : null}

        {batch.status === 'queued' && !batch.started_at ? (
          <Alert type="warning" dismissible={false}>
            {app.translator.trans('pbiaut-ai-seeder.admin.run.waiting_for_worker')}
          </Alert>
        ) : null}

        <div className="AiSeeder-actions">
          {live ? (
            <Button className="Button" icon="fas fa-pause" onclick={() => state.setState(batch.id, 'pause')}>
              {app.translator.trans('pbiaut-ai-seeder.admin.run.pause')}
            </Button>
          ) : null}

          {batch.status === 'paused' || batch.status === 'failed' ? (
            <Button className="Button" icon="fas fa-play" onclick={() => state.setState(batch.id, 'resume')}>
              {app.translator.trans('pbiaut-ai-seeder.admin.run.resume')}
            </Button>
          ) : null}

          {live || batch.status === 'paused' ? (
            <Button className="Button" icon="fas fa-stop" onclick={() => state.setState(batch.id, 'cancel')}>
              {app.translator.trans('pbiaut-ai-seeder.admin.run.cancel')}
            </Button>
          ) : null}

          {batch.failed > 0 && !live ? (
            <Button className="Button" icon="fas fa-redo" onclick={() => state.setState(batch.id, 'retry-failed')}>
              {app.translator.trans('pbiaut-ai-seeder.admin.run.retry')}
            </Button>
          ) : null}

          {!live && batch.status !== 'reverted' ? (
            <Button className="Button Button--danger" icon="fas fa-trash" onclick={() => this.confirmRevert(batch, state)}>
              {app.translator.trans('pbiaut-ai-seeder.admin.run.revert')}
            </Button>
          ) : null}
        </div>

        {batch.recent_failures && batch.recent_failures.length ? (
          <details className="AiSeeder-failures">
            <summary>{app.translator.trans('pbiaut-ai-seeder.admin.run.failure_details')}</summary>
            <ul>
              {batch.recent_failures.map((failure) => (
                <li>
                  <code>{failure.type}</code> — {failure.error}
                </li>
              ))}
            </ul>
          </details>
        ) : null}
      </div>
    );
  }

  confirmRevert(batch: Batch, state: SeederState) {
    const total = batch.created.users + batch.created.discussions + batch.created.replies;
    const message = extractText(app.translator.trans('pbiaut-ai-seeder.admin.run.revert_confirm', { count: total, id: batch.id }));

    if (confirm(message)) {
      state.revert(batch.id);
    }
  }
}
