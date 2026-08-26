import app from 'flarum/admin/app';
import extractText from 'flarum/common/utils/extractText';

export interface PlanDay {
  date: string;
  signups: number;
  discussions: number;
  replies: number;
}

export interface PlanTotals {
  users: number;
  discussions: number;
  replies: number;
  days: number;
  avg_discussions_per_day: number;
  avg_replies_per_day: number;
  avg_replies_per_discussion: number;
  peak_day: string | null;
  peak_activity: number;
}

export interface Estimate {
  api_calls: number;
  calls_breakdown: Record<string, number>;
  tokens_in: number;
  tokens_out: number;
  cost: number | null;
  currency: string;
  queue_runs: number;
  prices_missing: boolean;
  seed: number;
}

export interface Preview {
  seed: number;
  days: PlanDay[];
  totals: PlanTotals;
  warnings: string[];
  estimate: Estimate;
  config: Record<string, any>;
}

export interface Batch {
  id: number;
  status: string;
  model: string | null;
  seed: number;
  progress: number;
  planned: { users: number; discussions: number; replies: number };
  created: { users: number; discussions: number; replies: number };
  failed: number;
  pending: number;
  usage: { tokens_in: number; tokens_out: number; api_calls: number };
  cost: { cost: number | null; currency: string };
  error: string | null;
  period: { start: string | null; end: string | null };
  created_at: string | null;
  started_at: string | null;
  finished_at: string | null;
  plan?: { days: PlanDay[]; totals: PlanTotals; warnings: string[] };
  recent_failures?: { type: string; error: string }[];
}

export interface SeederForm {
  users: number;
  discussions: number;
  replies: number;
  date_start: string;
  date_end: string;
  distribution: string;
  hour_start: number;
  hour_end: number;
  replies_min: number;
  replies_max: number;
  reply_window_days: number;
  seed: number | '';
  model: string;
  language: string;
  theme: string;
  tone: string;
  audience: string;
  tags: { id: number; name: string; weight: number }[];
}

/** Statuses where the run is still moving and the screen should keep polling. */
const LIVE_STATUSES = ['queued', 'running', 'reverting'];

export default class SeederState {
  form: SeederForm;
  models: string[] = [];
  modelsLoaded = false;
  loadingModels = false;

  preview: Preview | null = null;
  planning = false;
  starting = false;

  batches: Batch[] = [];
  active: Batch | null = null;

  error: string | null = null;
  notice: string | null = null;

  private pollTimer: number | null = null;

  constructor() {
    this.form = this.defaults();
  }

