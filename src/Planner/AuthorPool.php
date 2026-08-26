<?php

namespace Pbiaut\AiSeeder\Planner;

use DateTimeImmutable;

/**
 * Picks who writes what, honouring two rules:
 *
 *  1. a member can only post after they joined;
 *  2. a few members are far more active than the rest (power-law weights).
 *
 * Members are kept sorted by join date with cumulative weights, so a pick is a
 * binary search rather than a scan. The only mutation ever applied is pulling
 * the *earliest* member back in time when nobody is eligible yet, which keeps
 * the sort order (and therefore the prefix sums) valid.
 */
final class AuthorPool
{
    /** @var array<int, int> position => user index */
    private array $order = [];

    /** @var array<int, int> position => join timestamp */
    private array $joinTs = [];

    /** @var array<int, float> position => cumulative activity weight */
    private array $prefix = [];

    /**
     * @param  array<int, array{joined_at: DateTimeImmutable}>  $users
     * @param  array<int, float>  $activity
     */
    public function __construct(
        private array $users,
        array $activity,
        private DateTimeImmutable $floor,
    ) {
        $order = array_keys($this->users);

        usort($order, fn (int $a, int $b) => $this->users[$a]['joined_at'] <=> $this->users[$b]['joined_at']);

        $cumulative = 0.0;

        foreach ($order as $position => $index) {
            $this->order[$position] = $index;
            $this->joinTs[$position] = $this->users[$index]['joined_at']->getTimestamp();
            $cumulative += max(1e-6, $activity[$index] ?? 1.0);
            $this->prefix[$position] = $cumulative;
        }
    }

    /**
     * @return array<int, array{joined_at: DateTimeImmutable}>
     */
    public function users(): array
    {
        return $this->users;
    }

    /**
     * @param  array<int, int>  $exclude  user indexes that must not be picked -
     *                                    the previous poster, and the thread's
     *                                    author when their returns are planned
     *                                    deliberately rather than drawn
     */
    public function pick(DateTimeImmutable $at, array $exclude, Rng $rng): ?int
    {
        if ($this->order === []) {
            return null;
        }

        $eligible = $this->eligibleCount($at->getTimestamp());

        if ($eligible === 0) {
            $this->pullEarliestBack($at, $rng);
            $eligible = 1;
        }

        $total = $this->prefix[$eligible - 1];
        $banned = array_flip($exclude);

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $index = $this->order[$this->searchPrefix($rng->float() * $total, $eligible)];

            if (! isset($banned[$index]) || $eligible === 1) {
                return $index;
            }
        }

        // Rare: the weighted pick keeps landing on an excluded member.
        $others = [];

        for ($position = 0; $position < $eligible; $position++) {
            if (! isset($banned[$this->order[$position]])) {
                $others[] = $this->order[$position];
            }
        }

        if ($others === []) {
            // Everybody eligible is excluded; the ban is a preference, not a
            // reason to leave the reply unwritten.
            return $this->order[$rng->int(0, $eligible - 1)];
        }

        return $others[$rng->int(0, count($others) - 1)];
    }

    /** Number of members whose join date is at or before $timestamp. */
    private function eligibleCount(int $timestamp): int
    {
        $low = 0;
        $high = count($this->order) - 1;
        $found = -1;

        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);

            if ($this->joinTs[$mid] <= $timestamp) {
                $found = $mid;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        return $found + 1;
    }

    /** Smallest position in [0, $bound) whose cumulative weight reaches $target. */
    private function searchPrefix(float $target, int $bound): int
    {
        $low = 0;
        $high = $bound - 1;

        while ($low < $high) {
            $mid = intdiv($low + $high, 2);

            if ($this->prefix[$mid] < $target) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        return $low;
    }

    private function pullEarliestBack(DateTimeImmutable $at, Rng $rng): void
    {
        $index = $this->order[0];
        $moved = $at->modify('-'.$rng->int(300, 7200).' seconds');

        if ($moved < $this->floor) {
            $moved = $this->floor;
        }

        if ($moved > $at) {
            $moved = $at;
        }

        $this->users[$index]['joined_at'] = $moved;
        $this->joinTs[0] = $moved->getTimestamp();
    }
}
