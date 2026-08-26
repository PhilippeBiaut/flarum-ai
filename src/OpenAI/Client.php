<?php

namespace Pbiaut\AiSeeder\OpenAI;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Pbiaut\AiSeeder\Service\SeederSettings;
use Psr\Http\Message\ResponseInterface;

/**
 * Minimal OpenAI client, built on /v1/chat/completions rather than /v1/responses
 * so the same code also works against Azure OpenAI, OpenRouter, LiteLLM or a
 * local server through the "base URL" setting.
 *
 * Two things it does that a naive wrapper does not:
 *  - it retries 429 / 5xx with exponential backoff and honours Retry-After;
 *  - it discovers, once per process, which optional parameters the chosen model
 *    actually accepts (temperature, max_tokens vs max_completion_tokens) instead
 *    of hardcoding assumptions that break on every model generation.
 */
class Client
{
    private ?ClientInterface $http = null;

    /** @var array<string, true> parameters this endpoint/model rejected */
    private array $unsupported = [];

    private float $lastCallAt = 0.0;

    private int $tokensIn = 0;

    private int $tokensOut = 0;

    private int $calls = 0;

    public function __construct(protected SeederSettings $settings)
    {
    }

    /** Injection point for tests (Guzzle MockHandler). */
    public function setHttpClient(ClientInterface $http): void
    {
        $this->http = $http;
    }

    public function isConfigured(): bool
    {
        return $this->settings->isConfigured();
    }

    /**
     * @return array<int, string>  model ids, alphabetically
     *
     * @throws OpenAiException
     */
    public function listModels(): array
    {
        $response = $this->send('GET', '/models');
        $data = $this->decodeBody($response);

        $models = [];

        foreach ($data['data'] ?? [] as $model) {
            if (isset($model['id']) && is_string($model['id'])) {
                $models[] = $model['id'];
            }
        }

        sort($models);

        return $models;
    }

    /**
     * Runs a chat completion that must answer with a JSON object.
     *
     * @return array<string, mixed>
     *
     * @throws OpenAiException
     */
    public function chatJson(string $system, string $user, ?string $model = null, ?int $maxTokens = null): array
    {
        $model ??= $this->settings->model();

        if (trim($model) === '') {
            throw new OpenAiException('No OpenAI model selected in the extension settings.');
        }

        $response = $this->sendChat([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'response_format' => ['type' => 'json_object'],
        ], $maxTokens ?? $this->settings->maxTokens());

        $body = $this->decodeBody($response);

        $this->calls++;
        $this->tokensIn += (int) ($body['usage']['prompt_tokens'] ?? 0);
        $this->tokensOut += (int) ($body['usage']['completion_tokens'] ?? 0);

        $choice = $body['choices'][0] ?? [];

        if (isset($choice['message']['refusal']) && is_string($choice['message']['refusal']) && $choice['message']['refusal'] !== '') {
            throw new OpenAiException('The model refused the request: '.$choice['message']['refusal']);
        }

        $content = $choice['message']['content'] ?? null;

        if (! is_string($content) || trim($content) === '') {
            $reason = $choice['finish_reason'] ?? 'unknown';

            throw new OpenAiException(
                "The model returned no content (finish_reason: $reason). Raising the max tokens setting usually fixes this.",
                0,
                true
            );
        }

        $decoded = json_decode($this->stripCodeFence($content), true);

        if (! is_array($decoded)) {
            throw new OpenAiException(
                'The model did not return valid JSON: '.mb_substr($content, 0, 200),
                0,
                true
            );
        }

        return $decoded;
    }

    /**
     * @return array{tokens_in: int, tokens_out: int, calls: int}
     */
    public function usage(): array
    {
        return ['tokens_in' => $this->tokensIn, 'tokens_out' => $this->tokensOut, 'calls' => $this->calls];
    }

    public function resetUsage(): void
    {
        $this->tokensIn = 0;
        $this->tokensOut = 0;
        $this->calls = 0;
    }

