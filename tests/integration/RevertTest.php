<?php

namespace Pbiaut\AiSeeder\Tests\integration;

use Carbon\Carbon;
use DateTimeImmutable;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;
use Pbiaut\AiSeeder\Creator\DiscussionCreator;
use Pbiaut\AiSeeder\Creator\ReplyCreator;
use Pbiaut\AiSeeder\Creator\UserCreator;
use Pbiaut\AiSeeder\Model\Batch;
use Pbiaut\AiSeeder\Model\Item;
use Pbiaut\AiSeeder\Service\RevertRunner;

/**
 * Rollback has to be total (nothing generated survives) and safe (nothing that
 * was already there is touched).
 */
class RevertTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('pbiaut-ai-seeder');
        $this->prepareDatabase([
            'users' => [
                ['id' => 2, 'username' => 'real_member', 'email' => 'real@example.com', 'is_email_confirmed' => 1],
            ],
            'discussions' => [
                ['id' => 1, 'title' => 'A pre-existing discussion', 'created_at' => '2025-06-01 10:00:00', 'user_id' => 2, 'comment_count' => 1],
            ],
            'posts' => [
                ['id' => 1, 'discussion_id' => 1, 'created_at' => '2025-06-01 10:00:00', 'user_id' => 2, 'type' => 'comment', 'content' => '<t>Real content</t>', 'number' => 1],
            ],
        ]);
    }

    private function make(string $class): object
    {
        return $this->app()->getContainer()->make($class);
    }

    /**
     * Builds a small finished batch by hand: two members, one discussion,
     * two replies, each traced by an item row exactly as the runner does.
     */
    private function seedBatch(): Batch
    {
        /** @var UserCreator $users */
        $users = $this->make(UserCreator::class);
        /** @var DiscussionCreator $discussions */
        $discussions = $this->make(DiscussionCreator::class);
        /** @var ReplyCreator $replyCreator */
        $replyCreator = $this->make(ReplyCreator::class);

        $batch = new Batch();
        $batch->status = Batch::STATUS_COMPLETED;
        $batch->config = ['date_start' => '2026-01-01', 'date_end' => '2026-05-31', 'tags' => []];
        $batch->created_at = Carbon::now();
        $batch->save();

        $author = $users->create(['username' => 'seeded_one'], new DateTimeImmutable('2026-01-02 08:00:00'));
        $responder = $users->create(['username' => 'seeded_two'], new DateTimeImmutable('2026-01-03 08:00:00'));

        foreach ([$author, $responder] as $user) {
            $this->item($batch, Item::TYPE_USER, $user->id);
        }

        $discussion = $discussions->create('Seeded thread', 'Seeded body', $author, new DateTimeImmutable('2026-02-01 10:00:00'));
        $this->item($batch, Item::TYPE_DISCUSSION, $discussion->id);

        foreach (['2026-02-01 12:00:00', '2026-02-02 09:00:00'] as $moment) {
            $post = $replyCreator->create($discussion, 'Seeded reply', $responder, new DateTimeImmutable($moment));
            $this->item($batch, Item::TYPE_REPLY, $post->id);
        }

        $batch->users_created = 2;
        $batch->discussions_created = 1;
        $batch->replies_created = 2;
        $batch->save();

        return $batch;
    }

    private function item(Batch $batch, string $type, int $targetId): void
    {
        $item = new Item();
        $item->batch_id = $batch->id;
        $item->type = $type;
        $item->target_id = $targetId;
        $item->scheduled_at = Carbon::parse('2026-02-01 10:00:00');
        $item->status = Item::STATUS_DONE;
        $item->save();
    }

    #[Test]
    public function reverting_removes_everything_the_batch_created(): void
    {
        $batch = $this->seedBatch();

        // Root admin + the pre-existing member + the two seeded ones.
        $this->assertSame(4, User::count());
        $this->assertSame(2, Discussion::count());

        /** @var RevertRunner $runner */
        $runner = $this->make(RevertRunner::class);

        // Slices, exactly like the queue would.
        $guard = 0;

        while ($runner->run($batch) && $guard++ < 50) {
            $batch->refresh();
        }

        $batch->refresh();

        $this->assertSame(Batch::STATUS_REVERTED, $batch->status);
        $this->assertSame(0, Item::where('batch_id', $batch->id)->count());
        $this->assertSame(0, User::where('email', 'like', '%@example.invalid')->count());
        $this->assertSame(0, $batch->users_created + $batch->discussions_created + $batch->replies_created);
    }

    #[Test]
    public function pre_existing_content_is_left_alone(): void
    {
        $batch = $this->seedBatch();

        /** @var RevertRunner $runner */
        $runner = $this->make(RevertRunner::class);

        $guard = 0;

        while ($runner->run($batch) && $guard++ < 50) {
            $batch->refresh();
        }

        $this->assertNotNull(User::find(2), 'the real member survives');
        $this->assertNotNull(User::find(1), 'the root admin survives');
        $this->assertNotNull(Discussion::find(1), 'the pre-existing discussion survives');
        $this->assertNotNull(Post::find(1), 'the pre-existing post survives');
        $this->assertSame(1, Discussion::count());
    }
}
