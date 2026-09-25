<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Llm\Drivers;

use Alashqar\Lazarus\Llm\Contracts\LlmDriver;
use Alashqar\Lazarus\Llm\Data\LlmRequest;
use Alashqar\Lazarus\Llm\Data\LlmResponse;
use Alashqar\Lazarus\Llm\Data\Usage;
use Alashqar\Lazarus\Llm\LlmException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;

/**
 * A local model served by Ollama (POST /api/chat). Free, and the code never leaves the machine.
 */
final readonly class OllamaDriver implements LlmDriver
{
    public function __construct(
        private Factory $http,
        private DriverConfig $config,
    ) {}

    public function complete(LlmRequest $request): LlmResponse
    {
        $messages = [['role' => 'system', 'content' => $request->system]];

        foreach ($request->messages as $message) {
            $messages[] = $message->toArray();
        }

        try {
            $response = $this->http
                ->baseUrl($this->baseUrl())
                ->timeout($this->config->int('timeout', 600))
                ->acceptJson()
                ->post('/api/chat', [
                    'model' => $this->model(),
                    'messages' => $messages,
                    'format' => 'json',
                    'stream' => false,
                ]);
        } catch (ConnectionException $exception) {
            throw new LlmException(sprintf('Could not reach Ollama at %s. Is `ollama serve` running? (%s)', $this->baseUrl(), $exception->getMessage()), previous: $exception);
        }

        if ($response->failed()) {
            $error = $response->json('error');

            throw new LlmException(sprintf('Ollama returned HTTP %d: %s', $response->status(), is_string($error) ? $error : 'no details'));
        }

        $content = $response->json('message.content');
        $input = $response->json('prompt_eval_count');
        $output = $response->json('eval_count');

        return new LlmResponse(
            is_string($content) ? $content : '',
            new Usage(is_int($input) ? $input : 0, is_int($output) ? $output : 0, 0.0),
            $this->model(),
        );
    }

    public function name(): string
    {
        return 'ollama';
    }

    public function model(): string
    {
        return $this->config->string('model', 'qwen2.5-coder');
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl() !== '';
    }

    private function baseUrl(): string
    {
        return rtrim($this->config->string('base_url', 'http://localhost:11434'), '/');
    }
}
