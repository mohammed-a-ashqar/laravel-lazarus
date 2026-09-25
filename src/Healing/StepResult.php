<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Healing;

final readonly class StepResult
{
    public function __construct(
        public string $summary,
        public ?string $details = null,
    ) {}
}
