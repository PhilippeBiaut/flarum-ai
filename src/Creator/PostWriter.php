<?php

namespace Pbiaut\AiSeeder\Creator;

use Carbon\Carbon;
use Flarum\Post\CommentPost;
use Flarum\User\User;

/**
 * The one place that actually writes a comment row.
 *
 * Two details matter here:
 *  - content must go through setContentAttribute(), which runs Flarum's text
 *    formatter; writing raw Markdown into posts.content produces posts that
 *    render as escaped junk;
 *  - `number` must NOT be set: Post::boot() assigns it with a MAX(number)+1
 *    SQL expression, which is also what keeps it race-free.
 */
class PostWriter
{
    public function write(int $discussionId, string $content, User $author, Carbon $createdAt): CommentPost
    {
        $post = new CommentPost();
        $post->discussion_id = $discussionId;
        $post->user_id = $author->id;
        $post->created_at = $createdAt;
        $post->ip_address = null;
        $post->is_private = false;
        $post->setContentAttribute($this->trim($content), $author);

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

        // Flarum's own limit is 63000 characters of raw text.
        return mb_substr($content, 0, 60000);
    }
}
