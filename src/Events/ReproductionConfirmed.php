<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Events;

use Alashqar\Lazarus\Healing\Data\ReproductionTest;
use Alashqar\Lazarus\Models\Incident;
use Alashqar\Lazarus\Sandbox\TestRun;

/**
 * The model wrote a test that fails with the original exception (the red half of the proof).
 */
final readonly class ReproductionConfirmed
{
    public function __construct(
        public Incident $incident,
        public ReproductionTest $test,
        public TestRun $run,
        public int $attempt,
    ) {}
}
