<?php

namespace Pbiaut\AiSeeder\Creator;

use DateTimeInterface;
use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Creates a discussion plus its opening post, backdated.
 *
 * Mirrors what Flarum 2.0's DiscussionResource::saveModel() does (save the
 * discussion, create the first post, then setFirstPost/setLastPost), minus the
 * JSON:API plumbing, permission checks and event dispatching.
 */
class DiscussionCreator
{
    public function __construct(
        protected ConnectionInterface $db,
        protected PostWriter $posts,
    ) {
    }

    /**
     * @param  array<int, int>  $tagIds
     */
    public function create(
        string $title,
        string $content,
        User $author,
        DateTimeInterface $createdAt,
        array $tagIds = [],
    ): Discussion {
        $when = Dates::toUtc($createdAt);

        return $this->db->transaction(function () use ($title, $content, $author, $when, $tagIds) {
            $discussion = Discussion::start($this->trimTitle($title), $author);
            $discussion->created_at = $when;
            $discussion->save();

            $post = $this->posts->write($discussion->id, $content, $author, $when);

            // Creating the post touched the discussion row (Post::created saves
            // it); take those attributes back before overwriting our own.
            $discussion->setRawAttributes($post->discussion->getAttributes(), true);
            $discussion->setFirstPost($post);
            $discussion->setLastPost($post);
            $discussion->refreshCommentCount();
            $discussion->refreshParticipantCount();
            $discussion->save();

            if ($tagIds !== []) {
                $this->attachTags($discussion->id, $tagIds);
            }

            return $discussion;
        });
    }

    /**
     * Written straight to the pivot table rather than through the Tags
     * extension's relation, so this file has no hard dependency on flarum/tags.
     *
     * @param  array<int, int>  $tagIds
     */
    protected function attachTags(int $discussionId, array $tagIds): void
    {
        /** @var \Illuminate\Database\Connection $connection */
        $connection = $this->db;

        if (! $connection->getSchemaBuilder()->hasTable('discussion_tag')) {
            return;
        }

        $rows = [];

        foreach (array_unique($tagIds) as $tagId) {
            $rows[] = ['discussion_id' => $discussionId, 'tag_id' => $tagId];
        }

        $this->db->table('discussion_tag')->insertOrIgnore($rows);
    }

    protected function trimTitle(string $title): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);

        return $title === '' ? 'Untitled discussion' : mb_substr($title, 0, 190);
    }
}
