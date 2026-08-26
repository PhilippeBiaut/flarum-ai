<?php

/**
 * Standalone unit tests for the planner.
 *
 * The planner deliberately has no Flarum and no network dependency, so it can
 * be verified with nothing but a PHP binary:
 *
 *     php tests/run-unit.php
 *
 * The Flarum-side integration tests (creators, rollback) live under
 * tests/integration and need a real forum + flarum/testing.
 */

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'Pbiaut\\AiSeeder\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $path = __DIR__.'/../src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

    if (is_file($path)) {
        require_once $path;
    }
});

use Pbiaut\AiSeeder\Planner\DayDistributor;
use Pbiaut\AiSeeder\Planner\InvalidConfigException;
use Pbiaut\AiSeeder\Planner\PlanConfig;
use Pbiaut\AiSeeder\Planner\PlanResult;
use Pbiaut\AiSeeder\Planner\ReplyLength;
use Pbiaut\AiSeeder\Planner\ReplyTarget;
use Pbiaut\AiSeeder\Planner\ReplyType;
use Pbiaut\AiSeeder\Planner\Rng;
use Pbiaut\AiSeeder\Planner\SchedulePlanner;

final class Runner
{
    private int $passed = 0;

    /** @var array<int, string> */
    private array $failures = [];

    private string $current = '';

    public function test(string $name, callable $body): void
    {
        $this->current = $name;

        try {
            $body($this);
        } catch (Throwable $e) {
            $this->failures[] = $name.' :: threw '.$e::class.' - '.$e->getMessage();
        }
    }

    public function ok(bool $condition, string $message): void
    {
        if ($condition) {
            $this->passed++;

            return;
        }

        $this->failures[] = $this->current.' :: '.$message;
    }

    public function same(mixed $expected, mixed $actual, string $message): void
    {
        $this->ok(
            $expected === $actual,
            $message.' (expected '.var_export($expected, true).', got '.var_export($actual, true).')'
        );
    }

    public function report(): int
    {
        echo PHP_EOL;

        if ($this->failures === []) {
            echo "\033[32mAll ".$this->passed." assertions passed.\033[0m".PHP_EOL;

            return 0;
        }

        echo "\033[31m".count($this->failures).' failure(s), '.$this->passed.' passed:'."\033[0m".PHP_EOL;

        foreach ($this->failures as $failure) {
            echo '  - '.$failure.PHP_EOL;
        }

        return 1;
    }
}

/**
 * @param  array<string, mixed>  $overrides
 */
function config(array $overrides = []): PlanConfig
{
    return PlanConfig::fromArray(array_merge([
        'users' => 20,
        'discussions' => 50,
        'replies' => 300,
        'date_start' => '2026-01-01',
        'date_end' => '2026-05-31',
        'distribution' => 'organic',
        'seed' => 424242,
    ], $overrides), 'UTC');
}

function totalReplies(PlanResult $plan): int
{
    return $plan->replyCount();
}

$t = new Runner();

// ---------------------------------------------------------------- distributor

$t->test('DayDistributor hits the exact total', function (Runner $t): void {
    foreach ([[0, 5], [1, 5], [7, 3], [50, 151], [1000, 7], [3, 100]] as [$total, $slots]) {
        $weights = [];

        for ($i = 0; $i < $slots; $i++) {
            $weights[$i] = 1.0 + $i * 0.1;
        }

        $result = DayDistributor::distribute($total, $weights);
        $t->same($total, array_sum($result), "distribute($total, $slots slots) sums to the total");
        $t->same($slots, count($result), "distribute($total, $slots slots) keeps every slot");
        $t->ok(min($result) >= 0, 'no negative counts');
    }
});

$t->test('DayDistributor tolerates zero and empty weights', function (Runner $t): void {
    $t->same([], DayDistributor::distribute(10, []), 'empty weights give an empty result');
    $result = DayDistributor::distribute(10, [0.0, 0.0, 0.0]);
    $t->same(10, array_sum($result), 'all-zero weights still distribute the total');
});

