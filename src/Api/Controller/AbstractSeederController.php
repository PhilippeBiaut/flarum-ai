<?php

namespace Pbiaut\AiSeeder\Api\Controller;

use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Pbiaut\AiSeeder\Model\Batch;
use Pbiaut\AiSeeder\OpenAI\OpenAiException;
use Pbiaut\AiSeeder\Planner\InvalidConfigException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Shared plumbing for the seeder's endpoints: admin-only, JSON in, JSON out,
 * and errors shaped the way Flarum's frontend already knows how to display.
 */
abstract class AbstractSeederController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        try {
            return new JsonResponse($this->data($request));
        } catch (InvalidConfigException $e) {
            return $this->error(422, $e->errors);
        } catch (OpenAiException $e) {
            return $this->error($e->status >= 400 && $e->status < 600 ? $e->status : 502, [
                'openai' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    abstract protected function data(ServerRequestInterface $request): array;

    /**
     * @return array<string, mixed>
     */
    protected function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    protected function batchId(ServerRequestInterface $request): int
    {
        return (int) Arr::get($request->getQueryParams(), 'id', 0);
    }

    protected function findBatch(ServerRequestInterface $request): Batch
    {
        /** @var Batch $batch */
        $batch = Batch::findOrFail($this->batchId($request));

        return $batch;
    }

    /**
     * @param  array<string, string>  $details
     */
    protected function error(int $status, array $details): JsonResponse
    {
        $errors = [];

        foreach ($details as $field => $message) {
            $errors[] = [
                'status' => (string) $status,
                'code' => 'ai-seeder-error',
                'source' => ['pointer' => '/data/attributes/'.$field],
                'detail' => $message,
            ];
        }

        return new JsonResponse(['errors' => $errors], $status);
    }
}
