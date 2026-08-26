<?php

namespace Pbiaut\AiSeeder\Service;

use Carbon\Carbon;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Pbiaut\AiSeeder\Model\Batch;
use Pbiaut\AiSeeder\Model\Item;
use Pbiaut\AiSeeder\Planner\Rng;

/**
 * Keeps a forum alive day after day, instead of filling it once.
 *
 * Each run creates one small batch dated today, reusing the members previous
 * runs created rather than inventing a fresh cohort - otherwise the forum would
 * end up with a new isolated set of people every morning and nobody from
 * yesterday ever posting again.
 *
 * Idempotent per day: running it hourly, or twice by accident, creates nothing
 * the second time. That matters because it spends money unattended.
 */
class DailyScheduler
{
    public function __construct(
        protected ConnectionInterface $db,
        protected SeederSettings $settings,
        protected BatchService $batches,
        protected RunLogger $logs,
    ) {
    }

    /**
     * @return Batch|null  null when nothing was due
     */
    public function runToday(bool $force = false): ?Batch
    {
        if (! $force && ! $this->settings->dailyEnabled()) {
            return null;
        }

        $today = Carbon::now($this->settings->timezone())->format('Y-m-d');

        if (! $force && $this->settings->lastDailyRun() === $today) {
            return null;
        }

        $config = $this->configForToday($today);

        if ($config === null) {
            return null;
        }

        $batch = $this->batches->create($config);

        // Marked only once the batch exists, so a failure part-way leaves the
        // day still due rather than silently skipped.
        $this->settings->markDailyRun($today);

        $this->logs->write(
            $batch->id,
            'Daily run for '.$today.': '.$config['users'].' member(s), '
                .$config['discussions'].' discussion(s), '.$config['replies'].' new-thread reply/replies, '
                .$config['revive_replies'].' into existing threads, '
                .count($config['existing_users']).' existing member(s) available as authors.'
        );

        return $batch;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function configForToday(string $today): ?array
    {
        // The last manual run's brief is the template: language, theme, tone,
        // tags, model. Without one there is nothing to write about, and
        // guessing would be worse than doing nothing.
        $base = $this->settings->lastConfig();

        if ($base === []) {
            return null;
        }

        $volumes = $this->settings->dailyVolumes();

        if (array_sum($volumes) === 0) {
            return null;
        }

        // A different seed every day, derived from the date so a rerun of the
        // same day reproduces the same content.
        $rng = new Rng(crc32($today) & 0x7FFFFFFF);
        $jitter = $this->settings->dailyJitter();

        foreach ($volumes as $key => $value) {
            $volumes[$key] = max(0, (int) round($value * $rng->between(1 - $jitter, 1 + $jitter)));
        }

        // Replies need somewhere to go; without a discussion today they would
        // be dropped by the planner anyway.
        if ($volumes['discussions'] === 0) {
            $volumes['replies'] = 0;
        }

        // Part of the day's replies go into threads that already exist rather
        // than into ones opened this morning - which is what a forum actually
        // looks like.
        $revivable = $this->revivableDiscussions();
        $revive = 0;

        if ($revivable !== [] && $volumes['replies'] > 0) {
            $revive = (int) round($volumes['replies'] * $this->settings->reviveShare());
            $volumes['replies'] -= $revive;
        }

        $existing = $this->existingMembers();

        if ($existing === [] && $volumes['users'] === 0) {
            // Nobody to write today's content.
            $volumes['users'] = 1;
        }

        return array_merge($base, $volumes, [
            'date_start' => $today,
            'date_end' => $today,
            'seed' => 0,
            'existing_users' => $existing,
            'existing_discussions' => $revivable,
            'revive_replies' => $revive,
            // One day at a time: a growth ramp across a single day is noise.
            'distribution' => 'random',
            'mode' => Batch::MODE_GENERATE,
        ]);
    }

    /**
     * Threads recent enough that somebody would plausibly still answer them.
     *
     * @return array<int, array{id: int, last_posted_at: string, last_user_id: int|null}>
     */
    protected function revivableDiscussions(): array
    {
        $since = Carbon::now()->subDays($this->settings->reviveWindowDays());

        $rows = $this->db->table('discussions')
            ->where('is_private', false)
            ->whereNull('hidden_at')
            ->where('created_at', '>=', $since)
            ->whereNotNull('last_posted_at')
            ->orderByDesc('last_posted_at')
            ->limit(300)
            ->get(['id', 'last_posted_at', 'last_posted_user_id']);

        $discussions = [];

        foreach ($rows as $row) {
            $discussions[] = [
                'id' => (int) $row->id,
                'last_posted_at' => (string) $row->last_posted_at,
                'last_user_id' => $row->last_posted_user_id === null ? null : (int) $row->last_posted_user_id,
            ];
        }

        return $discussions;
    }

    /**
     * Members previous runs created and that still exist.
     *
     * @return array<int, array{id: int, joined_at: string}>
     */
    protected function existingMembers(): array
    {
        $ids = $this->db->table('ai_seeder_items')
            ->where('type', Item::TYPE_USER)
            ->whereNotNull('target_id')
            ->orderByDesc('id')
            ->limit($this->settings->dailyAuthorPool())
            ->pluck('target_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        if ($ids === []) {
            return [];
        }

        return User::whereIn('id', $ids)
            ->whereNotNull('joined_at')
            ->get()
            ->map(fn (User $user) => [
                'id' => (int) $user->id,
                'joined_at' => $user->joined_at->format('Y-m-d H:i:s'),
            ])
            ->all();
    }
}