$t->test('distributeClamped respects min and max', function (Runner $t): void {
    $weights = [];

    for ($i = 0; $i < 40; $i++) {
        $weights[$i] = (float) random_int(1, 100);
    }

    $result = DayDistributor::distributeClamped(300, $weights, 2, 20);
    $t->same(300, array_sum($result), 'clamped distribution still sums to the total');
    $t->ok(min($result) >= 2, 'minimum honoured');
    $t->ok(max($result) <= 20, 'maximum honoured');

    // Infeasible: more than capacity.
    $saturated = DayDistributor::distributeClamped(10000, $weights, 0, 5);
    $t->same(40 * 5, array_sum($saturated), 'over-capacity falls back to full saturation');

    // Infeasible: below the floor.
    $floored = DayDistributor::distributeClamped(0, $weights, 3, 10);
    $t->same(40 * 3, array_sum($floored), 'under-floor falls back to the minimum');
});

// --------------------------------------------------------------------- config

$t->test('PlanConfig rejects impossible input', function (Runner $t): void {
    $cases = [
        'end before start' => ['date_end' => '2025-12-01'],
        'bad date' => ['date_start' => '01/01/2026'],
        'unknown distribution' => ['distribution' => 'chaos'],
        'no members but content' => ['users' => 0],
        'hours inverted' => ['hour_start' => 20, 'hour_end' => 8],
        'too many discussions' => ['discussions' => 999999],
    ];

    foreach ($cases as $label => $overrides) {
        $threw = false;

        try {
            config($overrides);
        } catch (InvalidConfigException) {
            $threw = true;
        }

        $t->ok($threw, "rejects: $label");
    }
});

$t->test('PlanConfig generates a seed when none is given', function (Runner $t): void {
    $c = config(['seed' => 0]);
    $t->ok($c->seed > 0, 'a random seed is assigned');
    $t->same(151, $c->days(), 'January 1st to May 31st 2026 is 151 days');
});

// -------------------------------------------------------------------- planner

$t->test('totals match exactly what was requested', function (Runner $t): void {
    foreach (['organic', 'uniform', 'random'] as $strategy) {
        $c = config(['distribution' => $strategy]);
        $plan = (new SchedulePlanner())->plan($c);

        $t->same(20, count($plan->users), "$strategy: member count");
        $t->same(50, count($plan->discussions), "$strategy: discussion count");
        $t->same(300, totalReplies($plan), "$strategy: reply count");
    }
});

$t->test('every content timestamp stays inside the period', function (Runner $t): void {
    $c = config(['discussions' => 120, 'replies' => 900]);
    $plan = (new SchedulePlanner())->plan($c);

    $outside = 0;

    foreach ($plan->discussions as $discussion) {
        if ($discussion['created_at'] < $c->dateStart || $discussion['created_at'] > $c->dateEnd) {
            $outside++;
        }

        foreach ($discussion['replies'] as $reply) {
            if ($reply['created_at'] < $c->dateStart || $reply['created_at'] > $c->dateEnd) {
                $outside++;
            }
        }
    }

    $t->same(0, $outside, 'no discussion or reply falls outside [date_start, date_end]');

    $signupsOutside = 0;

    foreach ($plan->users as $user) {
        if ($user['joined_at'] < $c->dateStart || $user['joined_at'] > $c->dateEnd) {
            $signupsOutside++;
        }
    }

    $t->same(0, $signupsOutside, 'no member joins outside the period either');
});

$t->test('nobody posts before joining', function (Runner $t): void {
    $c = config(['users' => 8, 'discussions' => 90, 'replies' => 600]);
    $plan = (new SchedulePlanner())->plan($c);

    $violations = 0;

    foreach ($plan->discussions as $discussion) {
        if ($plan->users[$discussion['author']]['joined_at'] > $discussion['created_at']) {
            $violations++;
        }

        foreach ($discussion['replies'] as $reply) {
            if ($plan->users[$reply['author']]['joined_at'] > $reply['created_at']) {
                $violations++;
            }
        }
    }

    $t->same(0, $violations, 'every author joined before writing');
});

