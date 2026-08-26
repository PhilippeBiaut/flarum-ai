<?php

namespace Pbiaut\AiSeeder\Tests\unit;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Pbiaut\AiSeeder\OpenAI\Client;
use Pbiaut\AiSeeder\OpenAI\OpenAiException;
use Pbiaut\AiSeeder\Service\SeederSettings;

/**
 * The OpenAI client, exercised against a mocked transport: no key, no network,
 * no cost.
 */
class OpenAiClientTest extends TestCase
{
    /**
     * @param  array<int, Response|\Throwable>  $responses
     */
    private function client(array $responses, array $settings = []): Client
    {
        $repository = new ArraySettings(array_merge([
            SeederSettings::PREFIX.'api_key' => 'sk-test',
            SeederSettings::PREFIX.'model' => 'test-model',
            // Keep the suite fast: no sleeping between retries.
            SeederSettings::PREFIX.'max_retries' => 1,
        ], $settings));

        $client = new Client(new SeederSettings($repository));
        $client->setHttpClient(new Guzzle([
            'handler' => HandlerStack::create(new MockHandler($responses)),
            'http_errors' => false,
        ]));

        return $client;
    }

    private function completion(string $content, int $in = 10, int $out = 20): Response
    {
        return new Response(200, [], json_encode([
            'choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $out],
        ]));
    }

    /** @test */
    public function it_parses_a_json_completion_and_records_usage(): void
    {
        $client = $this->client([$this->completion('{"members":[{"username":"ada"}]}', 120, 340)]);

        $result = $client->chatJson('system', 'user');

        $this->assertSame([['username' => 'ada']], $result['members']);
        $this->assertSame(['tokens_in' => 120, 'tokens_out' => 340, 'calls' => 1], $client->usage());
    }

    /** @test */
    public function it_unwraps_a_markdown_code_fence(): void
    {
        $client = $this->client([$this->completion("```json\n{\"titles\":[\"hello\"]}\n```")]);

        $this->assertSame(['hello'], $client->chatJson('system', 'user')['titles']);
    }

    /** @test */
    public function it_retries_a_rate_limit_then_succeeds(): void
    {
        $client = $this->client([
            new Response(429, ['Retry-After' => '1'], '{"error":{"message":"slow down"}}'),
            $this->completion('{"ok":true}'),
        ]);

        $this->assertTrue($client->chatJson('system', 'user')['ok']);
    }

    /** @test */
    public function it_gives_up_on_a_rate_limit_that_never_clears(): void
    {
        $client = $this->client([
            new Response(429, [], '{"error":{"message":"quota"}}'),
            new Response(429, [], '{"error":{"message":"quota"}}'),
        ]);

        try {
            $client->chatJson('system', 'user');
            $this->fail('Expected an OpenAiException.');
        } catch (OpenAiException $e) {
            $this->assertSame(429, $e->status);
            $this->assertTrue($e->retryable, 'a rate limit stays retryable so the batch backs off instead of failing');
        }
    }

    /** @test */
    public function a_bad_key_is_not_retried(): void
    {
        // Only one response queued: a second attempt would blow up the mock.
        $client = $this->client([new Response(401, [], '{"error":{"message":"Incorrect API key"}}')]);

        try {
            $client->chatJson('system', 'user');
            $this->fail('Expected an OpenAiException.');
        } catch (OpenAiException $e) {
            $this->assertSame(401, $e->status);
            $this->assertFalse($e->retryable);
            $this->assertStringContainsString('API key', $e->getMessage());
        }
    }

    /** @test */
    public function it_drops_parameters_the_model_rejects_and_retries(): void
    {
        $client = $this->client([
            new Response(400, [], '{"error":{"message":"Unsupported parameter: max_completion_tokens"}}'),
            new Response(400, [], '{"error":{"message":"Unsupported value: temperature does not support 0.9"}}'),
            $this->completion('{"ok":true}'),
        ]);

        $this->assertTrue($client->chatJson('system', 'user')['ok']);
    }

    /** @test */
    public function invalid_json_is_reported_as_retryable(): void
    {
        $client = $this->client([$this->completion('this is not json at all')]);

        try {
            $client->chatJson('system', 'user');
            $this->fail('Expected an OpenAiException.');
        } catch (OpenAiException $e) {
            $this->assertTrue($e->retryable);
            $this->assertStringContainsString('valid JSON', $e->getMessage());
        }
    }

    /** @test */
    public function it_lists_models_sorted(): void
    {
        $client = $this->client([
            new Response(200, [], json_encode(['data' => [['id' => 'zeta'], ['id' => 'alpha']]])),
        ]);

        $this->assertSame(['alpha', 'zeta'], $client->listModels());
    }
}
