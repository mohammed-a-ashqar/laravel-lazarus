<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Llm\Drivers;

use Alashqar\Lazarus\Llm\Contracts\LlmDriver;
use Alashqar\Lazarus\Llm\Data\LlmRequest;
use Alashqar\Lazarus\Llm\Data\LlmResponse;
use Alashqar\Lazarus\Llm\Data\Usage;
use Alashqar\Lazarus\Llm\LlmException;
use Closure;
use PHPUnit\Framework\Assert;

/**
 * Scripted responses for tests: each call takes the next answer from the queue.
 *
 * An answer is a raw string, an array (sent as JSON) or a closure that receives the request.
 */
final class FakeDriver implements LlmDriver
{
    /** @var list<string|array<array-key, mixed>|Closure> */
    private array $script;

    /** @var list<LlmRequest> */
    private array $requests = [];

    /**
     * @param  list<string|array<array-key, mixed>|Closure>  $script
     */
    public function __construct(array $script = [], private readonly int $tokensPerCall = 1_000)
    {
        $this->script = $script;
    }

    /**
     * @param  string|array<array-key, mixed>|Closure  ...$answers
     */
    public function push(string|array|Closure ...$answers): self
    {
        foreach ($answers as $answer) {
            $this->script[] = $answer;
        }

        return $this;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $this->requests[] = $request;
        $answer = array_shift($this->script);

        if ($answer === null) {
            throw new LlmException('FakeDriver ran out of scripted responses after '.count($this->requests).' calls.');
        }

        if ($answer instanceof Closure) {
            $answer = $answer($request);
        }

        $content = match (true) {
            is_array($answer) => (string) json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            is_string($answer) => $answer,
            default => '',
        };

        return new LlmResponse($content, new Usage($this->tokensPerCall, intdiv($this->tokensPerCall, 4), 0.001), 'fake');
    }

    /**
     * @return list<LlmRequest>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    public function remaining(): int
    {
        return count($this->script);
    }

    public function assertSent(int $count): void
    {
        Assert::assertCount($count, $this->requests, 'Unexpected number of LLM calls.');
    }

    public function assertNeverSent(string $needle): void
    {
        foreach ($this->requests as $request) {
            Assert::assertStringNotContainsString($needle, $request->system, 'A secret reached the model.');

            foreach ($request->messages as $message) {
                Assert::assertStringNotContainsString($needle, $message->content, 'A secret reached the model.');
            }
        }
    }

    public function name(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }
}
