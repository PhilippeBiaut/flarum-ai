<?php

namespace Pbiaut\AiSeeder\Model;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per entity the batch has to create. This table is the sole trace
 * linking generated content back to the batch that produced it (internal
 * marking): nothing is added to Flarum's own tables.
 *
 * @property int $id
 * @property int $batch_id
 * @property string $type
 * @property Carbon $scheduled_at
 * @property int|null $parent_item_id
 * @property int|null $author_item_id
 * @property int|null $target_id
 * @property int $position
 * @property array|null $payload
 * @property string $status
 * @property int $attempts
 * @property string|null $error
 */
class Item extends AbstractModel
{
    public const TYPE_USER = 'user';
    public const TYPE_DISCUSSION = 'discussion';
    public const TYPE_REPLY = 'reply';

    /**
     * Tagging an existing discussion. Unlike the others, target_id points at
     * something the seeder did not create, so a rollback must remove only the
     * tags it added rather than delete anything.
     */
    public const TYPE_TAGGING = 'tagging';

    public const STATUS_PENDING = 'pending';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    /**
     * Beyond this an item is given up on. The OpenAI client already retries
     * transient failures several times internally, so reaching this many
     * attempts means something is genuinely wrong.
     */
    public const MAX_ATTEMPTS = 4;

    protected $table = 'ai_seeder_items';

    /** Same reasoning as Batch: column defaults live in the database, not here. */
    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'position' => 0,
        'attempts' => 0,
    ];

    protected $casts = [
        'payload' => 'array',
        'position' => 'integer',
        'attempts' => 'integer',
        'scheduled_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Batch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }

    /**
     * @param  Builder<Item>  $query
     * @return Builder<Item>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->payload[$key] ?? $default;
    }

    public function mergePayload(array $data): void
    {
        $this->payload = array_merge($this->payload ?? [], $data);
    }

    public function markFailed(string $message): void
    {
        $this->attempts++;
        $this->error = mb_substr($message, 0, 2000);
        $this->status = $this->attempts >= self::MAX_ATTEMPTS
            ? self::STATUS_FAILED
            : self::STATUS_PENDING;
        $this->save();
    }
}