$t->test('replies are ordered and never precede the opening post', function (Runner $t): void {
    $plan = (new SchedulePlanner())->plan(config(['discussions' => 60, 'replies' => 700]));

    $ordering = 0;
    $sameAuthorTwice = 0;

    foreach ($plan->discussions as $discussion) {
        $previousTime = $discussion['created_at'];
        $previousAuthor = $discussion['author'];

        foreach ($discussion['replies'] as $reply) {
            if ($reply['created_at'] <= $previousTime) {
                $ordering++;
            }

            if ($reply['author'] === $previousAuthor) {
                $sameAuthorTwice++;
            }

            $previousTime = $reply['created_at'];
            $previousAuthor = $reply['author'];
        }
    }

    $t->same(0, $ordering, 'reply timestamps strictly increase after the opening post');
    $t->same(0, $sameAuthorTwice, 'nobody replies to themselves back to back');
});

$t->test('the same seed reproduces the same plan', function (Runner $t): void {
    $a = (new SchedulePlanner())->plan(config(['seed' => 777]))->toSummaryArray();
    $b = (new SchedulePlanner())->plan(config(['seed' => 777]))->toSummaryArray();
    $c = (new SchedulePlanner())->plan(config(['seed' => 778]))->toSummaryArray();

    $t->ok($a === $b, 'identical seed gives an identical day-by-day plan');
    $t->ok($a !== $c, 'a different seed gives a different plan');
});

$t->test('the day-by-day summary adds up', function (Runner $t): void {
    $plan = (new SchedulePlanner())->plan(config());
    $summary = $plan->toSummaryArray();

    $t->same(151, count($summary['days']), 'one row per day of the period');
    $t->same(50, array_sum(array_column($summary['days'], 'discussions')), 'daily discussions add up to the total');
    $t->same(300, array_sum(array_column($summary['days'], 'replies')), 'daily replies add up to the total');
    $t->same(20, array_sum(array_column($summary['days'], 'signups')), 'daily signups add up to the total');
    $t->same(50, $summary['totals']['discussions'], 'totals block agrees');
});

$t->test('organic mode is quieter at weekends and busier as the forum grows', function (Runner $t): void {
    $plan = (new SchedulePlanner())->plan(config(['discussions' => 1200, 'replies' => 0, 'users' => 40]));
    $days = $plan->days();

    $weekday = $weekend = 0;
    $first = $last = 0;
    $total = count($days);

    foreach ($days as $index => $day) {
        $isWeekend = in_array((int) (new DateTimeImmutable($day['date']))->format('N'), [6, 7], true);

        if ($isWeekend) {
            $weekend += $day['discussions'];
        } else {
            $weekday += $day['discussions'];
        }

        if ($index < $total / 4) {
            $first += $day['discussions'];
        } elseif ($index >= 3 * $total / 4) {
            $last += $day['discussions'];
        }
    }

    // Compare daily averages, not raw sums (there are more weekdays than weekend days).
    $weekendDays = count(array_filter($days, fn ($d) => in_array((int) (new DateTimeImmutable($d['date']))->format('N'), [6, 7], true)));
    $weekdayDays = $total - $weekendDays;

    $t->ok(
        ($weekend / max(1, $weekendDays)) < ($weekday / max(1, $weekdayDays)),
        'weekend days are quieter on average'
    );
    $t->ok($last > $first, 'the last quarter of the period is busier than the first');
});

$t->test('uniform mode really is flat', function (Runner $t): void {
    $plan = (new SchedulePlanner())->plan(config([
        'distribution' => 'uniform',
        'discussions' => 302,
        'replies' => 0,
        'users' => 10,
    ]));

    $counts = array_column($plan->days(), 'discussions');
    $t->ok(max($counts) - min($counts) <= 1, 'uniform spreads within one unit per day');
});

