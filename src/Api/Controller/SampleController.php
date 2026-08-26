<?php

namespace Pbiaut\AiSeeder\Api\Controller;

use Pbiaut\AiSeeder\OpenAI\Client;
use Pbiaut\AiSeeder\Service\SampleGenerator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Generates one thread and returns it as text. Nothing is written to the forum.
 */
class SampleController extends AbstractSeederController
{
    public function __construct(
        protected SampleGenerator $samples,
        protected Client $client,
    ) {
    }

    protected function data(ServerRequestInterface $request): array
    {
        $this->client->resetUsage();

        $sample = $this->samples->generate($this->body($request));

        return array_merge($sample, ['usage' => $this->client->usage()]);
    }
}
