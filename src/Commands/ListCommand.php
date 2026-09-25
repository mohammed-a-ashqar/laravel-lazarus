<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Commands;

use Alashqar\Lazarus\Enums\IncidentStatus;
use Alashqar\Lazarus\Models\Incident;
use Illuminate\Console\Command;

final class ListCommand extends Command
{
    protected $signature = 'lazarus:list
        {--status= : Only show incidents with this status}
        {--limit=20 : How many incidents to show}';

    protected $description = 'List captured incidents and where each heal stands';

    public function handle(): int
    {
        $query = Incident::query()->orderByDesc('last_seen_at')->limit(max(1, (int) $this->option('limit')));

        if (is_string($status = $this->option('status'))) {
            if (IncidentStatus::tryFrom($status) === null) {
                $this->components->error('Unknown status. Use one of: '.implode(', ', array_column(IncidentStatus::cases(), 'value')));

                return self::FAILURE;
            }

            $query->where('status', $status);
        }

        $incidents = $query->get();

        if ($incidents->isEmpty()) {
            $this->components->info('No incidents captured yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'Status', 'Exception', 'Location', 'Seen', 'Last seen', 'Outcome'],
            $incidents->map(static fn (Incident $incident): array => [
                $incident->id,
                $incident->status->label(),
                $incident->shortClass().': '.mb_strimwidth($incident->message, 0, 50, '…'),
                $incident->location(),
                $incident->occurrences,
                $incident->last_seen_at->diffForHumans(),
                $incident->pr_url ?? ($incident->report_path !== null ? basename($incident->report_path) : mb_strimwidth($incident->failure_reason ?? '', 0, 50, '…')),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
