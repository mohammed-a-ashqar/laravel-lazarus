<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Healing;

use Alashqar\Lazarus\Sandbox\TestRun;
use RuntimeException;

/**
 * The patch applied cleanly but did not survive verification. The model may try again.
 */
final class PatchRejected extends RuntimeException
{
    public function __construct(string $reason, public readonly TestRun $run)
    {
        parent::__construct($reason);
    }
}
