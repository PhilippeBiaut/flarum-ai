<?php

namespace Pbiaut\AiSeeder\Service;

use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;

/**
 * Per-batch run log, surfaced in the admin.
 *
 * Generation happens in a queue worker, so when something goes wrong the admin
 * sees only a generic error in the browser and has to go read Flarum's log file
 * on the server. Writing the run's own trace to a table puts the actual reason
 * in front of them instead.
 */
class RunLogger
{
    public const INFO = 'info';
    public const ERROR = 'error';
    public const WARNING = 'warning';

    /** Oldest lines are dropped past this, per batch. */
    public const KEEP = 400;

    public function __construct(protected ConnectionInterface $db)
    {
    }

    public function write(int $batchId, string $message, string $level = self::INFO): void
    {
        $this->db->table('ai_seeder_logs')->insert([
            'batch_id' => $batchId,
            'level' => $level,
            'message' => mb_substr(trim($message), 0, 2000),
            'created_at' => Carbon::now(),
        ]);

        // Cheap trim: only bother once in a while, and only when it can matter.
        if (random_int(1, 25) === 1) {
            $this->trim($batchId);
        }
    }

    public function error(int $batchId, string $message): void
    {
        $this->write($batchId, $message, self::ERROR);
    }

    public function warning(int $batchId, string $message): void
    {
        $this->write($batchId, $message, self::WARNING);
    }

    /**
     * @return array<int, array{id: int, level: string, message: string, created_at: string}>
     */
    public function since(int $batchId, int $afterId = 0, int $limit = 200): array
    {
        $rows = $this->db->table('ai_seeder_logs')
            ->where('batch_id', $batchId)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $entries = [];

        foreach ($rows as $row) {
            $entries[] = [
                'id' => (int) $row->id,
                'level' => (string) $row->level,
                'message' => (string) $row->message,
                'created_at' => (string) $row->created_at,
            ];
        }

        return $entries;
    }

    protected function trim(int $batchId): void
    {
        $cutoff = $this->db->table('ai_seeder_logs')
            ->where('batch_id', $batchId)
            ->orderByDesc('id')
            ->limit(1)
            ->offset(self::KEEP)
            ->value('id');

        if ($cutoff !== null) {
            $this->db->table('ai_seeder_logs')
                ->where('batch_id', $batchId)
                ->where('id', '<=', $cutoff)
                ->delete();
        }
    }
}
