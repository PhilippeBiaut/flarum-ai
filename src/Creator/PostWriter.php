<?php

namespace Pbiaut\AiSeeder\Creator;

use Carbon\Carbon;
use Flarum\Post\CommentPost;
use Flarum\User\User;

/**
 * The one place that actually writes a comment row.
 *
 * Three details matter here:
 *  - CommentPost::reply() runs the content through Flarum's text formatter;
 *    writing raw Markdown into posts.content produces posts that render as
 *    escaped junk;
 *  - it stamps created_at with "now", so the backdating has to overwrite it
 *    before the model is saved;
 *  - `number` must NOT be set: Post::boot() assigns it with a MAX(number)+1
 *    SQL expression, which is also what keeps it race-free.
 *
 * The Posted event it raises is never released (that is the command handler's
 * job, and we bypass it), so no notifications go out for backdated content.
 */
class PostWriter
{
    public function write(int $discussionId, string $content, User $author, Carbon $createdAt): CommentPost
    {
        $post = CommentPost::reply(
            $discussionId,
            $this->trim($content),
            $author->id,
            null,
            $author
        );

        $post->created_at = $createdAt;
        $post->is_private = false;

        $post->save();

        // Post::created() already refreshes the model, so `number` is a real
        // integer by now rather than the SQL expression.
        return $post;
    }

    protected function trim(string $content): string
    {
        $content = trim($content);

        if ($content === '') {
            $content = '...';
        }

        // Flarum's own limit is 65535 characters of raw text; stay well under.
        return mb_substr($content, 0, 60000);
    }
}
