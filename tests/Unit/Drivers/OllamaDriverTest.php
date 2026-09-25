<?php

declare(strict_types=1);

use Alashqar\Lazarus\Llm\Data\LlmRequest;
use Alashqar\Lazarus\Llm\Data\Message;
use Alashqar\Lazarus\Llm\Drivers\OllamaDriver;
use Alashqar\Lazarus\Llm\LlmException;
use Alashqar\Lazarus\Llm\LlmManager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function ollama(): OllamaDriver
{
    config()->set('lazarus.llm.drivers.ollama', ['base_url' => 'http://ollama.test:11434/', 'model' => 'qwen2.5-coder']);

    $driver = app(LlmManager::class)->driver('ollama');
    assert($driver instanceof OllamaDriver);

    return $driver;
}

it('calls /api/chat in JSON mode and reports tokens at zero cost', function (): void {
    Http::fake(['ollama.test:11434/api/chat' => Http::response([
        'model' => 'qwen2.5-coder',
        'message' => ['role' => 'assistant', 'content' => '{"ok":true}'],
        'prompt_eval_count' => 812,
        'eval_count' => 96,
        'done' => true,
    ])]);

    $response = ollama()->complete(new LlmRequest('be terse', [Message::user('hello')]));

    expect($response->content)->toBe('{"ok":true}')
        ->and($response->usage->inputTokens)->toBe(812)
        ->and($response->usage->outputTokens)->toBe(96)
        ->and($response->usage->cost)->toBe(0.0);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://ollama.test:11434/api/chat'
        && $request['model'] === 'qwen2.5-coder'
        && $request['format'] === 'json'
        && $request['stream'] === false
        && $request['messages'][0] === ['role' => 'system', 'content' => 'be terse']
        && $request['messages'][1] === ['role' => 'user', 'content' => 'hello']
        && ! $request->hasHeader('Authorization'));
});

it('explains how to start Ollama when it is not running', function (): void {
    Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('Connection refused'));

    ollama()->complete(new LlmRequest('s', [Message::user('u')]));
})->throws(LlmException::class, 'Is `ollama serve` running?');

it('surfaces Ollama errors such as a missing model', function (): void {
    Http::fake(['*' => Http::response(['error' => 'model "qwen2.5-coder" not found, try pulling it first'], 404)]);

    ollama()->complete(new LlmRequest('s', [Message::user('u')]));
})->throws(LlmException::class, 'not found, try pulling it first');
