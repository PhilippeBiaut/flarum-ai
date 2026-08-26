<?php

namespace Pbiaut\AiSeeder\Console;

use Flarum\Console\AbstractCommand;
use Pbiaut\AiSeeder\Service\BatchRunner;
use Pbiaut\AiSeeder\Service\DailyScheduler;
use Pbiaut\AiSeeder\Service\QueueInspector;
use Symfony\Component\Console\Input\InputOption;

/**
 * Creates - and, without a queue worker, runs - today's batch.
 *
 * Registered on Flarum's scheduler, so a forum with the standard
 * `php flarum schedule:run` cron keeps growing on its own. Safe to call more
 * often than daily: the scheduler itself decides whether anything is due.
 */
class DailyCommand extends AbstractCommand
{
    public function __construct(
        protected DailyScheduler $daily,
        protected BatchRunner $runner,
        protected QueueInspector $queues,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('ai-seeder:daily')
            ->setDescription("Generate today's batch, if one is due.")
            ->addOption('force', null, InputOption::VALUE_NONE, 'Generate even if today already ran, or the schedule is off');
    }

    protected function fire(): int
    {
        $batch = $this->daily->runToday((bool) $this->input->getOption('force'));

        if ($batch === null) {
            $this->info('Nothing due today.');

            return 0;
        }

        $this->info('Created batch #'.$batch->id.' for today.');

        if (! $this->queues->isSync()) {
            $this->info('Queued; the worker will pick it up.');

            return 0;
        }

        // No worker on this forum, and nobody is watching a browser tab at
        // three in the morning: run it here and now.
        $this->info('No queue worker, running it now...');

        $log = fn (string $message) => $this->info($message);

        while ($this->runner->run($batch, $log)) {
            $batch->refresh();

            if ($batch->isHalted()) {
                break;
            }
        }

        $batch->refresh();

        $this->info(sprintf(
            'Batch #%d is now %s: %d members, %d discussions, %d replies, %d failed.',
            $batch->id,
            $batch->status,
            $batch->users_created,
            $batch->discussions_created,
            $batch->replies_created,
            $batch->failed_count
        ));

        return 0;
    }
}
