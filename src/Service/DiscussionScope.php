<?php

namespace Pbiaut\AiSeeder\Service;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which existing discussions a tagging run should look at.
 *
 * Deliberately conservative by default: only threads that carry no tag at all,
 * so a run can never quietly re-categorise something an admin curated by hand.
 */
class DiscussionScope
{
    public const UNTAGGED = 'untagged';
    public const ALL = 'all';

    public const SCOPES = [self::UNTAGGED, self::ALL];

    /**
     * @param  array<string, mixed>  $config
     * @return Builder<Discussion>
     */
    public static function query(array $config): Builder
    {
        $query = Discussion::query()
            ->where('is_private', false)
            ->whereNull('hidden_at');

        $scope = (string) ($config['scope'] ?? self::UNTAGGED);

        if ($scope !== self::ALL) {
            $query->whereNotExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('discussion_tag')
                    ->whereColumn('discussion_tag.discussion_id', 'discussions.id');
            });
        }

        foreach ([['date_start', '>='], ['date_end', '<=']] as [$key, $operator]) {
            $value = $config[$key] ?? null;

            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                $moment = $operator === '>='
                    ? Carbon::parse($value)->startOfDay()
                    : Carbon::parse($value)->endOfDay();

                $query->where('created_at', $operator, $moment);
            }
        }

        return $query->orderBy('id');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function limit(array $config): int
    {
        $limit = $config['limit'] ?? 0;

        return is_numeric($limit) ? max(0, min(20000, (int) $limit)) : 0;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function count(array $config): int
    {
        $total = self::query($config)->count();
        $limit = self::limit($config);

        return $limit > 0 ? min($total, $limit) : $total;
    }
}
