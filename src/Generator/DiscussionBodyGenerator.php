<?php

namespace Pbiaut\AiSeeder\Generator;

use Pbiaut\AiSeeder\OpenAI\Client;
use Pbiaut\AiSeeder\OpenAI\OpenAiException;

/**
 * Writes the opening post of a thread, in the voice of its planned author.
 */
class DiscussionBodyGenerator
{
    public function __construct(
        protected Client $client,
        protected PromptBuilder $prompts,
    ) {
    }

    /**
     * @param  array<string, mixed>  $persona
     *
     * @throws OpenAiException
     */
    public function generate(
        string $title,
        array $persona,
        ?string $tagName,
        GenerationContext $context,
        ?string $model = null,
    ): string {
        $system = $this->prompts->system(
            $context,
            'You write the opening post of a forum thread, staying strictly in the voice of the member described.'
        );

        $user = implode("\n", array_filter([
            'Thread title: '.$title,
            $tagName === null || $tagName === '' ? null : 'Category: '.$tagName,
            '',
            $this->prompts->describePersona($persona),
            '',
            'Write the opening message of this thread.',
            'It must read like this person, not like a well-structured article: they may give context, hesitate,',
            'add a detail at the end, or ask something very specific.',
            'Anything between 2 and 12 sentences depending on how much this person usually writes.',
            'Do not repeat the title as a heading, do not sign the message.',
            '',
            'Answer as {"content": "..."}.',
        ]));

        $response = $this->client->chatJson($system, $user, $model);
        $content = $response['content'] ?? $response['post'] ?? $response['body'] ?? '';

        if (! is_string($content) || trim($content) === '') {
            throw new OpenAiException('The model returned an empty opening post.', 0, true);
        }

        return trim($content);
    }
}
