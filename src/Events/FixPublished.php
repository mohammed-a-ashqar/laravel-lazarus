<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Events;

use Alashqar\Lazarus\Healing\Data\HealingReport;
use Alashqar\Lazarus\Models\Incident;
use Alashqar\Lazarus\Publishing\PublishResult;

/**
 * A verified fix is ready for review, as a pull request or as a patch file.
 */
final readonly class FixPublished
{
    public function __construct(
        public Incident $incident,
        public HealingReport $report,
        public PublishResult $result,
    ) {}
}
