<?php

namespace Pbiaut\AiSeeder\Planner;

use DateTimeImmutable;

/**
 * Answers the core question of the extension: given X members, X discussions,
 * X replies and a period, *how many publications and replies happen each day*,
 * and who writes them.
 *
 * Fully deterministic for a given seed, and completely offline: no OpenAI call
 * is made here, which is what makes the free "preview before you spend" screen
 * possible.
 */
class SchedulePlanner
{
    public function plan(PlanConfig $config): PlanResult
    {
        $rng = new Rng($config->seed);
        $result = new PlanResult($config->seed, $config->dateStart, $config->dateEnd);

        $days = $this->buildDays($config);
        $dayWeights = $this->dayWeights($config, $days, $rng);

        $result->users = $this->planSignups($config, $days, $dayWeights, $rng);

        if ($config->discussions === 0) {
            return $result;
        }

        $activity = [];

        foreach (array_keys($result->users) as $index) {
            $activity[$index] = $rng->powerLaw(0.75);
        }

        $pool = new AuthorPool($result->users, $activity, $config->dateStart);

        $times = $this->planDiscussionTimes($config, $days, $dayWeights, $rng);
        $replyCounts = $this->planReplyCounts($config, count($times), $rng, $result);

        foreach ($times as $index => $createdAt) {
            $author = $pool->pick($createdAt, null, $rng);

            if ($author === null) {
                $result->warnings[] = 'No member available to author a discussion; planning stopped early.';
                break;
            }

            $tag = $this->pickTag($config, $rng);

            $result->discussions[$index] = [
                'author' => $author,
                'created_at' => $createdAt,
                'tag_id' => $tag['id'] ?? null,
                'tag_name' => $tag['name'] ?? null,
                'replies' => $this->planReplies(
                    $config,
                    $createdAt,
                    $replyCounts[$index] ?? 0,
                    $author,
                    $pool,
                    $rng,
                    $result
                ),
            ];
        }

        // The pool may have pulled a few members back in time so that early
        // content had an eligible author; take the adjusted dates back.
        $result->users = $pool->users();

        return $result;
    }

    /**
     * @return array<int, DateTimeImmutable>  midnight of each day in the period
     */
    protected function buildDays(PlanConfig $config): array
    {
        $days = [];
        $cursor = $config->dateStart->setTime(0, 0, 0);
        $last = $config->dateEnd->setTime(0, 0, 0);

        while ($cursor <= $last) {
            $days[] = $cursor;
            $cursor = $cursor->modify('+1 day');
        }

        return $days;
    }

    /**
     * Relative "how busy is this day" weight, per strategy.
     *
     * @param  array<int, DateTimeImmutable>  $days
     * @return array<int, float>
     */
    protected function dayWeights(PlanConfig $config, array $days, Rng $rng): array
    {
        $count = count($days);
        $weights = [];

        foreach ($days as $index => $day) {
            $weights[$index] = match ($config->distribution) {
                'uniform' => 1.0,
                // Exponential draws normalise to a Dirichlet-like random split.
                'random' => max(1e-4, -log(max(1e-9, $rng->float()))),
                default => $this->organicWeight($config, $day, $index, $count, $rng),
            };
        }

        return $weights;
    }

    protected function organicWeight(PlanConfig $config, DateTimeImmutable $day, int $index, int $count, Rng $rng): float
    {
        $weekday = (int) $day->format('N');
        $progress = $count > 1 ? $index / ($count - 1) : 0.0;
        $ramp = $config->growthStart + ($config->growthEnd - $config->growthStart) * $progress;
        $noise = $rng->between(0.75, 1.25);

        return max(1e-4, ($config->weekdayWeights[$weekday] ?? 1.0) * $ramp * $noise);
    }

    /**
     * Turns day weights into a per-day count.
     *
     * "uniform" stays deterministic and perfectly flat; the other strategies
     * draw, so that a period with more days than items still sees activity
     * from the very beginning instead of only at the busiest end.
     *
     * @param  array<int, float>  $dayWeights
     * @return array<int, int>
     */
    protected function spreadOverDays(int $total, array $dayWeights, PlanConfig $config, Rng $rng): array
    {
        return $config->distribution === 'uniform'
            ? DayDistributor::distribute($total, $dayWeights)
            : DayDistributor::sample($total, $dayWeights, $rng);
    }

