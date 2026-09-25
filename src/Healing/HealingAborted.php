<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Healing;

use Alashqar\Lazarus\Enums\HealingStage;
use RuntimeException;
use Throwable;

/**
 * A step could not prove what it had to prove. The heal stops and nothing is published.
 */
final class HealingAborted extends RuntimeException
{
    public function __construct(
        public readonly HealingStage $stage,
        string $reason,
        public readonly ?string $details = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($reason, 0, $previous);
    }
}
