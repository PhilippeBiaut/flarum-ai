<?php

namespace Pbiaut\AiSeeder\Planner;

use InvalidArgumentException;

class InvalidConfigException extends InvalidArgumentException
{
    /**
     * @param  array<string, string>  $errors  field => message
     */
    public function __construct(public array $errors)
    {
        parent::__construct('Invalid seeder configuration: '.implode(' | ', array_map(
            fn (string $field, string $message) => $field.': '.$message,
            array_keys($errors),
            array_values($errors)
        )));
    }
}
