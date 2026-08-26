<?php

namespace Pbiaut\AiSeeder\Tests\integration;

use DateTimeImmutable;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Pbiaut\AiSeeder\Creator\CounterRefresher;
use Pbiaut\AiSeeder\Creator\DiscussionCreator;
use Pbiaut\AiSeeder\Creator\ReplyCreator;
use Pbiaut\AiSeeder\Creator\UserCreator;

/**
 * The part that actually writes into Flarum's own tables: backdating, post
 * numbering, counters, and the fact that seeding stays silent.
 */
class CreatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('pbiaut-ai-seeder');
        $this->prepareDatabase([]);
    }

    private function make(string $class): object
    {
        return $this->app()->getContainer()->make($class);
    }

    private function member(string $username, string $joinedAt): User
    {
        /** @var UserCreator $creator */
        $creator = $this->make(UserCreator::class);

        return $creator->create(
            ['username' => $username, 'display_name' => $username, 'bio' => '', 'voice' => '', 'interests' => []],
            new DateTimeImmutable($joinedAt, new \DateTimeZone('UTC'))
        );
    }

    /** @test */
    public function a_generated_member_is_backdated_activated_and_silent(): void
    {
        $user = $this->member('ada_l', '2026-01-04 09:12:00');

        $this->assertSame('ada_l', $user->username);
        $this->assertSame('2026-01-04 09:12:00', $user->joined_at->format('Y-m-d H:i:s'));
        $this->assertTrue((bool) $user->is_email_confirmed);
        $this->assertStringEndsWith('@example.invalid', $user->email);

        // Flarum creates an email token whenever it asks a new member to
        // confirm their address. Seeding must never do that.
        $this->assertSame(0, $this->database()->table('email_tokens')->count());
    }

    /** @test */
    public function usernames_and_emails_never_collide(): void
    {
        $first = $this->member('ada_l', '2026-01-04 09:00:00');
        $second = $this->member('ada_l', '2026-01-05 09:00:00');

        $this->assertNotSame($first->username, $second->username);
        $this->assertNotSame($first->email, $second->email);
    }

    /** @test */
    public function a_discussion_and_its_opening_post_carry_the_planned_date(): void
    {
        $author = $this->member('grace_h', '2026-01-02 08:00:00');

        /** @var DiscussionCreator $creator */
        $creator = $this->make(DiscussionCreator::class);

        $discussion = $creator->create(
            'Anyone else seeing this?',
            'Since the last update my NAS wakes up every night at 3am. **Every** night.',
            $author,
            new DateTimeImmutable('2026-02-11 21:34:00', new \DateTimeZone('UTC'))
        );

        $discussion = Discussion::find($discussion->id);
        $firstPost = Post::find($discussion->first_post_id);

        $this->assertSame('2026-02-11 21:34:00', $discussion->created_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-02-11 21:34:00', $firstPost->created_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, $firstPost->number);
        $this->assertSame(1, $discussion->comment_count);
        $this->assertSame($author->id, $discussion->user_id);

        // Content went through the text formatter, not straight into the column.
        $this->assertStringContainsString('<', $firstPost->getRawOriginal('content'));
        $this->assertStringContainsString('Every', $firstPost->content);
    }

    /** @test */
    public function replies_are_numbered_in_order_and_update_the_discussion(): void
    {
        $author = $this->member('grace_h', '2026-01-02 08:00:00');
        $responder = $this->member('linus_t', '2026-01-03 08:00:00');

        /** @var DiscussionCreator $discussions */
        $discussions = $this->make(DiscussionCreator::class);
        /** @var ReplyCreator $replies */
        $replies = $this->make(ReplyCreator::class);

        $discussion = $discussions->create(
            'Anyone else seeing this?',
            'Opening post.',
            $author,
            new DateTimeImmutable('2026-02-11 21:34:00', new \DateTimeZone('UTC'))
        );

        $moments = ['2026-02-11 22:02:00', '2026-02-12 08:41:00', '2026-02-14 19:05:00'];

        foreach ($moments as $index => $moment) {
            $replies->create(
                $discussion,
                'Reply number '.($index + 1),
                $index % 2 === 0 ? $responder : $author,
                new DateTimeImmutable($moment, new \DateTimeZone('UTC'))
            );
        }

        $discussion = Discussion::find($discussion->id);
        $posts = Post::where('discussion_id', $discussion->id)->orderBy('number')->get();

        $this->assertSame([1, 2, 3, 4], $posts->pluck('number')->map('intval')->all());
        $this->assertSame(
            ['2026-02-11 21:34:00', '2026-02-11 22:02:00', '2026-02-12 08:41:00', '2026-02-14 19:05:00'],
            $posts->map(fn (Post $post) => $post->created_at->format('Y-m-d H:i:s'))->all()
        );

        $this->assertSame(4, $discussion->comment_count);
        $this->assertSame(2, $discussion->participant_count);
        $this->assertSame('2026-02-14 19:05:00', $discussion->last_posted_at->format('Y-m-d H:i:s'));
        $this->assertSame(4, (int) $discussion->last_post_number);

        // The discussion still opens on its own date, not on the last reply's.
        $this->assertSame('2026-02-11 21:34:00', $discussion->created_at->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function member_counters_are_brought_back_in_line(): void
    {
        $author = $this->member('grace_h', '2026-01-02 08:00:00');
        $responder = $this->member('linus_t', '2026-01-03 08:00:00');

        /** @var DiscussionCreator $discussions */
        $discussions = $this->make(DiscussionCreator::class);
        /** @var ReplyCreator $replies */
        $replies = $this->make(ReplyCreator::class);
        /** @var CounterRefresher $counters */
        $counters = $this->make(CounterRefresher::class);

        $discussion = $discussions->create('Title', 'Body', $author, new DateTimeImmutable('2026-03-01 10:00:00'));
        $replies->create($discussion, 'Sure', $responder, new DateTimeImmutable('2026-03-01 11:00:00'));

        $counters->refreshUsers([$author->id, $responder->id]);

        $this->assertSame(1, User::find($author->id)->discussion_count);
        $this->assertSame(1, User::find($author->id)->comment_count);
        $this->assertSame(0, User::find($responder->id)->discussion_count);
        $this->assertSame(1, User::find($responder->id)->comment_count);
    }
}
