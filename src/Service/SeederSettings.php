<?php

namespace Pbiaut\AiSeeder\Service;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Typed access to every setting this extension owns, in one place.
 */
class SeederSettings
{
    public const PREFIX = 'pbiaut-ai-seeder.';

    /** Settings that must never leave the server. */
    public const SECRET_KEYS = ['api_key'];

    public function __construct(protected SettingsRepositoryInterface $settings)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->settings->get(self::PREFIX.$key);

        return $value === null || $value === '' ? $default : $value;
    }

    public function set(string $key, mixed $value): void
    {
        $this->settings->set(self::PREFIX.$key, $value);
    }

    public function apiKey(): string
    {
        return trim((string) $this->get('api_key', ''));
    }

    /** The forum's own title, used as context in every prompt. */
    public function forumTitle(): string
    {
        $title = $this->settings->get('forum_title');

        return is_string($title) && trim($title) !== '' ? trim($title) : 'the forum';
    }

    /**
     * Timezone the calendar is expressed in. Flarum stores everything in UTC;
     * this only decides what "an evening post" means for the audience.
     */
    public function timezone(): string
    {
        $timezone = (string) $this->get('timezone', '');

        if ($timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
            return $timezone;
        }

        return 'UTC';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    public function baseUrl(): string
    {
        return rtrim((string) $this->get('base_url', 'https://api.openai.com/v1'), '/');
    }

    public function model(): string
    {
        return (string) $this->get('model', 'gpt-4o-mini');
    }

    public function temperature(): float
    {
        return min(2.0, max(0.0, (float) $this->get('temperature', 0.9)));
    }

    public function maxTokens(): int
    {
        return min(32000, max(256, (int) $this->get('max_tokens', 4000)));
    }

    public function timeout(): int
    {
        return min(600, max(10, (int) $this->get('timeout', 120)));
    }

    /** 0 disables client-side throttling. */
    public function requestsPerMinute(): int
    {
        return max(0, (int) $this->get('requests_per_minute', 0));
    }

    public function maxRetries(): int
    {
        return min(8, max(0, (int) $this->get('max_retries', 4)));
    }

    /**
     * Non-routable by design (RFC 6761 reserves .invalid), so a stray mail
     * never reaches a real inbox.
     */
    public function emailDomain(): string
    {
        $domain = strtolower(trim((string) $this->get('email_domain', 'example.invalid')));
        $domain = ltrim($domain, '@');

        return preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain) === 1 ? $domain : 'example.invalid';
    }

    /** Price per million input tokens, in the admin's own currency. */
    public function priceInput(): float
    {
        return max(0.0, (float) $this->get('price_input', 0));
    }

    /** Price per million output tokens. */
    public function priceOutput(): float
    {
        return max(0.0, (float) $this->get('price_output', 0));
    }

    public function currency(): string
    {
        return (string) $this->get('currency', 'USD');
    }

    /** How many OpenAI calls a single queue run may make before re-queueing. */
    public function callsPerRun(): int
    {
        return min(200, max(1, (int) $this->get('calls_per_run', 12)));
    }

    /**
     * Last used generation context, so the admin form comes back pre-filled.
     *
     * @return array<string, mixed>
     */
    public function lastConfig(): array
    {
        $raw = $this->get('last_config');

        if (! is_string($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function rememberConfig(array $config): void
    {
        unset($config['api_key']);

        $this->set('last_config', json_encode($config));
    }
}
