<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Llm\Data;

final readonly class LlmResponse
{
    public function __construct(
        public string $content,
        public Usage $usage,
        public string $model,
    ) {}
}
