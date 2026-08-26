<?php

namespace Pbiaut\AiSeeder\OpenAI;

use RuntimeException;
use Throwable;

class OpenAiException extends RuntimeException
{
    public function __construct(
        string $message,
        public int $status = 0,
        public bool $retryable = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public static function fromStatus(int $status, string $body): self
    {
        $detail = self::extractMessage($body);

        $message = match (true) {
            $status === 401 => 'OpenAI rejected the API key (401). Check the key in the extension settings.',
            $status === 403 => 'OpenAI refused the request (403). The key may lack access to this model.',
            $status === 404 => 'OpenAI returned 404. The model name is probably wrong or unavailable to this key.',
            $status === 429 => 'OpenAI rate limit or quota reached (429).',
            $status >= 500 => "OpenAI server error ($status).",
            default => "OpenAI returned an unexpected status ($status).",
        };

        return new self(
            $detail === '' ? $message : $message.' '.$detail,
            $status,
            // 429 and 5xx are worth another go; 4xx client errors are not.
            $status === 429 || $status >= 500 || $status === 408,
        );
    }

    public static function extractMessage(string $body): string
    {
        $decoded = json_decode($body, true);

        if (is_array($decoded) && isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
            return $decoded['error']['message'];
        }

        return mb_substr(trim($body), 0, 300);
    }
}
