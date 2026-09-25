<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Commands;

use Alashqar\Lazarus\Enums\IncidentStatus;
use Alashqar\Lazarus\Events\HealingStepCompleted;
use Alashqar\Lazarus\Events\HealingStepStarted;
use Alashqar\Lazarus\Healing\Healer;
use Alashqar\Lazarus\Jobs\HealIncident;
use Alashqar\Lazarus\Llm\Contracts\LlmDriver;
use Alashqar\Lazarus\Models\Incident;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Events\Dispatcher;

final class HealCommand extends Command
{
    protected $signature = 'lazarus:heal
        {incident : The incident id or a fingerprint prefix}
        {--queue : Dispatch the heal to the queue instead of running it here}';

    protected $description = 'Reproduce, fix and verify an incident, then publish the fix for review';

    public function handle(Healer $healer, Dispatcher $events, Bus $bus, LlmDriver $driver): int
    {
        $reference = $this->reference();
        $incident = Incident::findByReference($reference);

        if ($incident === null) {
            $this->components->error('No incident matches "'.$reference.'". Run lazarus:list to see them.');

            return self::FAILURE;
        }

        if ($incident->status === IncidentStatus::Ignored) {
            $this->components->warn("Incident #{$incident->id} is ignored.");

            return self::FAILURE;
        }

        if ($this->option('queue')) {
            $bus->dispatch(new HealIncident($incident->id, $incident->fingerprint));
            $this->components->info("Healing of incident #{$incident->id} was queued.");

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line("  <options=bold>Lazarus</> is healing incident <options=bold>#{$incident->id}</> with <fg=cyan>{$driver->name()}:{$driver->model()}</>");
        $this->line("  <fg=red;options=bold>{$incident->shortClass()}</>: {$incident->message}");
        $this->line("  <fg=gray>{$incident->location()} · seen {$incident->occurrences} time(s)</>");
        $this->newLine();

        $events->listen(HealingStepStarted::class, function (HealingStepStarted $event): void {
            $this->line("  <fg=cyan>●</> {$event->description}...");
        });

        $events->listen(HealingStepCompleted::class, function (HealingStepCompleted $event): void {
            $mark = $event->succeeded ? '<fg=green;options=bold>✔</>' : '<fg=red;options=bold>✘</>';
            $time = $event->seconds > 0 ? sprintf(' <fg=gray>%.1fs</>', $event->seconds) : '';

            $this->line("  {$mark} {$event->summary}{$time}");

            if ($event->details !== null && trim($event->details) !== '') {
                foreach (explode("\n", $event->details) as $line) {
                    $this->line('    <fg=gray>│ '.$line.'</>');
                }
            }
        });

        $report = $healer->heal($incident);
        $incident->refresh();

        $this->newLine();

        if ($report === null) {
            $this->components->error('Nothing was published. '.($incident->failure_reason ?? ''));

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Diagnosis', $report->diagnosis->rootCause);
        $this->components->twoColumnDetail('Confidence', $report->diagnosis->confidencePercent().'%');
        $this->components->twoColumnDetail('Red → green', "{$report->test->path}");
        $this->components->twoColumnDetail('Cost', sprintf('%s tokens · $%.4f', number_format($report->usage->totalTokens()), $report->usage->cost));
        $this->components->twoColumnDetail('Review', $incident->pr_url ?? $incident->report_path ?? '-');
        $this->newLine();

        return self::SUCCESS;
    }

    private function reference(): string
    {
        $reference = $this->argument('incident');

        return is_string($reference) ? $reference : '';
    }
}
