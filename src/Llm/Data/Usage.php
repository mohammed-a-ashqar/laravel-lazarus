<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Llm\Data;

final readonly class Usage
{
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public float $cost = 0.0,
    ) {}

    /**
     * @param  array{input?: mixed, output?: mixed}  $pricing  USD per million tokens.
     */
    public static function priced(int $inputTokens, int $outputTokens, array $pricing): self
    {
        $input = is_numeric($pricing['input'] ?? null) ? (float) $pricing['input'] : 0.0;
        $output = is_numeric($pricing['output'] ?? null) ? (float) $pricing['output'] : 0.0;

        return new self($inputTokens, $outputTokens, ($inputTokens * $input + $outputTokens * $output) / 1_000_000);
    }

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    public function plus(self $other): self
    {
        return new self(
            $this->inputTokens + $other->inputTokens,
            $this->outputTokens + $other->outputTokens,
            $this->cost + $other->cost,
        );
    }
}
