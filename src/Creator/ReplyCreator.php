<?php

namespace Pbiaut\AiSeeder\Creator;

use DateTimeInterface;
use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Appends replies to an existing discussion, backdated, then brings the
 * discussion's own counters back in line.
 */
class ReplyCreator
{
    public function __construct(
        protected ConnectionInterface $db,
        protected PostWriter $posts,
    ) {
    }

    public function create(Discussion $discussion, string $content, User $author, DateTimeInterface $createdAt): CommentPost
    {
        $when = Dates::toUtc($createdAt);

        return $this->db->transaction(function () use ($discussion, $content, $author, $when) {
            $post = $this->posts->write($discussion->id, $content, $author, $when);

            $discussion->setRawAttributes($post->discussion->getAttributes(), true);
            $discussion->setLastPost($post);
            $discussion->refreshCommentCount();
            $discussion->refreshParticipantCount();
            $discussion->save();

            return $post;
        });
    }
}
