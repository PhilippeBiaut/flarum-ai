<?php

namespace Pbiaut\AiSeeder\Tests\unit;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * In-memory settings, so unit tests never need a database.
 */
class ArraySettings implements SettingsRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(private array $values = [])
    {
    }

    public function all(): array
    {
        return $this->values;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function delete(string $keyLike): void
    {
        $pattern = '/^'.str_replace('%', '.*', preg_quote($keyLike, '/')).'$/';

        foreach (array_keys($this->values) as $key) {
            if (preg_match($pattern, $key) === 1) {
                unset($this->values[$key]);
            }
        }
    }
}
