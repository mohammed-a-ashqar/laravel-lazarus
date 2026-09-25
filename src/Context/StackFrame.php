<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Context;

final readonly class StackFrame
{
    public function __construct(
        public string $file,
        public int $line,
        public ?string $call,
        public string $snippet,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['file'] ?? null) ? $data['file'] : '',
            is_int($data['line'] ?? null) ? $data['line'] : 0,
            is_string($data['call'] ?? null) ? $data['call'] : null,
            is_string($data['snippet'] ?? null) ? $data['snippet'] : '',
        );
    }

    /**
     * @return array{file: string, line: int, call: string|null, snippet: string}
     */
    public function toArray(): array
    {
        return ['file' => $this->file, 'line' => $this->line, 'call' => $this->call, 'snippet' => $this->snippet];
    }
}
