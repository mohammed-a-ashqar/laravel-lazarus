<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Llm\Drivers;

use Alashqar\Lazarus\Llm\Contracts\LlmDriver;
use Alashqar\Lazarus\Llm\Data\LlmRequest;
use Alashqar\Lazarus\Llm\Data\LlmResponse;
use Alashqar\Lazarus\Llm\Data\Message;
use Alashqar\Lazarus\Llm\Data\Usage;
use Alashqar\Lazarus\Llm\LlmException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;

/**
 * Anthropic Messages API (POST /v1/messages) through Laravel's HTTP client.
 */
final readonly class AnthropicDriver implements LlmDriver
{
    public function __construct(
        private Factory $http,
        private DriverConfig $config,
    ) {}

    public function complete(LlmRequest $request): LlmResponse
    {
        try {
            $response = RateLimitRetry::apply($this->http)
                ->baseUrl(rtrim($this->config->string('base_url', 'https://api.anthropic.com'), '/'))
                ->timeout($this->config->int('timeout', 180))
                ->acceptJson()
                ->withHeaders([
                    'x-api-key' => $this->config->string('api_key'),
                    'anthropic-version' => '2023-06-01',
                ])
                ->post('/v1/messages', [
                    'model' => $this->model(),
                    'max_tokens' => $this->config->int('max_tokens', 16_000),
                    'system' => $request->system,
                    'messages' => array_map(static fn (Message $message): array => $message->toArray(), $request->messages),
                ]);
        } catch (ConnectionException $exception) {
            throw new LlmException('Could not reach the Anthropic API: '.$exception->getMessage(), previous: $exception);
        }

        if ($response->failed()) {
            $error = $response->json('error.message');

            throw new LlmException(sprintf('Anthropic API returned HTTP %d: %s', $response->status(), is_string($error) ? $error : 'no details'));
        }

        if ($response->json('stop_reason') === 'refusal') {
            throw new LlmException('The model declined the request.');
        }

        // Thinking blocks may precede the answer; only text blocks carry it.
        $text = '';
        $content = $response->json('content');

        foreach (is_array($content) ? $content : [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        $input = $response->json('usage.input_tokens');
        $output = $response->json('usage.output_tokens');
        $model = $response->json('model');

        return new LlmResponse(
            $text,
            Usage::priced(is_int($input) ? $input : 0, is_int($output) ? $output : 0, $this->config->pricing()),
            is_string($model) ? $model : $this->model(),
        );
    }

    public function name(): string
    {
        return 'anthropic';
    }

    public function model(): string
    {
        return $this->config->string('model', 'claude-sonnet-5');
    }

    public function isConfigured(): bool
    {
        return $this->config->string('api_key') !== '';
    }
}
