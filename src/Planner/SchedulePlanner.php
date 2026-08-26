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

        $this->warnDeepTagPaths($config, $result);

        $result->users = $this->planSignups($config, $days, $dayWeights, $rng);

        if ($config->discussions === 0) {
            return $result;
        }

        $activity = [];

        foreach (array_keys($result->users) as $index) {
            $activity[$index] = $rng->powerLaw(0.75);
        }

        $result->users = $this->planDepartures($config, $result->users, $rng);

        $pool = new AuthorPool($result->users, $activity, $config->dateStart);

        $times = $this->planDiscussionTimes($config, $days, $dayWeights, $rng);

        // Each thread's shape is drawn first: it decides how many replies the
        // thread deserves relative to the others, so it has to feed into the
        // distribution rather than be applied on top of it.
        $archetypes = [];

        foreach (array_keys($times) as $index) {
            $archetypes[$index] = ThreadArchetype::draw($rng, (float) $config->generation('dead_thread_share', 0.22));
        }

        // A minimum number of replies per discussion and unanswered threads are
        // contradictory requests. The explicit setting wins - and the archetype
        // has to change here, not just in the reply counts, or the plan would
        // report threads as dead while handing them replies.
        if ($config->repliesMin > 0) {
            $revived = 0;

            foreach ($archetypes as $index => $archetype) {
                if ($archetype === ThreadArchetype::DEAD) {
                    $archetypes[$index] = ThreadArchetype::QUICK;
                    $revived++;
                }
            }

            if ($revived > 0) {
                $result->warnings[] = 'Replies-per-discussion has a minimum, so no discussion is left unanswered.';
            }
        }

        $replyCounts = $this->planReplyCounts($config, $archetypes, $rng, $result);

        foreach ($times as $index => $createdAt) {
            $author = $pool->pick($createdAt, [], $rng);

            if ($author === null) {
                $result->warnings[] = 'No member available to author a discussion; planning stopped early.';
                break;
            }

            $tag = $this->pickTag($config, $rng);

            $result->discussions[$index] = [
                'author' => $author,
                'created_at' => $createdAt,
                'tag_path' => $tag['path'] ?? null,
                'tag_name' => $tag['name'] ?? null,
                'archetype' => $archetypes[$index],
                'replies' => $this->planReplies(
                    $config,
                    $createdAt,
                    $replyCounts[$index] ?? 0,
                    $author,
                    $archetypes[$index],
                    $pool,
                    $rng,
                    $result
                ),
            ];
        }

        $result->revivals = $this->planRevivals($config, $pool, $rng);

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
        // Members the forum already has keep their real join date and are never
        // created again: they simply become available authors.
        $users = [];

        foreach ($config->existingUsers as $existing) {
            $users[] = ['joined_at' => $existing['joined_at'], 'existing_id' => $existing['id']];
        }

        if ($config->users === 0) {
            return $this->indexed($users);
        }

        // A founding cohort is only needed when nobody is there yet; on a forum
        // that already has members, everyone simply joins over the period.
        $founders = $config->existingUsers === []
            ? min($config->users, max(1, (int) round($config->users * $config->founderRatio)))
            : 0;
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

        foreach ($joinDates as $joinedAt) {
            $users[] = ['joined_at' => $joinedAt, 'existing_id' => null];
        }

        return $this->indexed($users);
    }

    /**
     * Sorted by join date and re-indexed, which is what AuthorPool expects.
     *
     * @param  array<int, array{joined_at: DateTimeImmutable, existing_id: int|null}>  $users
     * @return array<int, array{joined_at: DateTimeImmutable, existing_id: int|null}>
     */
    protected function indexed(array $users): array
    {
        usort($users, fn (array $a, array $b) => $a['joined_at'] <=> $b['joined_at']);

        return array_values($users);
    }

    /**
     * Some members stop coming.
     *
     * Without this everyone who ever joined is still posting on the last day of
     * the period, which is the single least forum-like thing a member list can
     * do. Lifespans follow a heavy tail: many people post for a fortnight and
     * vanish, a few stay throughout.
     *
     * @param  array<int, array{joined_at: DateTimeImmutable}>  $users
     * @return array<int, array{joined_at: DateTimeImmutable, left_at: DateTimeImmutable|null}>
     */
    protected function planDepartures(PlanConfig $config, array $users, Rng $rng): array
    {
        $share = min(0.9, max(0.0, (float) $config->generation('departed_share', 0.35)));
        $periodDays = max(1, $config->days());

        foreach ($users as $index => $user) {
            if (! $rng->bool($share)) {
                // Still around on the last day.
                $users[$index]['left_at'] = null;
                continue;
            }

            // Heavy tail: mostly short stays, occasionally a long one.
            $lifespan = max(1, (int) round($periodDays * min(1.0, $rng->powerLaw(0.55) / 12)));
            $leftAt = $user['joined_at']->modify('+'.$lifespan.' days');

            $users[$index]['left_at'] = $leftAt > $config->dateEnd ? null : $leftAt;
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
    protected function planReplyCounts(PlanConfig $config, array $archetypes, Rng $rng, PlanResult $result): array
    {
        $discussionCount = count($archetypes);

        if ($discussionCount === 0 || $config->replies === 0) {
            return array_fill(0, max(0, $discussionCount), 0);
        }

        // Dead threads are held out of the distribution entirely: weighting them
        // low would only make them unlikely, not empty.
        $alive = [];

        foreach ($archetypes as $index => $archetype) {
            if ($archetype !== ThreadArchetype::DEAD) {
                $alive[$index] = $rng->powerLaw(0.85) * ThreadArchetype::multiplier($archetype);
            }
        }

        if ($alive === []) {
            return array_fill(0, $discussionCount, 0);
        }

        $capacity = count($alive) * $config->repliesMax;
        $floor = count($alive) * $config->repliesMin;

        if ($config->replies > $capacity) {
            $result->warnings[] = sprintf(
                'Only %d replies can fit in the %d discussions that get answered, with a maximum of %d each; %d were requested.',
                $capacity,
                count($alive),
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

        $counts = array_fill(0, $discussionCount, 0);

        foreach (DayDistributor::distributeClamped($config->replies, $alive, $config->repliesMin, $config->repliesMax) as $index => $count) {
            $counts[$index] = $count;
        }

        return $counts;
    }

    /**
     * @return array<int, array{author: int, created_at: DateTimeImmutable}>
     */
    protected function planReplies(
        PlanConfig $config,
        DateTimeImmutable $openedAt,
        int $count,
        int $opAuthor,
        string $archetype,
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

        // On a real thread the person who asked comes back: to answer a
        // clarifying question, to report what they tried, to say thanks. How
        // often depends on the kind of thread.
        $authorReturns = $this->planAuthorReturns($count, $archetype, $rng);
        $typeWeights = ThreadArchetype::typeWeights($archetype);

        foreach ($times as $time) {
            $position = count($replies);
            $byAuthor = in_array($position, $authorReturns, true) && $previous !== $opAuthor;

            // The thread author's returns are planned, not drawn: leaving them in
            // the pool as well would double their real share.
            $author = $byAuthor
                ? $opAuthor
                : $pool->pick($time, array_unique([$previous, $opAuthor]), $rng);

            if ($author === null) {
                break;
            }

            $length = ReplyLength::draw($rng);
            // 0 is the opening post, 1 the first reply, and so on.
            $target = ReplyTarget::draw($position, $rng);

            // The author answering their own opening post makes no sense; send
            // them to the most recent reply instead.
            if ($byAuthor && $target === 0 && $position > 0) {
                $target = $position;
            }

            $replies[] = [
                'author' => $author,
                'created_at' => $time,
                'words' => $length['words'],
                'length' => $length['bucket'],
                'replies_to' => $target,
                'type' => ReplyType::draw(
                    $position,
                    $count,
                    $target > 0,
                    $author === $opAuthor,
                    $rng,
                    $typeWeights
                ),
            ];

            $previous = $author;
        }

        return $replies;
    }

    /**
     * Replies into threads that already exist.
     *
     * A forum is not only fresh threads: somebody finds a three-week-old
     * question and answers it. Without this every reply a recurring run writes
     * would land in a thread opened the same morning.
     *
     * @return array<int, array{discussion_id: int, author: int, created_at: DateTimeImmutable, words: int, type: string}>
     */
    protected function planRevivals(PlanConfig $config, AuthorPool $pool, Rng $rng): array
    {
        if ($config->reviveReplies <= 0 || $config->existingDiscussions === []) {
            return [];
        }

        // A few old threads get a couple of replies; most get none.
        $weights = [];

        foreach ($config->existingDiscussions as $index => $discussion) {
            $weights[$index] = $rng->powerLaw(0.9);
        }

        $counts = DayDistributor::sample($config->reviveReplies, $weights, $rng);
        $revivals = [];

        foreach ($counts as $index => $count) {
            if ($count === 0) {
                continue;
            }

            $discussion = $config->existingDiscussions[$index];
            $previous = $discussion['last_user_id'];

            for ($i = 0; $i < $count; $i++) {
                $at = $this->timeOnDay($config->dateStart, $config, $rng);

                // Never before the thread's own last message, and never after
                // the day being generated.
                if ($at <= $discussion['last_posted_at']) {
                    $at = $discussion['last_posted_at']->modify('+'.$rng->int(600, 43200).' seconds');
                }

                if ($at > $config->dateEnd) {
                    $at = $config->dateEnd;
                }

                $author = $pool->pick($at, array_values(array_filter([$previous])), $rng);

                if ($author === null) {
                    break;
                }

                $revivals[] = [
                    'discussion_id' => $discussion['id'],
                    'author' => $author,
                    'created_at' => $at,
                    'words' => ReplyLength::draw($rng)['words'],
                    // Coming back to an older thread is nearly always about
                    // adding something, not bickering.
                    'type' => $rng->weightedKey([
                        ReplyType::ANSWER => 26.0,
                        ReplyType::EXPERIENCE => 24.0,
                        ReplyType::FOLLOWUP => 14.0,
                        ReplyType::EXPERT => 12.0,
                        ReplyType::RESOURCE => 10.0,
                        ReplyType::ALTERNATIVE => 8.0,
                        ReplyType::CLARIFY => 6.0,
                    ]) ?: ReplyType::ANSWER,
                ];

                // The pool excludes the previous poster; from here that is us.
                $previous = null;
            }
        }

        usort($revivals, fn (array $a, array $b) => $a['created_at'] <=> $b['created_at']);

        return $revivals;
    }

    /**
     * Which reply slots the thread's own author takes back.
     *
     * Never the first one - answering your own question immediately reads as a
     * mistake - and never two in a row, which the caller enforces.
     *
     * @return array<int, int>
     */
    protected function planAuthorReturns(int $count, string $archetype, Rng $rng): array
    {
        $share = ThreadArchetype::authorShare($archetype);

        if ($count < 2 || $share <= 0) {
            return [];
        }

        // Probabilistic rounding rather than max(1, ...): forcing at least one
        // return in every thread makes the author write half the replies of
        // every two-reply thread, which wrecks the overall share.
        $expected = $count * $share;
        $wanted = (int) floor($expected);

        if ($rng->float() < $expected - $wanted) {
            $wanted++;
        }

        $wanted = min($count - 1, $wanted);

        if ($wanted <= 0) {
            return [];
        }

        $slots = [];
        $attempts = 0;

        while (count($slots) < $wanted && $attempts++ < 40) {
            // Weighted towards the back of the thread: the author replies once
            // somebody has given them something to react to.
            $position = $rng->int(1, $count - 1);

            if (! in_array($position, $slots, true) && ! in_array($position - 1, $slots, true)) {
                $slots[] = $position;
            }
        }

        sort($slots);

        return $slots;
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
     * flarum/tags is two levels deep: a primary tag and its children. Anything
     * deeper is folded down to "first > last", and the admin is told so rather
     * than quietly getting something else than what they typed.
     */
    protected function warnDeepTagPaths(PlanConfig $config, PlanResult $result): void
    {
        foreach ($this->rawTagPaths($config) as $original) {
            $depth = count(array_filter(
                array_map('trim', explode('>', $original)),
                fn (string $part) => $part !== ''
            ));

            if ($depth > 2) {
                $result->warnings[] = sprintf(
                    'Flarum tags only go two levels deep: "%s" will be created as "%s".',
                    trim($original),
                    implode(' > ', PlanConfig::tagSegments($original))
                );
            }
        }
    }

    /**
     * The paths exactly as the admin typed them, before normalisation.
     *
     * @return array<int, string>
     */
    protected function rawTagPaths(PlanConfig $config): array
    {
        $given = $config->generation('tags', []);

        if (is_string($given)) {
            $given = preg_split('/\r\n|\r|\n/', $given) ?: [];
        }

        if (! is_array($given)) {
            return [];
        }

        $paths = [];

        foreach ($given as $entry) {
            $path = is_array($entry) ? ($entry['path'] ?? $entry['name'] ?? '') : $entry;

            if (! is_string($path)) {
                continue;
            }

            // Strip an optional "| weight" suffix.
            $path = trim(explode('|', $path, 2)[0]);

            if ($path !== '' && ! str_starts_with($path, '#')) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * @return array{path: string, name: string, weight: float}|array{}
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
