<?php

declare(strict_types=1);

use Alashqar\Lazarus\Llm\Data\LlmRequest;
use Alashqar\Lazarus\Llm\Data\Message;
use Alashqar\Lazarus\Llm\LlmException;
use Alashqar\Lazarus\Llm\LlmManager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function conversationRequest(): LlmRequest
{
    return new LlmRequest('You are Lazarus.', [Message::user('Reproduce this.'), Message::assistant('{}'), Message::user('Again.')]);
}

beforeEach(function (): void {
    config()->set('lazarus.llm.drivers.anthropic.api_key', 'test-anthropic-key');
    config()->set('lazarus.llm.drivers.openai.api_key', 'test-openai-key');
    Sleep::fake();
});

it('calls the Anthropic Messages API and prices the usage', function (): void {
    Http::fake(['api.anthropic.com/v1/messages' => Http::response([
        'model' => 'claude-sonnet-5',
        'stop_reason' => 'end_turn',
        'content' => [['type' => 'thinking', 'thinking' => ''], ['type' => 'text', 'text' => '{"ok":true}']],
        'usage' => ['input_tokens' => 1_000_000, 'output_tokens' => 100_000],
    ])]);

    $driver = app(LlmManager::class)->driver('anthropic');
    $response = $driver->complete(conversationRequest());

    expect($driver->model())->toBe('claude-sonnet-5')
        ->and($response->content)->toBe('{"ok":true}')
        ->and($response->usage->inputTokens)->toBe(1_000_000)
        ->and($response->usage->cost)->toBe(3.0);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('x-api-key', 'test-anthropic-key')
        && $request->hasHeader('anthropic-version', '2023-06-01')
        && $request['model'] === 'claude-sonnet-5'
        && $request['system'] === 'You are Lazarus.'
        && count($request['messages']) === 3
        && $request['messages'][1] === ['role' => 'assistant', 'content' => '{}']);
});

it('uses the configured Anthropic model', function (): void {
    config()->set('lazarus.llm.drivers.anthropic.model', 'claude-opus-5');

    expect(app(LlmManager::class)->driver('anthropic')->model())->toBe('claude-opus-5');
});

it('reports Anthropic API errors and refusals', function (array $body, int $status, string $message): void {
    Http::fake(['*' => Http::response($body, $status)]);

    expect(fn () => app(LlmManager::class)->driver('anthropic')->complete(conversationRequest()))->toThrow(LlmException::class, $message);
})->with([
    'overloaded' => [['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529, 'HTTP 529: Overloaded'],
    'refusal' => [['stop_reason' => 'refusal', 'content' => []], 200, 'declined'],
]);

it('calls OpenAI Chat Completions in JSON mode', function (): void {
    Http::fake(['api.openai.com/v1/chat/completions' => Http::response([
        'model' => 'gpt-5',
        'choices' => [['message' => ['role' => 'assistant', 'content' => '{"ok":true}']]],
        'usage' => ['prompt_tokens' => 1_000_000, 'completion_tokens' => 100_000],
    ])]);

    $response = app(LlmManager::class)->driver('openai')->complete(conversationRequest());

    expect($response->content)->toBe('{"ok":true}')
        ->and($response->usage->cost)->toBe(2.25);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-openai-key')
        && $request['response_format'] === ['type' => 'json_object']
        && $request['messages'][0] === ['role' => 'system', 'content' => 'You are Lazarus.']
        && count($request['messages']) === 4);
});

it('knows whether a hosted driver has its API key', function (): void {
    config()->set('lazarus.llm.drivers.openai.api_key', null);

    expect(app(LlmManager::class)->driver('anthropic')->isConfigured())->toBeTrue()
        ->and(app(LlmManager::class)->driver('openai')->isConfigured())->toBeFalse();
});

it('waits out a rate limit for as long as the API asks, then succeeds', function (): void {
    Http::fake(['*' => Http::sequence()
        ->push(['error' => ['message' => 'Rate limit reached for model on tokens per minute (TPM). Please try again in 14.43s.']], 429)
        ->push(['model' => 'gpt-5', 'choices' => [['message' => ['role' => 'assistant', 'content' => '{"ok":true}']]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 1]]),
    ]);

    $response = app(LlmManager::class)->driver('openai')->complete(conversationRequest());

    expect($response->content)->toBe('{"ok":true}');
    Http::assertSentCount(2);
    Sleep::assertSlept(fn ($duration): bool => (int) $duration->totalMilliseconds === 14_430);
});

it('honours Retry-After and gives up after four attempts', function (): void {
    Http::fake(['*' => Http::response(['error' => ['message' => 'Too many requests']], 429, ['Retry-After' => '3'])]);

    expect(fn () => app(LlmManager::class)->driver('openai')->complete(conversationRequest()))
        ->toThrow(LlmException::class, 'HTTP 429: Too many requests');
    Http::assertSentCount(4);
    Sleep::assertSleptTimes(3);
});

it('does not retry errors that will not go away', function (): void {
    Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid API key']], 401)]);

    expect(fn () => app(LlmManager::class)->driver('openai')->complete(conversationRequest()))
        ->toThrow(LlmException::class, 'HTTP 401');
    Http::assertSentCount(1);
});
