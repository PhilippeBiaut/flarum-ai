<?php

namespace Pbiaut\AiSeeder\Service;

use Pbiaut\AiSeeder\Generator\PersonaGenerator;
use Pbiaut\AiSeeder\Generator\ReplyBundleGenerator;
use Pbiaut\AiSeeder\Generator\TagClassifier;
use Pbiaut\AiSeeder\Generator\TopicGenerator;
use Pbiaut\AiSeeder\Planner\PlanConfig;
use Pbiaut\AiSeeder\Planner\PlanResult;

/**
 * Rough "what will this run cost me" figure, shown before the admin commits.
 *
 * Token counts are estimates from the prompt shapes; prices come from settings
 * the admin fills in, because published OpenAI rates change far too often to be
 * hardcoded in an extension.
 */
class CostEstimator
{
    // Per-call prompt overhead and per-item output, in tokens.
    private const PERSONA_PROMPT = 700;
    private const PERSONA_OUTPUT_PER_MEMBER = 130;

    private const TITLE_PROMPT = 450;
    private const TITLE_CONTEXT_PER_TITLE = 14;
    private const TITLE_OUTPUT_PER_TITLE = 18;

    private const BODY_PROMPT = 520;
    private const BODY_OUTPUT = 260;

    private const REPLY_PROMPT = 900;
    private const REPLY_PROMPT_PER_PERSONA = 90;
    private const REPLY_OUTPUT_PER_REPLY = 140;

    // Classifying existing discussions: the category list plus, per thread, a
    // title and a 500-character excerpt in, one short line out.
    private const CLASSIFY_PROMPT = 400;
    private const CLASSIFY_PER_THREAD = 180;
    private const CLASSIFY_OUTPUT_PER_THREAD = 20;

    public function __construct(protected SeederSettings $settings)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function estimate(PlanConfig $config, PlanResult $plan): array
    {
        $users = count($plan->users);
        $discussions = count($plan->discussions);

        $personaCalls = (int) ceil($users / PersonaGenerator::BATCH_SIZE);
        $titleCalls = (int) ceil($discussions / TopicGenerator::BATCH_SIZE);
        $bodyCalls = $discussions;

        $replyCalls = 0;
        $replyTokensIn = 0;
        $replyTokensOut = 0;

        foreach ($plan->discussions as $discussion) {
            $replies = count($discussion['replies']);

            if ($replies === 0) {
                continue;
            }

            $calls = (int) ceil($replies / ReplyBundleGenerator::MAX_PER_CALL);
            $replyCalls += $calls;
            $replyTokensIn += $calls * self::REPLY_PROMPT + $replies * self::REPLY_PROMPT_PER_PERSONA;
            $replyTokensOut += $replies * self::REPLY_OUTPUT_PER_REPLY;
        }

        $tokensIn = $personaCalls * self::PERSONA_PROMPT
            + $titleCalls * (self::TITLE_PROMPT + TopicGenerator::CONTEXT_TITLES * self::TITLE_CONTEXT_PER_TITLE)
            + $bodyCalls * self::BODY_PROMPT
            + $replyTokensIn;

        $tokensOut = $users * self::PERSONA_OUTPUT_PER_MEMBER
            + $discussions * self::TITLE_OUTPUT_PER_TITLE
            + $bodyCalls * self::BODY_OUTPUT
            + $replyTokensOut;

        $calls = $personaCalls + $titleCalls + $bodyCalls + $replyCalls;

        $priceIn = $this->settings->priceInput();
        $priceOut = $this->settings->priceOutput();
        $hasPrices = $priceIn > 0 || $priceOut > 0;

        return [
            'api_calls' => $calls,
            'calls_breakdown' => [
                'personas' => $personaCalls,
                'titles' => $titleCalls,
                'bodies' => $bodyCalls,
                'replies' => $replyCalls,
            ],
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
            'cost' => $hasPrices
                ? round(($tokensIn / 1_000_000) * $priceIn + ($tokensOut / 1_000_000) * $priceOut, 2)
                : null,
            'currency' => $this->settings->currency(),
            'queue_runs' => (int) ceil($calls / max(1, $this->settings->callsPerRun())),
            'accuracy' => 'Estimate only: real token usage depends on the model and on how verbose it is.',
            'prices_missing' => ! $hasPrices,
            'seed' => $config->seed,
        ];
    }

    /**
     * Cost of classifying existing discussions: one call per batch of threads,
     * each contributing a title and a short excerpt in, and one line out.
     *
     * @return array<string, mixed>
     */
    public function estimateTagging(int $discussions): array
    {
        $calls = (int) ceil($discussions / TagClassifier::BATCH_SIZE);

        $tokensIn = $calls * self::CLASSIFY_PROMPT + $discussions * self::CLASSIFY_PER_THREAD;
        $tokensOut = $discussions * self::CLASSIFY_OUTPUT_PER_THREAD;

        $priceIn = $this->settings->priceInput();
        $priceOut = $this->settings->priceOutput();
        $hasPrices = $priceIn > 0 || $priceOut > 0;

        return [
            'api_calls' => $calls,
            'calls_breakdown' => ['classify' => $calls],
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
            'cost' => $hasPrices
                ? round(($tokensIn / 1_000_000) * $priceIn + ($tokensOut / 1_000_000) * $priceOut, 2)
                : null,
            'currency' => $this->settings->currency(),
            'queue_runs' => (int) ceil($calls / max(1, $this->settings->callsPerRun())),
            'accuracy' => 'Estimate only: real token usage depends on how long the threads are.',
            'prices_missing' => ! $hasPrices,
            'seed' => 0,
        ];
    }

    /**
     * Actual cost of a finished run, from the tokens really consumed.
     *
     * @return array{cost: float|null, currency: string}
     */
    public function actual(int $tokensIn, int $tokensOut): array
    {
        $priceIn = $this->settings->priceInput();
        $priceOut = $this->settings->priceOutput();

        return [
            'cost' => ($priceIn > 0 || $priceOut > 0)
                ? round(($tokensIn / 1_000_000) * $priceIn + ($tokensOut / 1_000_000) * $priceOut, 2)
                : null,
            'currency' => $this->settings->currency(),
        ];
    }
}
