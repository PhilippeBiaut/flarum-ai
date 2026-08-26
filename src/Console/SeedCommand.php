<?php

namespace Pbiaut\AiSeeder\Console;

use Flarum\Console\AbstractCommand;
use Pbiaut\AiSeeder\Api\BatchPresenter;
use Pbiaut\AiSeeder\Model\Batch;
use Pbiaut\AiSeeder\Planner\InvalidConfigException;
use Pbiaut\AiSeeder\Service\BatchRunner;
use Pbiaut\AiSeeder\Service\BatchService;
use Pbiaut\AiSeeder\Service\RevertRunner;
use Pbiaut\AiSeeder\Service\SeederSettings;
use Symfony\Component\Console\Input\InputOption;

/**
 * Command-line counterpart to the admin screen.
 *
 * Useful in two situations the queue does not cover: hosts where no worker can
 * run at all, and very large runs that are more comfortable to watch in a
 * terminal. It drives the exact same services as the queued path.
 */
class SeedCommand extends AbstractCommand
{
    public function __construct(
        protected BatchService $batches,
        protected BatchRunner $runner,
        protected RevertRunner $reverter,
        protected BatchPresenter $presenter,
        protected SeederSettings $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('ai-seeder:run')
            ->setDescription('Generate members, discussions and replies with OpenAI, spread over a period.')
            ->addOption('users', null, InputOption::VALUE_REQUIRED, 'How many members to create')
            ->addOption('discussions', null, InputOption::VALUE_REQUIRED, 'How many discussions to create')
            ->addOption('replies', null, InputOption::VALUE_REQUIRED, 'How many replies to spread across them')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Period start, YYYY-MM-DD')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Period end, YYYY-MM-DD')
            ->addOption('distribution', null, InputOption::VALUE_REQUIRED, 'organic (default), uniform or random')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'OpenAI model, defaults to the configured one')
            ->addOption('theme', null, InputOption::VALUE_REQUIRED, 'What the forum is about')
            ->addOption('language', null, InputOption::VALUE_REQUIRED, 'Language to write in')
            ->addOption('seed', null, InputOption::VALUE_REQUIRED, 'Seed, to reproduce a previous plan')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show the day-by-day plan, generate nothing')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Run an existing batch to completion')
            ->addOption('revert', null, InputOption::VALUE_REQUIRED, 'Delete everything a batch created')
            ->addOption('list', null, InputOption::VALUE_NONE, 'List recent batches');
    }

    protected function fire(): int
    {
        try {
            return match (true) {
                (bool) $this->input->getOption('list') => $this->listBatches(),
                $this->input->getOption('revert') !== null => $this->revert((int) $this->input->getOption('revert')),
                $this->input->getOption('batch') !== null => $this->process((int) $this->input->getOption('batch')),
                default => $this->createAndRun(),
            };
        } catch (InvalidConfigException $e) {
            foreach ($e->errors as $field => $message) {
                $this->error("$field: $message");
            }

            return 1;
        }
    }

    protected function createAndRun(): int
    {
        $config = array_filter([
            'users' => $this->input->getOption('users'),
            'discussions' => $this->input->getOption('discussions'),
            'replies' => $this->input->getOption('replies'),
            'date_start' => $this->input->getOption('from'),
            'date_end' => $this->input->getOption('to'),
            'distribution' => $this->input->getOption('distribution'),
            'model' => $this->input->getOption('model'),
            'theme' => $this->input->getOption('theme'),
            'language' => $this->input->getOption('language'),
            'seed' => $this->input->getOption('seed'),
        ], fn ($value) => $value !== null);

        if ($this->input->getOption('dry-run')) {
            return $this->showPlan($this->batches->preview($config));
        }

        if (! $this->settings->isConfigured()) {
            $this->error('No OpenAI API key configured. Set it in the admin panel first.');

            return 1;
        }

        $batch = $this->batches->create($config);
        $this->info('Created batch #'.$batch->id.'.');

        return $this->process($batch->id);
    }

    /**
     * @param  array<string, mixed>  $preview
     */
    protected function showPlan(array $preview): int
    {
        $totals = $preview['totals'];

        $this->info(sprintf(
            'Plan (seed %d): %d members, %d discussions, %d replies over %d days.',
            $preview['seed'],
            $totals['users'],
            $totals['discussions'],
            $totals['replies'],
            $totals['days']
        ));
        $this->info(sprintf(
            'Average per day: %s discussions, %s replies. Busiest day: %s (%d posts).',
            $totals['avg_discussions_per_day'],
            $totals['avg_replies_per_day'],
            $totals['peak_day'] ?? '-',
            $totals['peak_activity']
        ));

        $estimate = $preview['estimate'];
        $cost = $estimate['cost'] === null
            ? 'unknown (set the token prices in the settings to get one)'
            : $estimate['cost'].' '.$estimate['currency'];

        $this->info(sprintf(
            'Estimated %d API calls, ~%d input / ~%d output tokens, cost: %s.',
            $estimate['api_calls'],
            $estimate['tokens_in'],
            $estimate['tokens_out'],
            $cost
        ));

        foreach ($preview['warnings'] as $warning) {
            $this->error('Warning: '.$warning);
        }

        $this->output->writeln('');
        $this->output->writeln('date        signups  discussions  replies');

        foreach ($preview['days'] as $day) {
            if ($day['signups'] + $day['discussions'] + $day['replies'] === 0) {
                continue;
            }

            $this->output->writeln(sprintf(
                '%s  %7d  %11d  %7d',
                $day['date'],
                $day['signups'],
                $day['discussions'],
                $day['replies']
            ));
        }

        return 0;
    }

    protected function process(int $batchId): int
    {
        $batch = Batch::find($batchId);

        if ($batch === null) {
            $this->error("Batch #$batchId does not exist.");

            return 1;
        }

        $log = fn (string $message) => $this->info($message);

        while ($this->runner->run($batch, $log)) {
            $batch->refresh();

            if ($batch->isHalted()) {
                break;
            }

            // Mirrors the queue's back-off: after a rate limit, wait instead of
            // immediately hammering the API again.
            if ($this->runner->retryAfter > 2) {
                $this->info('Waiting '.$this->runner->retryAfter.'s before the next slice...');
                sleep($this->runner->retryAfter);
            }
        }

        $batch->refresh();
        $this->info(sprintf(
            'Batch #%d is now %s: %d members, %d discussions, %d replies, %d failed (%d API calls).',
            $batch->id,
            $batch->status,
            $batch->users_created,
            $batch->discussions_created,
            $batch->replies_created,
            $batch->failed_count,
            $batch->api_calls
        ));

        if ($batch->error) {
            $this->error($batch->error);
        }

        return $batch->status === Batch::STATUS_COMPLETED ? 0 : 1;
    }

    protected function revert(int $batchId): int
    {
        $batch = Batch::find($batchId);

        if ($batch === null) {
            $this->error("Batch #$batchId does not exist.");

            return 1;
        }

        $log = fn (string $message) => $this->info($message);

        while ($this->reverter->run($batch, $log)) {
            $batch->refresh();
        }

        return 0;
    }

    protected function listBatches(): int
    {
        $batches = Batch::query()->orderByDesc('id')->limit(30)->get();

        if ($batches->isEmpty()) {
            $this->info('No batch yet.');

            return 0;
        }

        $this->output->writeln('  id  status      members  discussions  replies  period');

        foreach ($batches as $batch) {
            $this->output->writeln(sprintf(
                '%4d  %-10s  %7d  %11d  %7d  %s -> %s',
                $batch->id,
                $batch->status,
                $batch->users_created,
                $batch->discussions_created,
                $batch->replies_created,
                $batch->config['date_start'] ?? '?',
                $batch->config['date_end'] ?? '?'
            ));
        }

        return 0;
    }
}
