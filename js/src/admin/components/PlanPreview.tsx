import Component, { ComponentAttrs } from 'flarum/common/Component';
import app from 'flarum/admin/app';
import Alert from 'flarum/common/components/Alert';
import type Mithril from 'mithril';

import type { Estimate, PlanDay, PlanTotals } from '../state/SeederState';

export interface PlanPreviewAttrs extends ComponentAttrs {
  days: PlanDay[];
  totals: PlanTotals;
  warnings?: string[];
  estimate?: Estimate | null;
}

/**
 * The "day by day" screen: how many signups, discussions and replies land on
 * each day of the requested period, shown before a single token is spent.
 */
export default class PlanPreview extends Component<PlanPreviewAttrs> {
  /** Days are collapsed into weeks past this many, so five months stay readable. */
  static readonly MAX_BARS = 120;

  view(vnode: Mithril.Vnode<PlanPreviewAttrs, this>) {
    const { days, totals, warnings = [], estimate } = this.attrs;

    if (!days || !days.length) return null;

    return (
      <div className="AiSeeder-preview">
        {this.summary(totals, estimate)}

        {warnings.map((warning) => (
          <Alert type="warning" dismissible={false}>
            {warning}
          </Alert>
        ))}

        {this.chart(days)}
        {this.table(days)}
      </div>
    );
  }

  summary(totals: PlanTotals, estimate?: Estimate | null) {
    const tiles: [string, Mithril.Children][] = [
      [app.translator.trans('pbiaut-ai-seeder.admin.preview.members') as any, totals.users],
      [app.translator.trans('pbiaut-ai-seeder.admin.preview.discussions') as any, totals.discussions],
      [app.translator.trans('pbiaut-ai-seeder.admin.preview.replies') as any, totals.replies],
      [app.translator.trans('pbiaut-ai-seeder.admin.preview.days') as any, totals.days],
      [app.translator.trans('pbiaut-ai-seeder.admin.preview.per_day') as any, `${totals.avg_discussions_per_day} / ${totals.avg_replies_per_day}`],
      [app.translator.trans('pbiaut-ai-seeder.admin.preview.per_thread') as any, totals.avg_replies_per_discussion],
    ];

    if (estimate) {
      tiles.push([app.translator.trans('pbiaut-ai-seeder.admin.preview.api_calls') as any, estimate.api_calls]);
      tiles.push([
        app.translator.trans('pbiaut-ai-seeder.admin.preview.cost') as any,
        estimate.cost === null
          ? app.translator.trans('pbiaut-ai-seeder.admin.preview.cost_unknown')
          : `~ ${estimate.cost} ${estimate.currency}`,
      ]);
    }

    return (
      <div className="AiSeeder-tiles">
        {tiles.map(([label, value]) => (
          <div className="AiSeeder-tile">
            <div className="AiSeeder-tile-value">{value}</div>
            <div className="AiSeeder-tile-label">{label}</div>
          </div>
        ))}
      </div>
    );
  }

  /**
   * Inline SVG rather than a charting library: it is a stacked bar chart of two
   * series, and shipping a dependency for that would be silly.
   */
  chart(days: PlanDay[]) {
    const buckets = this.bucket(days);
    const peak = Math.max(1, ...buckets.map((bucket) => bucket.discussions + bucket.replies));

    const width = 100;
    const height = 30;
    const barWidth = width / buckets.length;

    return (
      <div className="AiSeeder-chart">
        <svg viewBox={`0 0 ${width} ${height}`} preserveAspectRatio="none" role="img" aria-label="Activity per day">
          {buckets.map((bucket, index) => {
            const total = bucket.discussions + bucket.replies;
            const totalHeight = (total / peak) * height;
            const discussionHeight = total ? (bucket.discussions / total) * totalHeight : 0;
            const x = index * barWidth;

            return [
              <rect
                className="AiSeeder-chart-replies"
                x={x}
                y={height - totalHeight}
                width={Math.max(0.4, barWidth - 0.15)}
                height={Math.max(0, totalHeight - discussionHeight)}
              />,
              <rect
                className="AiSeeder-chart-discussions"
                x={x}
                y={height - discussionHeight}
                width={Math.max(0.4, barWidth - 0.15)}
                height={discussionHeight}
              />,
            ];
          })}
        </svg>
        <div className="AiSeeder-chart-axis">
          <span>{buckets[0]?.label}</span>
          <span>{buckets[buckets.length - 1]?.label}</span>
        </div>
        <div className="AiSeeder-legend">
          <span className="AiSeeder-legend-discussions">{app.translator.trans('pbiaut-ai-seeder.admin.preview.discussions')}</span>
          <span className="AiSeeder-legend-replies">{app.translator.trans('pbiaut-ai-seeder.admin.preview.replies')}</span>
        </div>
      </div>
    );
  }

  /** Groups days into weeks when the period is too long to show one bar per day. */
  bucket(days: PlanDay[]) {
    const size = Math.ceil(days.length / PlanPreview.MAX_BARS) || 1;
    const buckets: { label: string; signups: number; discussions: number; replies: number }[] = [];

    for (let i = 0; i < days.length; i += size) {
      const slice = days.slice(i, i + size);

      buckets.push({
        label: slice[0].date,
        signups: slice.reduce((sum, day) => sum + day.signups, 0),
        discussions: slice.reduce((sum, day) => sum + day.discussions, 0),
        replies: slice.reduce((sum, day) => sum + day.replies, 0),
      });
    }

    return buckets;
  }

  table(days: PlanDay[]) {
    const active = days.filter((day) => day.signups + day.discussions + day.replies > 0);

    return (
      <details className="AiSeeder-dayTable">
        <summary>
          {app.translator.trans('pbiaut-ai-seeder.admin.preview.show_table', { count: active.length })}
        </summary>
        <div className="AiSeeder-dayTable-scroll">
          <table className="AiSeeder-table">
            <thead>
              <tr>
                <th>{app.translator.trans('pbiaut-ai-seeder.admin.preview.date')}</th>
                <th>{app.translator.trans('pbiaut-ai-seeder.admin.preview.signups')}</th>
                <th>{app.translator.trans('pbiaut-ai-seeder.admin.preview.discussions')}</th>
                <th>{app.translator.trans('pbiaut-ai-seeder.admin.preview.replies')}</th>
              </tr>
            </thead>
            <tbody>
              {active.map((day) => (
                <tr>
                  <td>{day.date}</td>
                  <td>{day.signups || ''}</td>
                  <td>{day.discussions || ''}</td>
                  <td>{day.replies || ''}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </details>
    );
  }
}
