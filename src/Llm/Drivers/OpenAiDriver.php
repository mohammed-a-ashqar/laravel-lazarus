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
 * OpenAI Chat Completions in JSON mode. Works with any compatible endpoint via base_url.
 */
final readonly class OpenAiDriver implements LlmDriver
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
                ->baseUrl(rtrim($this->config->string('base_url', 'https://api.openai.com'), '/'))
                ->timeout($this->config->int('timeout', 180))
                ->acceptJson()
                ->withToken($this->config->string('api_key'))
                ->post('/v1/chat/completions', [
                    'model' => $this->model(),
                    'messages' => $messages,
                    'response_format' => ['type' => 'json_object'],
                    'max_completion_tokens' => $this->config->int('max_tokens', 16_000),
                ]);
        } catch (ConnectionException $exception) {
            throw new LlmException('Could not reach the OpenAI API: '.$exception->getMessage(), previous: $exception);
        }

        if ($response->failed()) {
            $error = $response->json('error.message');

            throw new LlmException(sprintf('OpenAI API returned HTTP %d: %s', $response->status(), is_string($error) ? $error : 'no details'));
        }

        $refusal = $response->json('choices.0.message.refusal');

        if (is_string($refusal) && $refusal !== '') {
            throw new LlmException('The model declined the request: '.$refusal);
        }

        $content = $response->json('choices.0.message.content');
        $input = $response->json('usage.prompt_tokens');
        $output = $response->json('usage.completion_tokens');
        $model = $response->json('model');

        return new LlmResponse(
            is_string($content) ? $content : '',
            Usage::priced(is_int($input) ? $input : 0, is_int($output) ? $output : 0, $this->config->pricing()),
            is_string($model) ? $model : $this->model(),
        );
    }

    public function name(): string
    {
        return 'openai';
    }

    public function model(): string
    {
        return $this->config->string('model', 'gpt-5');
    }

    public function isConfigured(): bool
    {
        return $this->config->string('api_key') !== '';
    }
}
