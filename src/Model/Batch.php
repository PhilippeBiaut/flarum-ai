<?php

namespace Pbiaut\AiSeeder\Model;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $status
 * @property array $config
 * @property array|null $plan_summary
 * @property string|null $model
 * @property int $seed
 * @property int $users_planned
 * @property int $discussions_planned
 * @property int $replies_planned
 * @property int $users_created
 * @property int $discussions_created
 * @property int $replies_created
 * @property int $failed_count
 * @property int $tokens_in
 * @property int $tokens_out
 * @property int $api_calls
 * @property string|null $error
 * @property Carbon $created_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class Batch extends AbstractModel
{
    public const STATUS_PLANNED = 'planned';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REVERTING = 'reverting';
    public const STATUS_REVERTED = 'reverted';

    /** Statuses for which the queue worker must stop processing. */
    public const HALTED = [
        self::STATUS_PAUSED,
        self::STATUS_CANCELLED,
        self::STATUS_REVERTING,
        self::STATUS_REVERTED,
        self::STATUS_FAILED,
    ];

    protected $table = 'ai_seeder_batches';

    protected $casts = [
        'config' => 'array',
        'plan_summary' => 'array',
        'created_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'batch_id');
    }

    public function isHalted(): bool
    {
        return in_array($this->status, self::HALTED, true);
    }

    public function totalPlanned(): int
    {
        return $this->users_planned + $this->discussions_planned + $this->replies_planned;
    }

    public function totalCreated(): int
    {
        return $this->users_created + $this->discussions_created + $this->replies_created;
    }

    public function progress(): float
    {
        $total = $this->totalPlanned();

        if ($total === 0) {
            return 0.0;
        }

        return round((($this->totalCreated() + $this->failed_count) / $total) * 100, 1);
    }
}