  private defaults(): SeederForm {
    const saved = this.savedConfig();
    const today = new Date();
    const start = `${today.getFullYear()}-01-01`;
    const end = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-01`;

    return {
      users: 20,
      discussions: 50,
      replies: 300,
      date_start: start,
      date_end: end,
      distribution: 'organic',
      hour_start: 8,
      hour_end: 23,
      replies_min: 0,
      replies_max: 40,
      reply_window_days: 30,
      seed: '',
      model: app.data.settings['pbiaut-ai-seeder.model'] || '',
      language: '',
      theme: '',
      tone: '',
      audience: '',
      tags: [],
      ...saved,
    };
  }

  /** The last run's settings come back pre-filled; re-seeding is usually iterative. */
  private savedConfig(): Partial<SeederForm> {
    try {
      const raw = app.data.settings['pbiaut-ai-seeder.last_config'];
      if (!raw) return {};

      const parsed = JSON.parse(raw);
      // A fresh seed each time unless the admin asks for reproducibility.
      delete parsed.seed;

      return parsed;
    } catch (e) {
      return {};
    }
  }

  private url(path: string): string {
    return `${app.forum.attribute('apiUrl')}/ai-seeder${path}`;
  }

  private fail(error: any): never {
    const detail = error?.response?.errors?.[0]?.detail;
    this.error = detail || error?.message || 'Unexpected error.';
    m.redraw();
    throw error;
  }

  payload(): Record<string, any> {
    const form: Record<string, any> = { ...this.form };

    if (!form.seed) delete form.seed;

    ['users', 'discussions', 'replies', 'hour_start', 'hour_end', 'replies_min', 'replies_max', 'reply_window_days'].forEach((key) => {
      form[key] = Number(form[key]) || 0;
    });

    return form;
  }

  loadModels(): Promise<void> {
    this.loadingModels = true;
    this.error = null;

    return app
      .request<any>({ method: 'GET', url: this.url('/models') })
      .then((result) => {
        this.models = result.suggested?.length ? result.suggested : result.models;
        this.modelsLoaded = true;

        if (!this.form.model && this.models.length) {
          this.form.model = result.selected && this.models.includes(result.selected) ? result.selected : this.models[0];
        }

        this.notice = extractText(app.translator.trans('pbiaut-ai-seeder.admin.connection.ok', { count: this.models.length }));
      })
      .catch((e) => {
        this.modelsLoaded = false;
        this.fail(e);
      })
      .finally(() => {
        this.loadingModels = false;
        m.redraw();
      });
  }

  plan(): Promise<void> {
    this.planning = true;
    this.error = null;
    this.notice = null;

    return app
      .request<Preview>({ method: 'POST', url: this.url('/plan'), body: this.payload() })
      .then((preview) => {
        this.preview = preview;
        this.form.seed = preview.seed;
      })
      .catch((e) => this.fail(e))
      .finally(() => {
        this.planning = false;
        m.redraw();
      });
  }

  start(): Promise<void> {
    this.starting = true;
    this.error = null;

    return app
      .request<{ batch: Batch }>({ method: 'POST', url: this.url('/batches'), body: this.payload() })
      .then(({ batch }) => {
        this.active = batch;
        this.preview = null;
        this.startPolling();
        this.loadBatches();
      })
      .catch((e) => this.fail(e))
      .finally(() => {
        this.starting = false;
        m.redraw();
      });
  }

  loadBatches(): Promise<void> {
    return app
      .request<{ batches: Batch[] }>({ method: 'GET', url: this.url('/batches') })
      .then(({ batches }) => {
        this.batches = batches;

        const live = batches.find((batch) => LIVE_STATUSES.includes(batch.status));

        if (live && !this.active) {
          this.show(live.id);
        }

        m.redraw();
      })
      .catch(() => {
        // A failed history load must not blank the whole screen.
      });
  }

  show(id: number): Promise<void> {
    return app
      .request<{ batch: Batch }>({ method: 'GET', url: this.url(`/batches/${id}`) })
      .then(({ batch }) => {
        this.active = batch;

        if (LIVE_STATUSES.includes(batch.status)) {
          this.startPolling();
        } else {
          this.stopPolling();
        }

        m.redraw();
      })
      .catch((e) => this.fail(e));
  }

  setState(id: number, action: string): Promise<void> {
    return app
      .request<{ batch: Batch }>({ method: 'POST', url: this.url(`/batches/${id}/state`), body: { action } })
      .then(({ batch }) => {
        this.active = batch;

        if (LIVE_STATUSES.includes(batch.status)) this.startPolling();

        this.loadBatches();
      })
      .catch((e) => this.fail(e));
  }

  revert(id: number): Promise<void> {
    return app
      .request<{ batch: Batch }>({ method: 'DELETE', url: this.url(`/batches/${id}`) })
      .then(({ batch }) => {
        this.active = batch;
        this.startPolling();
        this.loadBatches();
      })
      .catch((e) => this.fail(e));
  }

  startPolling(): void {
    this.stopPolling();

    this.pollTimer = window.setInterval(() => {
      if (!this.active) return this.stopPolling();

      app
        .request<{ batch: Batch }>({ method: 'GET', url: this.url(`/batches/${this.active.id}`) })
        .then(({ batch }) => {
          this.active = batch;

          if (!LIVE_STATUSES.includes(batch.status)) {
            this.stopPolling();
            this.loadBatches();
          }

          m.redraw();
        })
        .catch(() => this.stopPolling());
    }, 2500);
  }

  stopPolling(): void {
    if (this.pollTimer !== null) {
      window.clearInterval(this.pollTimer);
      this.pollTimer = null;
    }
  }

  isLive(batch: Batch | null): boolean {
    return !!batch && LIVE_STATUSES.includes(batch.status);
  }
}