$t->test('edge cases do not blow up', function (Runner $t): void {
    $cases = [
        'single day' => ['date_start' => '2026-03-04', 'date_end' => '2026-03-04', 'discussions' => 12, 'replies' => 40],
        'fewer discussions than days' => ['discussions' => 3, 'replies' => 5],
        'no replies' => ['replies' => 0],
        'no discussions' => ['discussions' => 0, 'replies' => 0],
        'single member' => ['users' => 1, 'discussions' => 5, 'replies' => 10],
        'capped replies' => ['replies' => 300, 'replies_max' => 4],
        'forced minimum' => ['replies' => 10, 'replies_min' => 3],
        'narrow hours' => ['hour_start' => 22, 'hour_end' => 23],
        'short reply window' => ['reply_window_days' => 1],
    ];

    foreach ($cases as $label => $overrides) {
        $c = config($overrides);
        $plan = (new SchedulePlanner())->plan($c);

        $t->same($c->discussions, count($plan->discussions), "$label: discussion total");

        // Threads drawn as "dead" are held out of the distribution, so capacity
        // is a function of the answered ones, not of every discussion.
        $answered = count(array_filter(
            $plan->discussions,
            fn (array $discussion) => $discussion['archetype'] !== 'dead'
        ));

        $expectedReplies = min(
            max($c->replies, $answered * $c->repliesMin),
            $answered * $c->repliesMax
        );
        $t->same($expectedReplies, totalReplies($plan), "$label: reply total (bounds applied)");

        $late = 0;

        foreach ($plan->discussions as $discussion) {
            foreach ($discussion['replies'] as $reply) {
                if ($reply['created_at'] > $c->dateEnd) {
                    $late++;
                }
            }
        }

        $t->same(0, $late, "$label: no reply overflows the period");
    }
});

$t->test('fewer items than days still covers the whole period', function (Runner $t): void {
    // 60 discussions over 151 days: a largest-remainder split would hand every
    // one of them to the 60 highest-weighted days and leave January empty.
    foreach (['organic', 'random'] as $strategy) {
        $plan = (new SchedulePlanner())->plan(config([
            'distribution' => $strategy,
            'users' => 25,
            'discussions' => 60,
            'replies' => 420,
        ]));

        $days = $plan->days();
        $half = intdiv(count($days), 2);

        $firstHalf = array_sum(array_column(array_slice($days, 0, $half), 'discussions'));
        $firstMonth = array_sum(array_column(array_slice($days, 0, 31), 'discussions'));
        $activeDays = count(array_filter($days, fn ($d) => $d['discussions'] > 0));

        $t->ok($firstHalf >= 6, "$strategy: the first half of the period carries a real share (got $firstHalf/60)");
        $t->ok($firstMonth >= 1, "$strategy: the first month is not empty (got $firstMonth)");
        $t->ok($activeDays >= 30, "$strategy: activity is spread over many days (got $activeDays)");
    }
});

$t->test('impossible reply bounds raise a warning instead of silently lying', function (Runner $t): void {
    $plan = (new SchedulePlanner())->plan(config(['replies' => 5000, 'replies_max' => 3]));
    $t->ok($plan->warnings !== [], 'a warning is attached when the requested total cannot fit');
});

$t->test('every reply gets its own target length, mostly short', function (Runner $t): void {
    $plan = (new SchedulePlanner())->plan(config(['discussions' => 80, 'replies' => 900]));

    $buckets = [];
    $outOfRange = 0;
    $missing = 0;

    foreach ($plan->discussions as $discussion) {
        foreach ($discussion['replies'] as $reply) {
            if (! isset($reply['words'], $reply['length'])) {
                $missing++;
                continue;
            }

            $buckets[$reply['length']] = ($buckets[$reply['length']] ?? 0) + 1;

            [$min, $max] = ReplyLength::BUCKETS[$reply['length']];

            if ($reply['words'] < $min || $reply['words'] > $max) {
                $outOfRange++;
            }
        }
    }

    $t->same(0, $missing, 'every reply carries a word target');
    $t->same(0, $outOfRange, 'each target sits inside its own bucket');
    $t->same(5, count($buckets), 'all five length buckets get used');

    $short = ($buckets['very_short'] ?? 0) + ($buckets['short'] ?? 0);
    $long = ($buckets['long'] ?? 0) + ($buckets['very_long'] ?? 0);

    $t->ok($short > $long, 'short replies outnumber long ones, as on a real forum');
});

