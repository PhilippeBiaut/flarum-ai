<?php

namespace Pbiaut\AiSeeder\Service;

use RuntimeException;

/**
 * Internal signal: this run should stop and be picked up again later, without
 * marking anything as failed. Raised on transient OpenAI trouble (rate limits,
 * network) once the client's own retries are exhausted.
 */
class RunInterrupted extends RuntimeException
{
}
