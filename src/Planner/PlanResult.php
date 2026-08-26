<?php

namespace Pbiaut\AiSeeder\Planner;

use DateTimeImmutable;

/**
 * The full generation calendar, computed without a single OpenAI call.
 *
 * This is what the admin sees in the "day by day" preview, and what gets
 * persisted as ai_seeder_items rows once the run is confirmed.
 */
final class PlanResult
{
    /**
     * @var array<int, array{joined_at: DateTimeImmutable}>
     */
    public array $users = [];

    /**
     * @var array<int, array{
     *     author: int,
     *     created_at: DateTimeImmutable,
     *     tag_id: int|null,
     *     tag_name: string|null,
     *     replies: array<int, array{author: int, created_at: DateTimeImmutable, words: int, length: string}>
     * }>
     */
    public array $discussions = [];

    /** @var array<int, string> */
    public array $warnings = [];

    public function __construct(
        public int $seed,
        public DateTimeImmutable $dateStart,
        public DateTimeImmutable $dateEnd,
    ) {
    }

    public function replyCount(): int
    {
        $total = 0;

        foreach ($this->discussions as $discussion) {
            $total += count($discussion['replies']);
        }

        return $total;
    }

    /**
     * Per-day aggregation: exactly the table and histogram rendered in the admin.
     *
     * @return array<int, array{date: string, signups: int, discussions: int, replies: int}>
     */
    public function days(): array
    {
        $days = [];

        $cursor = $this->dateStart->setTime(0, 0, 0);
        $last = $this->dateEnd->setTime(0, 0, 0);

        while ($cursor <= $last) {
            $days[$cursor->format('Y-m-d')] = ['signups' => 0, 'discussions' => 0, 'replies' => 0];
            $cursor = $cursor->modify('+1 day');
        }

        $bump = function (string $date, string $key) use (&$days): void {
            if (! isset($days[$date])) {
                $days[$date] = ['signups' => 0, 'discussions' => 0, 'replies' => 0];
            }

            $days[$date][$key]++;
        };

        foreach ($this->users as $user) {
            $bump($user['joined_at']->format('Y-m-d'), 'signups');
        }

        foreach ($this->discussions as $discussion) {
            $bump($discussion['created_at']->format('Y-m-d'), 'discussions');

            foreach ($discussion['replies'] as $reply) {
                $bump($reply['created_at']->format('Y-m-d'), 'replies');
            }
        }

        ksort($days);

        $rows = [];

        foreach ($days as $date => $counts) {
            $rows[] = array_merge(['date' => $date], $counts);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        $days = $this->days();
        $peak = 0;
        $busiestDay = null;

        foreach ($days as $day) {
            $activity = $day['discussions'] + $day['replies'];

            if ($activity > $peak) {
                $peak = $activity;
                $busiestDay = $day['date'];
            }
        }

        $dayCount = max(1, count($days));
        $replies = $this->replyCount();
        $discussions = count($this->discussions);

        return [
            'seed' => $this->seed,
            'date_start' => $this->dateStart->format('Y-m-d'),
            'date_end' => $this->dateEnd->format('Y-m-d'),
            'days' => $days,
            'totals' => [
                'users' => count($this->users),
                'discussions' => $discussions,
                'replies' => $replies,
                'days' => $dayCount,
                'avg_discussions_per_day' => round($discussions / $dayCount, 2),
                'avg_replies_per_day' => round($replies / $dayCount, 2),
                'avg_replies_per_discussion' => $discussions > 0 ? round($replies / $discussions, 2) : 0,
                'peak_day' => $busiestDay,
                'peak_activity' => $peak,
            ],
            'warnings' => $this->warnings,
        ];
    }
}