    /**
     * Members join over the period, with a founding cohort on day one so that
     * the very first discussions have someone to write them.
     *
     * @param  array<int, DateTimeImmutable>  $days
     * @param  array<int, float>  $dayWeights
     * @return array<int, array{joined_at: DateTimeImmutable}>
     */
    protected function planSignups(PlanConfig $config, array $days, array $dayWeights, Rng $rng): array
    {
        if ($config->users === 0) {
            return [];
        }

        $founders = min($config->users, max(1, (int) round($config->users * $config->founderRatio)));
        $remaining = $config->users - $founders;

        $joinDates = [];
        $earlyWindow = max(600, $config->hourStart * 3600);

        for ($i = 0; $i < $founders; $i++) {
            $joinDates[] = $config->dateStart->modify('+'.$rng->int(0, $earlyWindow).' seconds');
        }

        if ($remaining > 0) {
            $perDay = $this->spreadOverDays($remaining, $dayWeights, $config, $rng);

            foreach ($perDay as $dayIndex => $count) {
                for ($i = 0; $i < $count; $i++) {
                    $joinDates[] = $this->timeOnDay($days[$dayIndex], $config, $rng);
                }
            }
        }

        usort($joinDates, fn (DateTimeImmutable $a, DateTimeImmutable $b) => $a <=> $b);

        $users = [];

        foreach ($joinDates as $index => $joinedAt) {
            $users[$index] = ['joined_at' => $joinedAt];
        }

        return $users;
    }

    /**
     * @param  array<int, DateTimeImmutable>  $days
     * @param  array<int, float>  $dayWeights
     * @return array<int, DateTimeImmutable>  chronologically sorted
     */
    protected function planDiscussionTimes(PlanConfig $config, array $days, array $dayWeights, Rng $rng): array
    {
        $perDay = $this->spreadOverDays($config->discussions, $dayWeights, $config, $rng);
        $times = [];

        foreach ($perDay as $dayIndex => $count) {
            for ($i = 0; $i < $count; $i++) {
                $times[] = $this->timeOnDay($days[$dayIndex], $config, $rng);
            }
        }

        usort($times, fn (DateTimeImmutable $a, DateTimeImmutable $b) => $a <=> $b);

        return $times;
    }

    /**
     * Spreads the requested reply total over the discussions: a few very busy
     * threads, many quiet ones, always summing to exactly the requested total
     * when the min/max bounds allow it.
     *
     * @return array<int, int>
     */
    protected function planReplyCounts(PlanConfig $config, int $discussionCount, Rng $rng, PlanResult $result): array
    {
        if ($discussionCount === 0 || $config->replies === 0) {
            return array_fill(0, max(0, $discussionCount), 0);
        }

        $capacity = $discussionCount * $config->repliesMax;
        $floor = $discussionCount * $config->repliesMin;

        if ($config->replies > $capacity) {
            $result->warnings[] = sprintf(
                'Only %d replies can fit in %d discussions with a maximum of %d per discussion; %d were requested.',
                $capacity,
                $discussionCount,
                $config->repliesMax,
                $config->replies
            );
        } elseif ($config->replies < $floor) {
            $result->warnings[] = sprintf(
                'A minimum of %d replies per discussion forces at least %d replies; %d were requested.',
                $config->repliesMin,
                $floor,
                $config->replies
            );
        }

        $weights = [];

        for ($i = 0; $i < $discussionCount; $i++) {
            $weights[$i] = $rng->powerLaw(0.85);
        }

        return DayDistributor::distributeClamped(
            $config->replies,
            $weights,
            $config->repliesMin,
            $config->repliesMax
        );
    }

