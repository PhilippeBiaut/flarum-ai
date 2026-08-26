<?php

namespace Pbiaut\AiSeeder\Generator;

use Pbiaut\AiSeeder\OpenAI\Client;
use Pbiaut\AiSeeder\OpenAI\OpenAiException;
use Pbiaut\AiSeeder\Planner\ReplyLength;
use Pbiaut\AiSeeder\Planner\ReplyType;

/**
 * Generates every reply of a thread in a single call.
 *
 * The whole thread is laid out as numbered messages - [1] is the opening post,
 * [2] onwards are the replies - and each reply to write is told which message
 * it is answering. Without that the model produces a column of parallel answers
 * to the opening post; with it, people answer each other.
 *
 * Nothing about the forum is sent here: the thread is the entire subject.
 */
class ReplyBundleGenerator
{
    /** Beyond this, a thread is generated in several successive calls. */
    public const MAX_PER_CALL = 12;

    public function __construct(
        protected Client $client,
        protected PromptBuilder $prompts,
        protected ReplyQuality $quality,
    ) {
    }

    /**
     * @param  array<string, mixed>  $opPersona
     * @param  array<int, array<string, mixed>>  $personas  one per reply, in order
     * @param  array<int, array{author: string, content: string}>  $alreadyWritten  earlier replies of this thread
     * @param  array<int, int>  $lengths  target word count per reply
     * @param  array<int, int>  $targets  message index each reply answers (0 = opening post)
     * @param  array<int, string>  $types  the intent of each reply
     * @return array<int, string|null>  one body per requested reply; null where
     *                                  the model gave nothing usable, so the
     *                                  caller can retry that slot instead of
     *                                  putting a duplicate on the forum
     *
     * @throws OpenAiException
     */
    public function generate(
        string $title,
        string $openingPost,
        array $opPersona,
        array $personas,
        GenerationContext $context,
        array $alreadyWritten = [],
        ?string $model = null,
        array $lengths = [],
        array $targets = [],
        array $types = [],
    ): array {
        $count = count($personas);

        if ($count === 0) {
            return [];
        }

        $system = $this->prompts->system(
            $context,
            'You write the replies a forum thread actually gets: people who read it and help.',
            PromptBuilder::NONE
        );

        $opAuthor = $opPersona['display_name'] ?? $opPersona['username'] ?? 'the author';

        // [1] is the opening post; replies continue from [2].
        $thread = ['[1] '.$opAuthor.' (opening post):', $openingPost];
        $number = 2;

        foreach ($alreadyWritten as $message) {
            $thread[] = '';
            $thread[] = '['.$number.'] '.$message['author'].':';
            $thread[] = $message['content'];
            $number++;
        }

        $written = $number - 1;
        $instructions = [];

        foreach (array_values($personas) as $index => $persona) {
            $target = $targets[$index] ?? 0;

            // Targets are thread indexes (0 = opening post); messages are
            // numbered from 1. Anything not yet written falls back to the
            // opening post rather than pointing at nothing.
            $targetNumber = $target <= 0 || $target > $written ? 1 : $target + 1;

            $instructions[] = '['.($written + $index + 1).'] by '
                .($persona['display_name'] ?? $persona['username'] ?? 'a member')
                .' - answering message ['.$targetNumber.'], '
                .ReplyLength::instruction((int) ($lengths[$index] ?? 90))."\n"
                .'What this reply does: '.ReplyType::instruction($types[$index] ?? ReplyType::ANSWER)."\n"
                .$this->prompts->describePersona($persona, 'Member');
        }

        $user = implode("\n", [
            'Thread title: '.$title,
            '',
            'The thread so far:',
            '"""',
            implode("\n", $thread),
            '"""',
            '',
            "Write the next $count replies, in order:",
            '',
            implode("\n\n", $instructions),
            '',
            'Answering a specific message means it:',
            '- Address what that message actually says, not the thread in general.',
            '- When it is not the opening post, make the target unmistakable: quote the line you are',
            '  answering with "> ", or name the member you are replying to. Do both only when it helps.',
            '- You may agree and add something, disagree and say why, correct a mistake, or ask that',
            '  person to clarify. What you must not do is ignore them and answer the opening post again.',
            '',
            'Every reply must say something the others do not. Two replies making the same point',
            'in different words is the one thing that gives a generated thread away.',
            '',
            'What a reply is for:',
            '- Answer the actual question. If it asks how, say how. If it asks which, choose and say why.',
            '- Give real substance: the step, the setting, the number, the caveat, the thing that bit you.',
            '- No filler. No "great question", no "hope this helps", no restating the problem back,',
            '  no summarising what someone else just said before adding your own point.',
            '- A reply with nothing to add is short, not padded to look substantial.',
            '- Never mention dates, delays, or how much time has passed.',
            '',
            'Respect each reply\'s target length. Short means short: one or two sentences, no preamble.',
            '',
            'Answer as {"replies": [{"content": "..."}, ...]} with exactly '.$count.' entries, in order.',
        ]);

        $response = $this->client->chatJson($system, $user, $model);
        $raw = $response['replies'] ?? $response['posts'] ?? [];

        if (! is_array($raw) || $raw === []) {
            throw new OpenAiException('The model returned no replies.', 0, true);
        }

        $replies = [];

        foreach ($raw as $entry) {
            $content = is_array($entry) ? ($entry['content'] ?? $entry['body'] ?? '') : $entry;

            if (is_string($content) && trim($content) !== '') {
                $replies[] = trim($content);
            }
        }

        if ($replies === []) {
            throw new OpenAiException('The model returned no usable replies.', 0, true);
        }

        // Quality gate. A rejected slot comes back as null and is retried on a
        // later pass; it is never filled with a copy of a sibling, which is
        // exactly how duplicate replies used to reach the forum.
        $kept = [];
        $accepted = array_map(fn (array $message) => $message['content'], $alreadyWritten);

        for ($index = 0; $index < $count; $index++) {
            $candidate = $replies[$index] ?? null;

            if ($candidate === null) {
                $kept[] = null;
                continue;
            }

            if ($this->quality->reject($candidate, $accepted) !== null) {
                $kept[] = null;
                continue;
            }

            $accepted[] = $candidate;
            $kept[] = $candidate;
        }

        return $kept;
    }
}
