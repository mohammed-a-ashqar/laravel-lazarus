<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Capture;

use Alashqar\Lazarus\Context\ContextCollector;
use Alashqar\Lazarus\Enums\IncidentStatus;
use Alashqar\Lazarus\Events\IncidentCaptured;
use Alashqar\Lazarus\Jobs\HealIncident;
use Alashqar\Lazarus\Models\Incident;
use Alashqar\Lazarus\Redaction\Redactor;
use Alashqar\Lazarus\Support\Settings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Throwable;
use WeakMap;

/**
 * Entry point from the exception handler: fingerprint, redact, store, maybe start a heal.
 */
final class ExceptionCapturer
{
    private bool $capturing = false;

    /** @var WeakMap<Throwable, true> Exceptions already recorded, so a double report counts once. */
    private WeakMap $seen;

    public function __construct(
        private readonly Settings $settings,
        private readonly Fingerprinter $fingerprinter,
        private readonly ContextCollector $collector,
        private readonly Redactor $redactor,
        private readonly HealingThrottle $throttle,
        private readonly Dispatcher $events,
        private readonly Bus $bus,
        private readonly string $environment,
    ) {
        $this->seen = new WeakMap;
    }

    /**
     * Called for every reported exception. Never throws: a broken monitor must not break the app.
     */
    public function report(Throwable $exception, ?Request $request = null): ?Incident
    {
        if ($this->capturing || isset($this->seen[$exception]) || ! $this->shouldCapture($exception)) {
            return null;
        }

        $this->capturing = true;
        $this->seen[$exception] = true;

        try {
            return $this->record(ExceptionSnapshot::fromThrowable($exception), $request);
        } catch (Throwable) {
            return null;
        } finally {
            $this->capturing = false;
        }
    }

    public function shouldCapture(Throwable $exception): bool
    {
        if (! $this->settings->bool('enabled', true)) {
            return false;
        }

        if (! in_array($this->environment, $this->settings->strings('environments'), true)) {
            return false;
        }

        return $this->capturesClass($exception::class);
    }

    /**
     * Whether an exception class is worth an incident: not Lazarus itself and not ignored.
     */
    public function capturesClass(string $class): bool
    {
        // Never try to heal Lazarus itself; that way lies recursion.
        if (str_starts_with($class, 'Alashqar\\Lazarus\\')) {
            return false;
        }

        foreach ($this->settings->strings('ignore') as $ignored) {
            if (strcasecmp($class, ltrim($ignored, '\\')) === 0 || is_a($class, $ignored, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Store an occurrence. `$at` is when it happened (a log line's time); `$fromLog` marks a
     * replayed occurrence, which never starts a heal on its own.
     */
    public function record(ExceptionSnapshot $exception, ?Request $request = null, ?CarbonInterface $at = null, bool $fromLog = false): Incident
    {
        $fingerprint = $this->fingerprinter->fingerprint($exception);
        [$file, $line] = $this->fingerprinter->origin($exception);
        $now = $at !== null ? CarbonImmutable::instance($at) : CarbonImmutable::now();

        $incident = Incident::query()->createOrFirst(['fingerprint' => $fingerprint], [
            'exception_class' => $exception->class,
            'message' => mb_substr($this->redactor->redact($exception->message), 0, 2000),
            'file' => $file,
            'line' => $line,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'context' => $this->collector->collect($exception, $request)->toArray(),
        ]);

        $isNew = $incident->wasRecentlyCreated;

        if (! $isNew) {
            $incident->occurrences++;

            if ($now->greaterThan($incident->last_seen_at)) {
                $incident->last_seen_at = $now;
            }

            if ($now->lessThan($incident->first_seen_at)) {
                $incident->first_seen_at = $now;
            }

            if ($incident->status === IncidentStatus::Captured) {
                $incident->context = $this->collector->collect($exception, $request)->toArray();
            }

            $incident->save();
        }

        $this->events->dispatch(new IncidentCaptured($incident, $isNew, $fromLog));

        if (! $fromLog) {
            $this->maybeHeal($incident);
        }

        return $incident;
    }

    private function maybeHeal(Incident $incident): void
    {
        if (! $this->settings->bool('auto_heal') || ! $incident->status->canAutoHeal()) {
            return;
        }

        if (! $this->throttle->allows($incident->fingerprint)) {
            return;
        }

        $this->throttle->hit($incident->fingerprint);

        $job = new HealIncident($incident->id, $incident->fingerprint);

        if (($queue = $this->settings->nullableString('queue')) !== null) {
            $job->onQueue($queue);
        }

        $this->bus->dispatch($job);
    }
}
