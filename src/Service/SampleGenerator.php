<?php

namespace Pbiaut\AiSeeder\Service;

use Pbiaut\AiSeeder\Generator\DiscussionBodyGenerator;
use Pbiaut\AiSeeder\Generator\GenerationContext;
use Pbiaut\AiSeeder\Generator\PersonaGenerator;
use Pbiaut\AiSeeder\Generator\ReplyBundleGenerator;
use Pbiaut\AiSeeder\Generator\TopicGenerator;
use Pbiaut\AiSeeder\Planner\PlanConfig;
use Pbiaut\AiSeeder\Planner\ReplyLength;
use Pbiaut\AiSeeder\Planner\ReplyTarget;
use Pbiaut\AiSeeder\Planner\ReplyType;
use Pbiaut\AiSeeder\Planner\Rng;
use Pbiaut\AiSeeder\Planner\ThreadArchetype;

/**
 * Generates one complete thread and returns it as text, writing nothing.
 *
 * Four API calls and a few cents to see the tone, the language, the lengths and
 * the way people answer each other - before committing to five hundred posts
 * and finding out the brief was wrong. This is the cheapest safeguard in the
 * extension.
 */
class SampleGenerator
{
    /** Enough people for a thread to have a conversation, not more. */
    private const MEMBERS = 5;

    private const REPLIES = 5;

    public function __construct(
        protected SeederSettings $settings,
        protected PersonaGenerator $personas,
        protected TopicGenerator $topics,
        protected DiscussionBodyGenerator $bodies,
        protected ReplyBundleGenerator $replies,
    ) {
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function generate(array $config): array
    {
        $planConfig = PlanConfig::fromArray(array_merge($config, [
            'users' => self::MEMBERS,
            'discussions' => 1,
            'replies' => self::REPLIES,
        ]), $this->settings->timezone());

        $context = GenerationContext::fromConfig($planConfig->toArray(), $this->settings->forumTitle());
        $model = (string) ($planConfig->generation('model') ?: $this->settings->model());
        $rng = new Rng($planConfig->seed);

        $personas = $this->personas->generate(self::MEMBERS, $context, [], $model);

        if ($personas === []) {
            return ['error' => 'The model returned no members.'];
        }

        $tag = $planConfig->tags === [] ? null : $planConfig->tags[0]['path'];
        $titles = $this->topics->generate([$tag], $context, [], $model);
        $title = $titles[0] ?? 'Untitled';

        $author = $personas[0];
        $opening = $this->bodies->generate($title, $author, $tag, $context, $model);

        // Same shape a real thread would get, so the sample is representative
        // rather than a best case.
        $archetype = ThreadArchetype::TROUBLESHOOT;
        $typeWeights = ThreadArchetype::typeWeights($archetype);

        $replyPersonas = [];
        $lengths = [];
        $targets = [];
        $types = [];

        for ($position = 0; $position < self::REPLIES; $position++) {
            $persona = $personas[($position % (count($personas) - 1)) + 1] ?? $personas[0];
            $target = ReplyTarget::draw($position, $rng);

            $replyPersonas[] = $persona;
            $lengths[] = ReplyLength::draw($rng)['words'];
            $targets[] = $target;
            $types[] = ReplyType::draw($position, self::REPLIES, $target > 0, false, $rng, $typeWeights);
        }

        $bodies = $this->replies->generate(
            $title,
            $opening,
            $author,
            $replyPersonas,
            $context,
            [],
            $model,
            $lengths,
            $targets,
            $types
        );

        $messages = [];

        foreach ($bodies as $index => $body) {
            $messages[] = [
                'author' => $replyPersonas[$index]['display_name'] ?? $replyPersonas[$index]['username'],
                'answers' => $targets[$index] + 1,
                'type' => $types[$index],
                'target_words' => $lengths[$index],
                'words' => $body === null ? 0 : str_word_count(strip_tags($body)),
                'content' => $body,
                'rejected' => $body === null,
            ];
        }

        return [
            'title' => $title,
            'tag' => $tag,
            'author' => $author['display_name'] ?? $author['username'],
            'opening' => $opening,
            'personas' => $personas,
            'replies' => $messages,
        ];
    }
}