$t->test('replies point at a message that exists before them', function (Runner $t): void {
    $plan = (new SchedulePlanner())->plan(config(['discussions' => 60, 'replies' => 700]));

    $missing = 0;
    $impossible = 0;
    $toOpeningPost = 0;
    $toAnotherReply = 0;
    $perTarget = [0];

    foreach ($plan->discussions as $discussion) {
        foreach (array_values($discussion['replies']) as $position => $reply) {
            if (! array_key_exists('replies_to', $reply)) {
                $missing++;
                continue;
            }

            $target = $reply['replies_to'];

            // 0 is the opening post; anything else must already have been
            // written by the time this reply is posted.
            if ($target < 0 || $target > $position) {
                $impossible++;
            }

            if ($target === 0) {
                $toOpeningPost++;
            } else {
                $toAnotherReply++;
                $perTarget[$target] = ($perTarget[$target] ?? 0) + 1;
            }
        }
    }

    $t->same(0, $missing, 'every reply says which message it answers');
    $t->same(0, $impossible, 'no reply answers a message that does not exist yet');
    $t->ok($toAnotherReply > 0, 'some replies answer another member rather than the opening post');

    // Replies answering each other is the point, so the opening post no longer
    // holds an outright majority. What must stay true is that it is the single
    // most-answered message of the thread.
    $t->ok(
        $toOpeningPost > max($perTarget),
        'the opening post is still the most-answered single message'
    );
});

$t->test('the first reply of a thread can only answer the opening post', function (Runner $t): void {
    $rng = new Rng(7);

    for ($i = 0; $i < 50; $i++) {
        $t->ok(ReplyTarget::draw(0, $rng) === 0, 'position 0 always targets the opening post');
    }

    // Later positions stay inside the thread.
    $outside = 0;

    for ($position = 1; $position < 40; $position++) {
        $target = ReplyTarget::draw($position, $rng);

        if ($target < 0 || $target > $position) {
            $outside++;
        }
    }

    $t->same(0, $outside, 'a target never points past the reply doing the answering');
});

$t->test('reply lengths are reproducible and expressed as a range', function (Runner $t): void {
    $a = ReplyLength::draw(new Rng(4242));
    $b = ReplyLength::draw(new Rng(4242));

    $t->ok($a === $b, 'the same seed draws the same length');

    $t->ok(
        str_contains(ReplyLength::instruction(100), '75') && str_contains(ReplyLength::instruction(100), '125'),
        'a target of 100 becomes a 75-125 range'
    );

    // The range must bracket the target and never collapse to nothing.
    foreach ([8, 20, 60, 160, 420] as $target) {
        preg_match_all('/\d+/', ReplyLength::instruction($target), $numbers);
        [$low, $high] = array_map('intval', $numbers[0]);

        $t->ok($low >= 5, "target $target keeps a floor of at least 5 words (got $low)");
        $t->ok($low <= $target && $target <= $high, "target $target sits inside its own range ($low-$high)");
    }
});

$t->test('tag paths are parsed, deduplicated and capped at two levels', function (Runner $t): void {
    $c = config([
        'tags' => "Voyage > Voyages France\n"
            ."  Voyage   >   Voyages Asie  | 3\n"
            ."Cuisine\n"
            ."# une ligne commentee\n"
            ."\n"
            ."voyage > voyages france\n"          // doublon, casse differente
            .'Sport > Cyclisme > Route > Cols',   // trop profond
    ]);

    $paths = array_column($c->tags, 'path');

    $t->same(
        ['Voyage > Voyages France', 'Voyage > Voyages Asie', 'Cuisine', 'Sport > Cols'],
        $paths,
        'paths are normalised, comments and duplicates dropped, depth capped'
    );

    $t->same(3.0, $c->tags[1]['weight'], 'the "| 3" suffix becomes a weight');
    $t->same(1.0, $c->tags[0]['weight'], 'no suffix means weight 1');
    $t->same('Voyages France', $c->tags[0]['name'], 'the leaf is kept as the display name');
    $t->same('Cuisine', $c->tags[2]['name'], 'a single-level path is its own leaf');
});