    /**
     * Sends a chat request, dropping optional parameters the model rejects and
     * retrying instead of failing the whole batch over a payload detail.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function sendChat(array $payload, int $maxTokens): ResponseInterface
    {
        for ($adaptation = 0; $adaptation <= 3; $adaptation++) {
            $body = $payload;

            if (! isset($this->unsupported['temperature'])) {
                $body['temperature'] = $this->settings->temperature();
            }

            $tokenParam = isset($this->unsupported['max_completion_tokens']) ? 'max_tokens' : 'max_completion_tokens';

            if (! isset($this->unsupported[$tokenParam])) {
                $body[$tokenParam] = $maxTokens;
            }

            if (! isset($this->unsupported['response_format'])) {
                $body['response_format'] = ['type' => 'json_object'];
            } else {
                unset($body['response_format']);
            }

            try {
                return $this->send('POST', '/chat/completions', $body);
            } catch (OpenAiException $e) {
                $offending = $this->offendingParameter($e->getMessage());

                if ($e->status !== 400 || $offending === null || isset($this->unsupported[$offending])) {
                    throw $e;
                }

                // Remember it and try again without that parameter.
                $this->unsupported[$offending] = true;
            }
        }

        throw new OpenAiException('The model rejected every supported parameter combination.');
    }

    /**
     * @param  array<string, mixed>|null  $json
     *
     * @throws OpenAiException
     */
    protected function send(string $method, string $path, ?array $json = null): ResponseInterface
    {
        $attempts = $this->settings->maxRetries();
        $lastError = null;

        for ($attempt = 0; $attempt <= $attempts; $attempt++) {
            $this->throttle();

            try {
                $response = $this->client()->request($method, ltrim($path, '/'), array_filter([
                    'json' => $json,
                ], fn ($value) => $value !== null));

                $status = $response->getStatusCode();

                if ($status >= 200 && $status < 300) {
                    return $response;
                }

                $lastError = OpenAiException::fromStatus($status, (string) $response->getBody());

                if (! $lastError->retryable || $attempt === $attempts) {
                    throw $lastError;
                }

                $this->backoff($attempt, $response);
            } catch (GuzzleException $e) {
                $lastError = new OpenAiException(
                    'Could not reach the OpenAI endpoint: '.$e->getMessage(),
                    0,
                    true,
                    $e
                );

                if ($attempt === $attempts) {
                    throw $lastError;
                }

                $this->backoff($attempt, null);
            }
        }

        throw $lastError ?? new OpenAiException('The OpenAI request failed for an unknown reason.');
    }

    protected function client(): ClientInterface
    {
        if ($this->http === null) {
            $this->http = new Guzzle([
                'base_uri' => $this->settings->baseUrl().'/',
                'timeout' => $this->settings->timeout(),
                'http_errors' => false,
                'headers' => [
                    'Authorization' => 'Bearer '.$this->settings->apiKey(),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
            ]);
        }

        return $this->http;
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeBody(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);

        if (! is_array($decoded)) {
            throw new OpenAiException('The OpenAI endpoint returned a body that is not JSON.', 0, true);
        }

        return $decoded;
    }

    /** Waits between calls when the admin set a requests-per-minute ceiling. */
    protected function throttle(): void
    {
        $rpm = $this->settings->requestsPerMinute();

        if ($rpm <= 0) {
            return;
        }

        $interval = 60 / $rpm;
        $elapsed = microtime(true) - $this->lastCallAt;

        if ($this->lastCallAt > 0 && $elapsed < $interval) {
            usleep((int) round(($interval - $elapsed) * 1_000_000));
        }

        $this->lastCallAt = microtime(true);
    }

    protected function backoff(int $attempt, ?ResponseInterface $response): void
    {
        $retryAfter = $response?->getHeaderLine('Retry-After');

        if (is_numeric($retryAfter)) {
            $seconds = min(60.0, max(1.0, (float) $retryAfter));
        } else {
            // 1s, 2s, 4s, 8s ... plus jitter to avoid a thundering herd.
            $seconds = min(30.0, 2 ** $attempt) + (random_int(0, 500) / 1000);
        }

        usleep((int) round($seconds * 1_000_000));
    }

    /** Which optional parameter did a 400 complain about? */
    protected function offendingParameter(string $message): ?string
    {
        $message = strtolower($message);

        foreach (['max_completion_tokens', 'max_tokens', 'temperature', 'response_format'] as $parameter) {
            if (str_contains($message, $parameter)) {
                return $parameter;
            }
        }

        return null;
    }

    /** Some models wrap JSON in a markdown fence despite the response format. */
    protected function stripCodeFence(string $content): string
    {
        $content = trim($content);

        if (! str_starts_with($content, '```')) {
            return $content;
        }

        $content = preg_replace('/^```[a-z]*\s*/i', '', $content) ?? $content;

        return trim(preg_replace('/```\s*$/', '', $content) ?? $content);
    }
}
