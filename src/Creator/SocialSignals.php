<?php

namespace Pbiaut\AiSeeder\Creator;

use Carbon\Carbon;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Pbiaut\AiSeeder\Planner\Rng;

/**
 * The things that make a forum page look inhabited, none of which cost a token.
 *
 * Likes and mentions are written straight to the pivot tables. Flarum normally
 * fills those through listeners on the Posted / PostWasLiked events, which the
 * seeder deliberately never dispatches - backdated content must not generate a
 * few thousand notifications.
 *
 * Every method degrades quietly when the matching extension is absent.
 */
class SocialSignals
{
    /** Likes are drawn against this: most posts get none, a few get many. */
    private const LIKE_RATE = 0.28;

    public function __construct(protected ConnectionInterface $db)
    {
    }

    public function likesAvailable(): bool
    {
        return $this->hasTable('post_likes');
    }

    public function mentionsAvailable(): bool
    {
        return $this->hasTable('post_mentions_user');
    }

    /**
     * Spreads likes over a thread's posts.
     *
     * Only members who had already joined by the time the post was written can
     * like it, and nobody likes their own post.
     *
     * @param  array<int, array{id: int, user_id: int, created_at: Carbon}>  $posts
     * @param  array<int, array{id: int, joined_at: Carbon}>  $candidates
     * @return int  how many likes were written
     */
    public function like(array $posts, array $candidates, Rng $rng): int
    {
        if (! $this->likesAvailable() || $posts === [] || $candidates === []) {
            return 0;
        }

        $hasTimestamp = $this->hasColumn('post_likes', 'created_at');
        $rows = [];

        foreach ($posts as $post) {
            // A minority of posts get liked at all, and the ones that do follow
            // a heavy tail: the helpful answer collects most of them.
            if (! $rng->bool(self::LIKE_RATE)) {
                continue;
            }

            $eligible = array_values(array_filter(
                $candidates,
                fn (array $user) => $user['id'] !== $post['user_id'] && $user['joined_at'] <= $post['created_at']
            ));

            if ($eligible === []) {
                continue;
            }

            $wanted = min(count($eligible), max(1, (int) round($rng->powerLaw(0.7))));
            $pickedIds = [];

            foreach ($rng->shuffle($eligible) as $user) {
                if (count($pickedIds) >= $wanted) {
                    break;
                }

                $pickedIds[] = $user['id'];

                $row = ['post_id' => $post['id'], 'user_id' => $user['id']];

                if ($hasTimestamp) {
                    // Liked some time after the post appeared, never before.
                    $row['created_at'] = $post['created_at']->copy()->addMinutes($rng->int(2, 4320));
                }

                $rows[] = $row;
            }
        }

        if ($rows === []) {
            return 0;
        }

        $this->db->table('post_likes')->insertOrIgnore($rows);

        return count($rows);
    }

    /**
     * Turns a plain name into a real Flarum mention.
     *
     * The formatter recognises `@"Display Name"#id`, so a reply that says
     * "Alice, tu as raison" becomes a genuine mention - clickable, styled, and
     * counted - rather than a name in plain text.
     *
     * @param  array<int, User>  $participants  members already in this thread
     */
    public function linkMentions(string $content, array $participants): string
    {
        if (! $this->mentionsAvailable() || $participants === []) {
            return $content;
        }

        foreach ($participants as $user) {
            $name = (string) $user->display_name;

            if (mb_strlen($name) < 3) {
                continue;
            }

            $quoted = preg_quote($name, '/');
            $mention = '@"'.$name.'"#'.$user->id;

            // "@Name" written plainly by the model, not already a full mention.
            $content = preg_replace(
                '/@'.$quoted.'(?!["#])/u',
                $mention,
                $content
            ) ?? $content;

            // "Name," or "Name :" at the start of a line - how people actually
            // address each other. Only the first occurrence: repeating the
            // mention in every paragraph reads as spam.
            $content = preg_replace(
                '/(^|\n)'.$quoted.'(\s*[,:])/u',
                '$1'.str_replace('$', '\$', $mention).'$2',
                $content,
                1
            ) ?? $content;
        }

        return $content;
    }

    /**
     * Records the mentions a post contains, which Flarum would normally do from
     * an event listener.
     *
     * @return array<int, int>  the mentioned user ids
     */
    public function recordMentions(int $postId, string $content, Carbon $createdAt): array
    {
        if (! $this->mentionsAvailable()) {
            return [];
        }

        if (preg_match_all('/@"(?:[^"]+)"#(\d+)/u', $content, $matches) === 0) {
            return [];
        }

        $ids = array_values(array_unique(array_map('intval', $matches[1])));
        $hasTimestamp = $this->hasColumn('post_mentions_user', 'created_at');
        $rows = [];

        foreach ($ids as $id) {
            $row = ['post_id' => $postId, 'mentions_id' => $id];

            if ($hasTimestamp) {
                $row['created_at'] = $createdAt;
            }

            $rows[] = $row;
        }

        $this->db->table('post_mentions_user')->insertOrIgnore($rows);

        return $ids;
    }

    /**
     * Members with a null last_seen_at show up as never having visited, which
     * makes every generated profile look like a shell. Set it a little after
     * their last post, so the profile agrees with the history.
     *
     * @param  array<int, int>  $userIds
     */
    public function refreshLastSeen(array $userIds, Rng $rng): void
    {
        if ($userIds === []) {
            return;
        }

        $lastPosts = $this->db->table('posts')
            ->whereIn('user_id', $userIds)
            ->groupBy('user_id')
            ->selectRaw('user_id, MAX(created_at) as last_post')
            ->pluck('last_post', 'user_id');

        foreach ($lastPosts as $userId => $lastPost) {
            if ($lastPost === null) {
                continue;
            }

            $this->db->table('users')
                ->where('id', (int) $userId)
                ->update([
                    'last_seen_at' => Carbon::parse($lastPost)->addMinutes($rng->int(1, 2880)),
                ]);
        }
    }

    private function hasTable(string $table): bool
    {
        /** @var \Illuminate\Database\Connection $connection */
        $connection = $this->db;

        return $connection->getSchemaBuilder()->hasTable($table);
    }

    private function hasColumn(string $table, string $column): bool
    {
        /** @var \Illuminate\Database\Connection $connection */
        $connection = $this->db;

        return $connection->getSchemaBuilder()->hasColumn($table, $column);
    }
}
