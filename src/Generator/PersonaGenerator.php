<?php

namespace Pbiaut\AiSeeder\Generator;

use Pbiaut\AiSeeder\OpenAI\Client;
use Pbiaut\AiSeeder\OpenAI\OpenAiException;

/**
 * Generates member personas in bulk (one API call per batch of members),
 * because asking for 25 distinct people at once yields far more variety than
 * 25 independent calls that all drift towards the same archetype.
 */
class PersonaGenerator
{
    public const BATCH_SIZE = 25;

    public function __construct(
        protected Client $client,
        protected PromptBuilder $prompts,
    ) {
    }

    /**
     * @param  array<int, string>  $existingUsernames  already taken, to avoid collisions
     * @return array<int, array{username: string, display_name: string, bio: string, voice: string, interests: array<int, string>}>
     *
     * @throws OpenAiException
     */
    public function generate(int $count, GenerationContext $context, array $existingUsernames = [], ?string $model = null): array
    {
        if ($count <= 0) {
            return [];
        }

        $system = $this->prompts->system(
            $context,
            'You invent believable forum members: a mix of ages, backgrounds, expertise levels and writing habits.'
        );

        $user = implode("\n", array_filter([
            "Invent $count distinct members of this forum.",
            '',
            'For each one give:',
            '- "username": lowercase, 3 to 20 characters, letters/digits/underscore/hyphen only, no spaces, no accents. Make them look like real handles, not first_name_1.',
            '- "display_name": how they sign, can differ from the username.',
            '- "bio": one or two sentences, who they are and why they are on this forum.',
            '- "voice": one short sentence describing how they write (verbose, terse, sarcastic, always asks questions, posts from their phone...).',
            '- "interests": 2 to 4 short keywords.',
            '',
            'Make their expertise levels genuinely uneven: beginners, hobbyists, one or two clear experts, a lurker who posts rarely.',
            $existingUsernames === []
                ? null
                : 'These usernames are already taken, do not reuse them: '.implode(', ', array_slice($existingUsernames, 0, 200)).'.',
            '',
            'Answer as {"members": [ ... ]}.',
        ]));

        $response = $this->client->chatJson($system, $user, $model);
        $members = $response['members'] ?? $response['users'] ?? [];

        if (! is_array($members) || $members === []) {
            throw new OpenAiException('The model returned no members.', 0, true);
        }

        $personas = [];
        $taken = array_flip(array_map('strtolower', $existingUsernames));

        foreach ($members as $member) {
            if (! is_array($member)) {
                continue;
            }

            $username = $this->normaliseUsername((string) ($member['username'] ?? ''), $taken);
            $taken[$username] = true;

            $personas[] = [
                'username' => $username,
                'display_name' => $this->clean((string) ($member['display_name'] ?? $username), 60),
                'bio' => $this->clean((string) ($member['bio'] ?? ''), 400),
                'voice' => $this->clean((string) ($member['voice'] ?? ''), 300),
                'interests' => $this->interests($member['interests'] ?? []),
            ];

            if (count($personas) >= $count) {
                break;
            }
        }

        return $personas;
    }

    /**
     * Flarum usernames allow letters, digits, underscore and hyphen only.
     *
     * @param  array<string, mixed>  $taken
     */
    protected function normaliseUsername(string $raw, array $taken): string
    {
        $username = strtolower(trim($raw));

        if (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $username);

            if (is_string($ascii)) {
                $username = $ascii;
            }
        }

        $username = preg_replace('/[^a-z0-9_-]/', '', $username) ?? '';
        $username = trim($username, '-_');

        if (strlen($username) < 3) {
            $username = 'member'.random_int(100, 999);
        }

        $username = substr($username, 0, 20);
        $base = $username;
        $suffix = 1;

        while (isset($taken[$username])) {
            $suffix++;
            $username = substr($base, 0, 20 - strlen((string) $suffix)).$suffix;
        }

        return $username;
    }

    /**
     * @return array<int, string>
     */
    protected function interests(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $interests = [];

        foreach (array_slice($raw, 0, 5) as $interest) {
            if (is_scalar($interest)) {
                $interests[] = $this->clean((string) $interest, 40);
            }
        }

        return array_values(array_filter($interests));
    }

    protected function clean(string $value, int $max): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $value) ?? $value), 0, $max);
    }
}