$t->test('tag paths also accept the structured form', function (Runner $t): void {
    $c = config(['tags' => [
        ['path' => 'Voyage > Voyages France', 'weight' => 2],
        ['name' => 'Cuisine'],
    ]]);

    $t->same(['Voyage > Voyages France', 'Cuisine'], array_column($c->tags, 'path'), 'objects work too');
    $t->same(2.0, $c->tags[0]['weight'], 'weights survive');
});

$t->test('discussions get a tag path, weighted', function (Runner $t): void {
    $plan = (new SchedulePlanner())->plan(config([
        'discussions' => 200,
        'replies' => 0,
        'tags' => "Voyage > Voyages France | 5\nCuisine | 1",
    ]));

    $counts = [];

    foreach ($plan->discussions as $discussion) {
        $t->ok($discussion['tag_path'] !== null, 'every discussion carries a tag path');
        $counts[$discussion['tag_path']] = ($counts[$discussion['tag_path']] ?? 0) + 1;
    }

    $t->same(200, array_sum($counts), 'every discussion is tagged');
    $t->ok(
        ($counts['Voyage > Voyages France'] ?? 0) > ($counts['Cuisine'] ?? 0),
        'the heavier tag is picked more often'
    );
});

$t->test('a too-deep path raises a warning instead of silently changing', function (Runner $t): void {
    $plan = (new SchedulePlanner())->plan(config([
        'discussions' => 5,
        'replies' => 0,
        'tags' => 'Sport > Cyclisme > Route',
    ]));

    $t->ok($plan->warnings !== [], 'the admin is told the path was folded to two levels');
});

$t->test('no tags at all is fine', function (Runner $t): void {
    $plan = (new SchedulePlanner())->plan(config(['discussions' => 5, 'replies' => 0]));

    $t->same(5, count($plan->discussions), 'discussions are still planned');
    $t->ok($plan->discussions[0]['tag_path'] === null, 'and simply carry no tag');
});

// ----------------------------------------------------------- reply behaviour

$t->test('reply types respect what is possible where they sit', function (Runner $t): void {
    $rng = new Rng(31337);
    $violations = [];

    for ($i = 0; $i < 600; $i++) {
        $total = $rng->int(1, 12);
        $position = $rng->int(0, $total - 1);
        $answersAReply = $position > 0 && $rng->bool();
        $byAuthor = $rng->bool(0.2);

        $type = ReplyType::draw($position, $total, $answersAReply, $byAuthor, $rng);

        // Correcting or agreeing with the opening post's own author, when the
        // reply is aimed at the opening post, has nothing to correct or agree
        // with beyond the question itself.
        if (! $answersAReply && in_array($type, ['correction', 'disagree', 'agree', 'pedantic'], true)) {
            $violations[] = "$type answering the opening post";
        }

        // Only the person who asked reports back or says thanks.
        if (! $byAuthor && in_array($type, ['followup', 'thanks'], true)) {
            $violations[] = "$type from someone other than the author";
        }

        // And not in the opening exchanges.
        $isLate = $total <= 2 || $position >= (int) floor($total * 0.55);

        if (! $isLate && in_array($type, ['followup', 'thanks'], true)) {
            $violations[] = "$type too early in the thread";
        }
    }

    $t->same([], array_slice(array_unique($violations), 0, 5), 'no type appears where it makes no sense');
});

