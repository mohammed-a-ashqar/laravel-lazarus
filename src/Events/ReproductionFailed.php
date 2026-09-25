<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Events;

use Alashqar\Lazarus\Models\Incident;

/**
 * No attempt produced a test that fails for the right reason, so nothing will be patched.
 */
final readonly class ReproductionFailed
{
    public function __construct(
        public Incident $incident,
        public string $reason,
        public int $attempts,
    ) {}
}
