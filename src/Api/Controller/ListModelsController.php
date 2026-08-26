<?php

namespace Pbiaut\AiSeeder\Api\Controller;

use Pbiaut\AiSeeder\OpenAI\Client;
use Pbiaut\AiSeeder\Service\SeederSettings;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Lists the models the configured key can actually use.
 *
 * Fetched live rather than hardcoded: the OpenAI line-up changes several times
 * a year, and the same endpoint also works against Azure, OpenRouter or a local
 * server, so whatever the admin points the base URL at is what they get.
 */
class ListModelsController extends AbstractSeederController
{
    public function __construct(
        protected Client $client,
        protected SeederSettings $settings,
    ) {
    }

    protected function data(ServerRequestInterface $request): array
    {
        if (! $this->client->isConfigured()) {
            return [
                'configured' => false,
                'models' => [],
                'message' => 'No API key set yet.',
            ];
        }

        $models = $this->client->listModels();

        return [
            'configured' => true,
            'base_url' => $this->settings->baseUrl(),
            'selected' => $this->settings->model(),
            // Chat-capable ids first; the raw list stays available underneath so
            // nothing is hidden if the naming scheme changes again.
            'suggested' => array_values(array_filter(
                $models,
                fn (string $id) => (bool) preg_match('/^(gpt|o\d|chatgpt)/i', $id)
                    && ! preg_match('/(embedding|whisper|tts|audio|image|dall|moderation|realtime|transcribe)/i', $id)
            )),
            'models' => $models,
        ];
    }
}
