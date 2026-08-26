<?php

namespace Pbiaut\AiSeeder\Planner;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Validated, normalised representation of the admin form.
 *
 * Deliberately free of any Flarum dependency, so the planner can be unit
 * tested on its own without booting a forum.
 */
final class PlanConfig
{
    public const MAX_USERS = 5000;
    public const MAX_DISCUSSIONS = 5000;
    public const MAX_REPLIES = 100000;
    public const MAX_DAYS = 1830; // ~5 years

    public const DISTRIBUTIONS = ['organic', 'uniform', 'random'];

    /** Default weekday weights, Monday (1) through Sunday (7). */
    public const DEFAULT_WEEKDAY_WEIGHTS = [
        1 => 1.0, 2 => 1.0, 3 => 1.0, 4 => 1.0, 5 => 0.95, 6 => 0.6, 7 => 0.5,
    ];

    /**
     * @param  array<int, float>  $weekdayWeights  keyed 1 (Mon) .. 7 (Sun)
     * @param  array<int, array{path: string, name: string, weight: float}>  $tags
     * @param  array<string, mixed>  $raw
     */
    private function __construct(
        public int $users,
        public int $discussions,
        public int $replies,
        public DateTimeImmutable $dateStart,
        public DateTimeImmutable $dateEnd,
        public string $distribution,
        public int $hourStart,
        public int $hourEnd,
        public int $repliesMin,
        public int $repliesMax,
        public array $weekdayWeights,
        public float $growthStart,
        public float $growthEnd,
        public float $founderRatio,
        public int $replyWindowDays,
        public int $seed,
        public array $tags,
        public array $existingUsers,
        public int $reviveReplies,
        public array $existingDiscussions,
        public array $raw,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidConfigException
     */
    public static function fromArray(array $data, string $timezone = 'UTC'): self
    {
        $errors = [];

        $users = self::intOf($data, 'users', 0);
        $discussions = self::intOf($data, 'discussions', 0);
        $replies = self::intOf($data, 'replies', 0);

        if ($users < 0 || $users > self::MAX_USERS) {
            $errors['users'] = 'must be between 0 and '.self::MAX_USERS;
        }

        if ($discussions < 0 || $discussions > self::MAX_DISCUSSIONS) {
            $errors['discussions'] = 'must be between 0 and '.self::MAX_DISCUSSIONS;
        }

        if ($replies < 0 || $replies > self::MAX_REPLIES) {
            $errors['replies'] = 'must be between 0 and '.self::MAX_REPLIES;
        }

        if ($users === 0 && ($discussions > 0 || $replies > 0)) {
            $errors['users'] = 'at least one member is required to author content';
        }

        if ($discussions === 0 && $replies > 0) {
            $errors['discussions'] = 'replies require at least one discussion';
        }

        try {
            $tz = new DateTimeZone($timezone);
        } catch (Exception) {
            $tz = new DateTimeZone('UTC');
        }

        $dateStart = self::dateOf($data, 'date_start', $tz, false);
        $dateEnd = self::dateOf($data, 'date_end', $tz, true);

        if ($dateStart === null) {
            $errors['date_start'] = 'expected a YYYY-MM-DD date';
        }

        if ($dateEnd === null) {
            $errors['date_end'] = 'expected a YYYY-MM-DD date';
        }

        if ($dateStart !== null && $dateEnd !== null) {
            if ($dateEnd < $dateStart) {
                $errors['date_end'] = 'must not be earlier than the start date';
            } elseif (self::dayCount($dateStart, $dateEnd) > self::MAX_DAYS) {
                $errors['date_end'] = 'the period must not exceed '.self::MAX_DAYS.' days';
            }
        }

        $distribution = (string) ($data['distribution'] ?? 'organic');

        if (! in_array($distribution, self::DISTRIBUTIONS, true)) {
            $errors['distribution'] = 'must be one of '.implode(', ', self::DISTRIBUTIONS);
        }

        $hourStart = self::intOf($data, 'hour_start', 8);
        $hourEnd = self::intOf($data, 'hour_end', 23);

        if ($hourStart < 0 || $hourStart > 23) {
            $errors['hour_start'] = 'must be between 0 and 23';
        }

        if ($hourEnd <= $hourStart || $hourEnd > 24) {
            $errors['hour_end'] = 'must be greater than the start hour and at most 24';
        }

        $repliesMin = max(0, self::intOf($data, 'replies_min', 0));
        $repliesMax = max($repliesMin, self::intOf($data, 'replies_max', 40));

        $weekdayWeights = self::weekdayWeightsOf($data);
        $growthStart = self::floatOf($data, 'growth_start', 0.4);
        $growthEnd = self::floatOf($data, 'growth_end', 1.6);

        if ($growthStart <= 0 || $growthEnd <= 0) {
            $errors['growth_start'] = 'growth factors must be greater than 0';
        }

        $founderRatio = min(1.0, max(0.05, self::floatOf($data, 'founder_ratio', 0.25)));
        $replyWindowDays = min(365, max(1, self::intOf($data, 'reply_window_days', 30)));

        $seed = self::intOf($data, 'seed', 0);

        if ($seed <= 0) {
            $seed = random_int(1, 2147483646);
        }

        $tags = self::tagsOf($data);
        $existingUsers = self::existingUsersOf($data, $tz);
        $reviveReplies = max(0, self::intOf($data, 'revive_replies', 0));
        $existingDiscussions = self::existingDiscussionsOf($data, $tz);

        if ($errors !== []) {
            throw new InvalidConfigException($errors);
        }

        return new self(
            users: $users,
            discussions: $discussions,
            replies: $replies,
            dateStart: $dateStart,
            dateEnd: $dateEnd,
            distribution: $distribution,
            hourStart: $hourStart,
            hourEnd: $hourEnd,
            repliesMin: $repliesMin,
            repliesMax: $repliesMax,
            weekdayWeights: $weekdayWeights,
            growthStart: $growthStart,
            growthEnd: $growthEnd,
            founderRatio: $founderRatio,
            replyWindowDays: $replyWindowDays,
            seed: $seed,
            tags: $tags,
            existingUsers: $existingUsers,
            reviveReplies: $reviveReplies,
            existingDiscussions: $existingDiscussions,
            raw: $data,
        );
    }

    public static function dayCount(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        $a = $start->setTime(0, 0, 0);
        $b = $end->setTime(0, 0, 0);

        return (int) $a->diff($b)->days + 1;
    }

    public function days(): int
    {
        return self::dayCount($this->dateStart, $this->dateEnd);
    }

    /** Generation-side settings, untouched by the planner. */
    public function generation(string $key, mixed $default = null): mixed
    {
        return $this->raw[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge($this->raw, [
            'users' => $this->users,
            'discussions' => $this->discussions,
            'replies' => $this->replies,
            'date_start' => $this->dateStart->format('Y-m-d'),
            'date_end' => $this->dateEnd->format('Y-m-d'),
            'timezone' => $this->dateStart->getTimezone()->getName(),
            'distribution' => $this->distribution,
            'hour_start' => $this->hourStart,
            'hour_end' => $this->hourEnd,
            'replies_min' => $this->repliesMin,
            'replies_max' => $this->repliesMax,
            'weekday_weights' => $this->weekdayWeights,
            'growth_start' => $this->growthStart,
            'growth_end' => $this->growthEnd,
            'founder_ratio' => $this->founderRatio,
            'reply_window_days' => $this->replyWindowDays,
            'seed' => $this->seed,
            'tags' => $this->tags,
            'existing_users' => [],
            'existing_discussions' => [],
            'revive_replies' => $this->reviveReplies,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function intOf(array $data, string $key, int $default): int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function floatOf(array $data, string $key, float $default): float
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function dateOf(array $data, string $key, DateTimeZone $tz, bool $endOfDay): ?DateTimeImmutable
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value.' 00:00:00', $tz);

        if ($date === false) {
            return null;
        }

        return $endOfDay ? $date->setTime(23, 59, 59) : $date;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, float>
     */
    private static function weekdayWeightsOf(array $data): array
    {
        $given = $data['weekday_weights'] ?? null;

        if (! is_array($given)) {
            return self::DEFAULT_WEEKDAY_WEIGHTS;
        }

        $weights = [];

        foreach (self::DEFAULT_WEEKDAY_WEIGHTS as $day => $default) {
            $value = $given[$day] ?? $given[(string) $day] ?? $default;
            $weights[$day] = is_numeric($value) ? max(0.0, (float) $value) : $default;
        }

        return array_sum($weights) > 0 ? $weights : self::DEFAULT_WEEKDAY_WEIGHTS;
    }

    /**
     * Tags are given as hierarchical paths ("Voyage > Voyages France"), which
     * the seeder resolves against existing tags and creates when missing.
     *
     * Accepts either a list of entries with a `path`, or the raw multi-line
     * text straight from the admin textarea, one path per line with an optional
     * `| weight` suffix.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array{path: string, name: string, weight: float}>
     */
    private static function tagsOf(array $data): array
    {
        $given = $data['tags'] ?? [];

        if (is_string($given)) {
            $given = self::parseTagLines($given);
        }

        if (! is_array($given)) {
            return [];
        }

        $tags = [];
        $seen = [];

        foreach ($given as $tag) {
            if (is_string($tag)) {
                $tag = ['path' => $tag];
            }

            if (! is_array($tag)) {
                continue;
            }

            // `name` is accepted as a fallback so a flat tag list still works.
            $path = trim((string) ($tag['path'] ?? $tag['name'] ?? ''));

            if ($path === '') {
                continue;
            }

            $segments = self::tagSegments($path);

            if ($segments === []) {
                continue;
            }

            $path = implode(' > ', $segments);
            $key = mb_strtolower($path);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $tags[] = [
                'path' => $path,
                // The leaf is the most specific label, and the one a member
                // would actually think of the thread as belonging to.
                'name' => $segments[count($segments) - 1],
                'weight' => max(0.0, (float) ($tag['weight'] ?? 1.0)),
            ];
        }

        return $tags;
    }

    /**
     * Members the forum already has, who should keep posting rather than being
     * replaced by a fresh cohort.
     *
     * Only meaningful for a recurring run: without it every daily batch would
     * invent its own isolated set of people, and everyone created yesterday
     * would fall silent forever.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array{id: int, joined_at: DateTimeImmutable}>
     */
    private static function existingUsersOf(array $data, DateTimeZone $tz): array
    {
        $given = $data['existing_users'] ?? [];

        if (! is_array($given)) {
            return [];
        }

        $users = [];

        foreach ($given as $user) {
            if (! is_array($user) || ! isset($user['id'], $user['joined_at'])) {
                continue;
            }

            // Flarum stores these in UTC; the planner reasons in the forum's own
            // timezone, so that "an evening post" means the same thing.
            $joined = DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                (string) $user['joined_at'],
                new DateTimeZone('UTC')
            );

            if ($joined === false) {
                continue;
            }

            $users[] = ['id' => (int) $user['id'], 'joined_at' => $joined->setTimezone($tz)];
        }

        return $users;
    }

    /**
     * Threads that already exist and could plausibly get a new reply today.
     *
     * A forum does not only produce fresh threads: somebody stumbles on a
     * three-week-old question and answers it. Without this, every reply a
     * recurring run writes lands in a thread opened the same morning, which is
     * not how anybody uses a forum.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array{id: int, last_posted_at: DateTimeImmutable, last_user_id: int|null}>
     */
    private static function existingDiscussionsOf(array $data, DateTimeZone $tz): array
    {
        $given = $data['existing_discussions'] ?? [];

        if (! is_array($given)) {
            return [];
        }

        $discussions = [];

        foreach ($given as $discussion) {
            if (! is_array($discussion) || ! isset($discussion['id'], $discussion['last_posted_at'])) {
                continue;
            }

            $last = DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                (string) $discussion['last_posted_at'],
                new DateTimeZone('UTC')
            );

            if ($last === false) {
                continue;
            }

            $discussions[] = [
                'id' => (int) $discussion['id'],
                'last_posted_at' => $last->setTimezone($tz),
                // Nobody replies to themselves twice in a row.
                'last_user_id' => isset($discussion['last_user_id']) ? (int) $discussion['last_user_id'] : null,
            ];
        }

        return $discussions;
    }

    /**
     * @return array<int, array{path: string, weight: float}>
     */
    private static function parseTagLines(string $text): array
    {
        $tags = [];

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim((string) $line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $weight = 1.0;

            // "Voyage > Voyages France | 3"
            if (str_contains($line, '|')) {
                [$line, $rawWeight] = array_map('trim', explode('|', $line, 2));

                if (is_numeric($rawWeight)) {
                    $weight = (float) $rawWeight;
                }
            }

            if ($line !== '') {
                $tags[] = ['path' => $line, 'weight' => $weight];
            }
        }

        return $tags;
    }

    /**
     * Splits a path, keeping at most the two levels flarum/tags supports.
     *
     * @return array<int, string>
     */
    public static function tagSegments(string $path): array
    {
        $parts = [];

        foreach (preg_split('/\s*>\s*/', trim($path)) ?: [] as $segment) {
            $segment = trim(preg_replace('/\s+/u', ' ', (string) $segment) ?? '');

            if ($segment !== '') {
                $parts[] = mb_substr($segment, 0, 100);
            }
        }

        if (count($parts) <= 2) {
            return $parts;
        }

        return [$parts[0], $parts[count($parts) - 1]];
    }
}