$t->test('useful replies stay in the majority', function (Runner $t): void {
    $plan = (new SchedulePlanner())->plan(config(['discussions' => 80, 'replies' => 900]));

    $useful = ['answer', 'experience', 'expert', 'alternative', 'partial', 'resource'];
    $tone = ['incisive', 'humour', 'teasing', 'skeptical', 'pedantic'];

    $counts = ['useful' => 0, 'tone' => 0, 'total' => 0];

    foreach ($plan->discussions as $discussion) {
        foreach ($discussion['replies'] as $reply) {
            $counts['total']++;

            if (in_array($reply['type'], $useful, true)) {
                $counts['useful']++;
            } elseif (in_array($reply['type'], $tone, true)) {
                $counts['tone']++;
            }
        }
    }

    $t->ok($counts['useful'] / $counts['total'] > 0.5, 'more than half of replies are there to help');
    $t->ok($counts['tone'] / $counts['total'] < 0.2, 'tone-driven replies stay a garnish');
});

$t->test('a thread that nobody answers is possible, and common', function (Runner $t): void {
    $plan = (new SchedulePlanner())->plan(config(['discussions' => 120, 'replies' => 600]));

    $dead = count(array_filter($plan->discussions, fn (array $d) => $d['replies'] === []));

    $t->ok($dead > 10, "a real share of threads gets no reply at all (got $dead/120)");
    $t->ok($dead < 60, 'but most threads do get answered');

    // Asking for a minimum contradicts dead threads; the explicit setting wins.
    $forced = (new SchedulePlanner())->plan(config(['discussions' => 40, 'replies' => 200, 'replies_min' => 2]));
    $stillDead = count(array_filter($forced->discussions, fn (array $d) => $d['replies'] === []));

    $t->same(0, $stillDead, 'a minimum per discussion leaves none unanswered');
    $t->ok($forced->warnings !== [], 'and the contradiction is reported');
});

$t->test('ReplyQuality catches duplicates and assistant tells', function (Runner $t): void {
    $quality = new Pbiaut\AiSeeder\Generator\ReplyQuality();

    $original = 'Chez moi le souci venait du cache disque, il faut vider /var/cache avant de relancer le service.';

    $t->ok($quality->reject($original, []) === null, 'a normal reply passes');

    $reworded = 'Le souci chez moi venait du cache disque : il faut vider /var/cache avant de relancer le service.';
    $t->ok($quality->reject($reworded, [$original]) !== null, 'the same point reworded is caught');

    $different = 'Aucune idée pour le cache, mais vérifie plutôt les permissions du dossier de logs.';
    $t->ok($quality->reject($different, [$original]) === null, 'a genuinely different reply is kept');

    foreach ([
        'As an AI, I cannot test this myself, but you could try clearing the cache.',
        "Excellente question ! Il faut vider le cache disque avant de relancer le service, voila.",
        "## Solution\n\nVider le cache disque puis relancer le service, c'est tout ce qu'il faut faire.",
    ] as $bad) {
        $t->ok($quality->reject($bad, []) !== null, 'rejected: '.mb_substr($bad, 0, 28).'...');
    }

    $t->ok($quality->reject('ok', []) !== null, 'a two-letter answer is rejected');
});

$t->test('voice quirks never break mentions, code, links or quotes', function (Runner $t): void {
    $quirks = new Pbiaut\AiSeeder\Generator\VoiceQuirks();

    $text = "Éric, regarde @\"Éric Dupré\"#42 : lance `sudo systemctl restart nginx`,\n"
        ."> comme dit plus haut\n"
        ."et vérifie sur https://Example.COM/Docs/Été avant de râler.";

    $all = [
        Pbiaut\AiSeeder\Generator\VoiceQuirks::LOWERCASE,
        Pbiaut\AiSeeder\Generator\VoiceQuirks::NO_ACCENTS,
        Pbiaut\AiSeeder\Generator\VoiceQuirks::TYPOS,
        Pbiaut\AiSeeder\Generator\VoiceQuirks::CAPS_EMPHASIS,
        Pbiaut\AiSeeder\Generator\VoiceQuirks::MOBILE,
    ];

    foreach ($all as $quirk) {
        $out = $quirks->apply($text, [$quirk], new Rng(11));

        $t->ok(str_contains($out, '@"Éric Dupré"#42'), "$quirk leaves the mention intact");
        $t->ok(str_contains($out, '`sudo systemctl restart nginx`'), "$quirk leaves the code span intact");
        $t->ok(str_contains($out, 'https://Example.COM/Docs/Été'), "$quirk leaves the link intact");
        $t->ok(str_contains($out, '> comme dit plus haut'), "$quirk leaves the quoted line intact");
    }
});

