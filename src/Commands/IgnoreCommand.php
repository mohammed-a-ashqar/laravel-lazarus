<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Commands;

use Alashqar\Lazarus\Enums\IncidentStatus;
use Alashqar\Lazarus\Models\Incident;
use Illuminate\Console\Command;

final class IgnoreCommand extends Command
{
    protected $signature = 'lazarus:ignore {incident : The incident id or a fingerprint prefix}';

    protected $description = 'Stop healing an incident; new occurrences are still counted';

    public function handle(): int
    {
        $reference = $this->reference();
        $incident = Incident::findByReference($reference);

        if ($incident === null) {
            $this->components->error('No incident matches "'.$reference.'".');

            return self::FAILURE;
        }

        $incident->transitionTo(IncidentStatus::Ignored);
        $this->components->info("Incident #{$incident->id} ({$incident->shortClass()}) will not be healed.");

        return self::SUCCESS;
    }

    private function reference(): string
    {
        $reference = $this->argument('incident');

        return is_string($reference) ? $reference : '';
    }
}
