<?php

namespace Pbiaut\AiSeeder\Creator;

use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Denormalised counters that Flarum normally maintains through its event
 * listeners. Since the seeder writes models directly (to avoid firing
 * notifications and emails for backdated content), it has to bring them back in
 * line itself.
 */
class CounterRefresher
{
    public function __construct(protected ConnectionInterface $db)
    {
    }

    /**
     * @param  array<int, int>  $userIds
     */
    public function refreshUsers(array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        User::whereIn('id', array_unique($userIds))
            ->get()
            ->each(function (User $user): void {
                $user->refreshCommentCount();
                $user->refreshDiscussionCount();
                $user->save();
            });
    }

    /**
     * @param  array<int, int>  $discussionIds
     */
    public function refreshDiscussions(array $discussionIds): void
    {
        if ($discussionIds === []) {
            return;
        }

        Discussion::whereIn('id', array_unique($discussionIds))
            ->get()
            ->each(function (Discussion $discussion): void {
                $discussion->refreshCommentCount();
                $discussion->refreshParticipantCount();
                $discussion->refreshLastPost();
                $discussion->save();
            });
    }

    /**
     * flarum/tags keeps a discussion_count per tag. Recomputed in PHP rather
     * than with a correlated UPDATE so it behaves the same on MySQL, PostgreSQL
     * and SQLite.
     *
     * @param  array<int, int>  $tagIds  empty means "every tag"
     */
    public function refreshTags(array $tagIds = []): void
    {
        /** @var \Illuminate\Database\Connection $connection */
        $connection = $this->db;
        $schema = $connection->getSchemaBuilder();

        if (! $schema->hasTable('tags') || ! $schema->hasTable('discussion_tag')) {
            return;
        }

        $query = $this->db->table('discussion_tag')
            ->join('discussions', 'discussions.id', '=', 'discussion_tag.discussion_id')
            ->where('discussions.is_private', false)
            ->whereNull('discussions.hidden_at')
            ->groupBy('discussion_tag.tag_id')
            ->selectRaw('discussion_tag.tag_id as tag_id, count(*) as total');

        if ($tagIds !== []) {
            $query->whereIn('discussion_tag.tag_id', array_unique($tagIds));
        }

        $counts = [];

        foreach ($query->get() as $row) {
            $counts[(int) $row->tag_id] = (int) $row->total;
        }

        $targets = $tagIds !== []
            ? array_unique($tagIds)
            : $this->db->table('tags')->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach ($targets as $tagId) {
            $this->db->table('tags')
                ->where('id', $tagId)
                ->update(['discussion_count' => $counts[$tagId] ?? 0]);
        }
    }
}