$t->test('voice quirks actually change the prose, and reproducibly', function (Runner $t): void {
    $quirks = new Pbiaut\AiSeeder\Generator\VoiceQuirks();
    $text = 'Chez moi la mise à jour a cassé le montage réseau. Il fallait recréer le point de montage.';

    $lower = $quirks->apply($text, [Pbiaut\AiSeeder\Generator\VoiceQuirks::LOWERCASE], new Rng(3));
    $t->ok($lower === mb_strtolower($text), 'lowercase applies to the whole message');

    $plain = $quirks->apply($text, [Pbiaut\AiSeeder\Generator\VoiceQuirks::NO_ACCENTS], new Rng(3));
    $t->ok(! str_contains($plain, 'à') && ! str_contains($plain, 'é'), 'accents are gone');
    $t->ok(str_contains($plain, 'casse le montage reseau'), 'and the words survive');

    $t->same(
        $quirks->apply($text, [Pbiaut\AiSeeder\Generator\VoiceQuirks::TYPOS], new Rng(9)),
        $quirks->apply($text, [Pbiaut\AiSeeder\Generator\VoiceQuirks::TYPOS], new Rng(9)),
        'the same seed produces the same typos'
    );

    $t->same($text, $quirks->apply($text, [], new Rng(3)), 'a member with no quirks is left alone');
});

$t->test('most members write plainly, a minority have habits', function (Runner $t): void {
    $rng = new Rng(2024);
    $plain = 0;
    $tooMany = 0;

    for ($i = 0; $i < 400; $i++) {
        $drawn = Pbiaut\AiSeeder\Generator\VoiceQuirks::draw($rng);

        if ($drawn === []) {
            $plain++;
        }

        if (count($drawn) > 2) {
            $tooMany++;
        }
    }

    $t->ok($plain > 140 && $plain < 260, "roughly half of members write plainly (got $plain/400)");
    $t->same(0, $tooMany, 'nobody gets more than two habits');
});

$t->test('some members stop coming, and never post afterwards', function (Runner $t): void {
    $plan = (new SchedulePlanner())->plan(config(['users' => 60, 'discussions' => 100, 'replies' => 700]));

    $departed = count(array_filter($plan->users, fn (array $user) => ($user['left_at'] ?? null) !== null));

    $t->ok($departed > 5, "a real share of members leave during the period (got $departed/60)");
    $t->ok($departed < 45, 'but most are still around at the end');

    $posthumous = 0;

    foreach ($plan->discussions as $discussion) {
        foreach ($discussion['replies'] as $reply) {
            $leftAt = $plan->users[$reply['author']]['left_at'] ?? null;

            if ($leftAt !== null && $reply['created_at'] > $leftAt) {
                $posthumous++;
            }
        }
    }

    // The pool falls back to anyone eligible when everybody available is
    // excluded, so this is a strong preference rather than an absolute.
    $t->ok(
        $posthumous < totalReplies($plan) * 0.05,
        "almost nobody posts after leaving (got $posthumous)"
    );
});

$t->test('Rng is reproducible and bounded', function (Runner $t): void {
    $a = new Rng(99);
    $b = new Rng(99);

    $sameSequence = true;
    $inRange = true;

    for ($i = 0; $i < 500; $i++) {
        $x = $a->float();

        if ($x !== $b->float()) {
            $sameSequence = false;
        }

        if ($x < 0.0 || $x >= 1.0) {
            $inRange = false;
        }
    }

    $t->ok($sameSequence, 'the same seed yields the same stream');
    $t->ok($inRange, 'float() stays in [0, 1)');
    $t->ok((new Rng(1))->int(5, 5) === 5, 'int() handles a collapsed range');
});

exit($t->report());