    /**
     * @return array<int, array{author: int, created_at: DateTimeImmutable}>
     */
    protected function planReplies(
        PlanConfig $config,
        DateTimeImmutable $openedAt,
        int $count,
        int $opAuthor,
        AuthorPool $pool,
        Rng $rng,
        PlanResult $result,
    ): array {
        if ($count <= 0) {
            return [];
        }

        $times = [];

        foreach (ReplyCurve::delays($count, $rng, $config->replyWindowDays) as $delay) {
            $times[] = $this->snapToWindow($openedAt->modify('+'.$delay.' seconds'), $config, $rng);
        }

        $times = $this->normaliseReplyTimes($times, $openedAt, $config->dateEnd, $rng, $result);

        $replies = [];
        $previous = $opAuthor;

        foreach ($times as $time) {
            $author = $pool->pick($time, $previous, $rng);

            if ($author === null) {
                break;
            }

            $replies[] = ['author' => $author, 'created_at' => $time];
            $previous = $author;
        }

        return $replies;
    }

    /**
     * Keeps replies inside the browsing-hours window: nobody posts at 4am.
     */
    protected function snapToWindow(DateTimeImmutable $time, PlanConfig $config, Rng $rng): DateTimeImmutable
    {
        $hour = (int) $time->format('G') + ((int) $time->format('i')) / 60;

        if ($hour >= $config->hourStart && $hour < $config->hourEnd) {
            return $time;
        }

        if ($hour < $config->hourStart) {
            return $time->setTime($config->hourStart, 0, 0)->modify('+'.$rng->int(0, 2700).' seconds');
        }

        return $time->modify('+1 day')->setTime($config->hourStart, 0, 0)->modify('+'.$rng->int(0, 5400).' seconds');
    }

    /**
     * Guarantees strictly increasing timestamps that stay after the opening
     * post and inside the period. If the tail would overflow the end date, the
     * whole thread is compressed into the remaining room instead of piling up
     * on the last second.
     *
     * @param  array<int, DateTimeImmutable>  $times
     * @return array<int, DateTimeImmutable>
     */
    protected function normaliseReplyTimes(
        array $times,
        DateTimeImmutable $openedAt,
        DateTimeImmutable $periodEnd,
        Rng $rng,
        PlanResult $result,
    ): array {
        usort($times, fn (DateTimeImmutable $a, DateTimeImmutable $b) => $a <=> $b);

        $ordered = [];
        $previous = $openedAt;

        foreach ($times as $time) {
            if ($time <= $previous) {
                $time = $previous->modify('+'.$rng->int(60, 900).' seconds');
            }

            $ordered[] = $time;
            $previous = $time;
        }

        if ($ordered === [] || $previous <= $periodEnd) {
            return $ordered;
        }

        // Overflow: fold the thread back into [openedAt, periodEnd].
        $count = count($ordered);
        $span = $periodEnd->getTimestamp() - $openedAt->getTimestamp();

        if ($span <= 0) {
            $result->warnings[] = 'A discussion opened on the very last second of the period; its replies share that timestamp.';

            return array_fill(0, $count, $periodEnd);
        }

        $step = max(1, intdiv($span, $count + 1));
        $compressed = [];

        for ($i = 0; $i < $count; $i++) {
            $moment = $openedAt->modify('+'.($step * ($i + 1)).' seconds');
            $compressed[] = $moment > $periodEnd ? $periodEnd : $moment;
        }

        return $compressed;
    }

    /**
     * Time of day, biased towards a lunchtime and an evening peak rather than
     * spread flat across the browsing window.
     */
    protected function timeOnDay(DateTimeImmutable $day, PlanConfig $config, Rng $rng): DateTimeImmutable
    {
        $low = (float) $config->hourStart;
        $high = (float) $config->hourEnd;
        $width = $high - $low;

        if ($width < 1.0) {
            $peak = ($low + $high) / 2;
        } else {
            $peak = $rng->bool(0.45) ? 12.5 : 21.0;
            $peak = min(max($peak, $low + 0.5), $high - 0.5);
        }

        $hour = $rng->gauss($peak, max(0.2, $width / 6));
        $hour = min(max($hour, $low), $high - 1 / 60);

        return $day->setTime(0, 0, 0)->modify('+'.((int) round($hour * 3600)).' seconds');
    }

    /**
     * @return array{id: int, name: string, weight: float}|array{}
     */
    protected function pickTag(PlanConfig $config, Rng $rng): array
    {
        if ($config->tags === []) {
            return [];
        }

        $weights = [];

        foreach ($config->tags as $index => $tag) {
            $weights[$index] = $tag['weight'];
        }

        $key = $rng->weightedKey($weights);

        return $key === null ? [] : $config->tags[$key];
    }
}
