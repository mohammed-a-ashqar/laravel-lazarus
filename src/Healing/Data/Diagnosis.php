<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Healing\Data;

use Alashqar\Lazarus\Llm\Contracts\StructuredResponse;
use Alashqar\Lazarus\Llm\Payload;

final readonly class Diagnosis implements StructuredResponse
{
    public function __construct(
        public string $rootCause,
        public string $explanation,
        public float $confidence,
    ) {}

    public static function schema(): string
    {
        return <<<'JSON'
        {
          "root_cause": "one sentence naming the actual defect",
          "explanation": "a short paragraph for the reviewer: why it failed, why the patch is correct, any risk",
          "confidence": 0.0 to 1.0
        }
        JSON;
    }

    public static function fromLlm(array $data): static
    {
        return new self(
            trim(Payload::string($data, 'root_cause')),
            trim(Payload::string($data, 'explanation')),
            Payload::number($data, 'confidence', 0, 1),
        );
    }

    public function confidencePercent(): int
    {
        return (int) round($this->confidence * 100);
    }
}
