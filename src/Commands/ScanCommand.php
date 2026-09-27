<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Commands;

use Alashqar\Lazarus\Capture\ExceptionCapturer;
use Alashqar\Lazarus\Capture\Fingerprinter;
use Alashqar\Lazarus\Capture\LogParser;
use Alashqar\Lazarus\Models\Incident;
use Alashqar\Lazarus\Sandbox\PathGuard;
use Alashqar\Lazarus\Support\Project;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

final class ScanCommand extends Command
{
    protected $signature = 'lazarus:scan
        {paths?* : Log files or directories (default: storage/logs)}
        {--since= : Only errors newer than this, e.g. "7d", "24h" or "2026-09-01"}
        {--dry-run : Show what would be captured without storing anything}';

    protected $description = 'Turn errors already in your Laravel logs into incidents, ready to heal';

    public function handle(ExceptionCapturer $capturer, Fingerprinter $fingerprinter, Project $project, PathGuard $guard): int
    {
        $since = $this->since();

        if ($since === false) {
            $this->components->error('Could not understand --since. Use "7d", "24h" or a date such as "2026-09-01".');

            return self::FAILURE;
        }

        $files = $this->files();

        if ($files === []) {
            $this->components->error('No log files found. Pass a path, for example: php artisan lazarus:scan storage/logs/laravel.log');

            return self::FAILURE;
        }

        $parser = new LogParser($project->root);
        $dryRun = (bool) $this->option('dry-run');

        /** @var array<string, array{incident: Incident|null, class: string, location: string, count: int, new: bool}> $found */
        $found = [];
        $read = $skipped = $unfixable = $alreadyCounted = 0;

        foreach ($files as $file) {
            foreach ($parser->parse($file, $since) as ['at' => $at, 'snapshot' => $snapshot]) {
                $read++;
                [$origin, $line] = $fingerprinter->origin($snapshot);

                // Errors raised entirely inside vendor code (a wrong artisan option, a database
                // that is down) have no line in your app that a patch could change.
                if ($origin === null || ! $capturer->capturesClass($snapshot->class)) {
                    $skipped++;

                    continue;
                }

                // Migrations, config and the like are off limits to patches, so a heal could not help.
                if (! $guard->allows($origin)) {
                    $unfixable++;

                    continue;
                }

                $fingerprint = $fingerprinter->fingerprint($snapshot);
                $existing = $found[$fingerprint]['incident'] ?? Incident::query()->where('fingerprint', $fingerprint)->first();

                // Re-scanning must not count the same log line twice, and errors Lazarus already
                // caught live are in the log too.
                if ($existing !== null && ! $at->greaterThan($existing->last_seen_at)) {
                    $alreadyCounted++;
                    $found[$fingerprint] ??= ['incident' => $existing, 'class' => $snapshot->class, 'location' => "{$origin}:{$line}", 'count' => 0, 'new' => false];

                    continue;
                }

                $incident = $dryRun ? $existing : $capturer->record($snapshot, null, $at, fromLog: true);

                $found[$fingerprint] ??= ['incident' => null, 'class' => $snapshot->class, 'location' => "{$origin}:{$line}", 'count' => 0, 'new' => $existing === null];
                $found[$fingerprint]['incident'] = $incident;
                $found[$fingerprint]['count']++;
            }
        }

        $this->report($files, $found, $read, $skipped, $unfixable, $alreadyCounted, $dryRun);

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $files
     * @param  array<string, array{incident: Incident|null, class: string, location: string, count: int, new: bool}>  $found
     */
    private function report(array $files, array $found, int $read, int $skipped, int $unfixable, int $alreadyCounted, bool $dryRun): void
    {
        // Incidents whose every line was already counted are covered by the "Already counted" line.
        $found = array_filter($found, static fn (array $item): bool => $item['count'] > 0);
        $new = count(array_filter($found, static fn (array $item): bool => $item['new']));

        $this->components->info(sprintf(
            '%s %d error(s) in %d log file(s): %d incident(s) updated, %d new.%s',
            $dryRun ? 'Dry run: found' : 'Scanned',
            $read,
            count($files),
            count($found),
            $new,
            $dryRun ? ' Nothing was stored.' : '',
        ));

        if ($skipped > 0) {
            $this->components->twoColumnDetail('Skipped (raised inside vendor code, or ignored)', (string) $skipped);
        }

        if ($unfixable > 0) {
            $this->components->twoColumnDetail('Skipped (in files Lazarus may not change, such as migrations)', (string) $unfixable);
        }

        if ($alreadyCounted > 0) {
            $this->components->twoColumnDetail('Already counted (earlier scan or live capture)', (string) $alreadyCounted);
        }

        if ($found === []) {
            return;
        }

        $rows = [];

        foreach ($found as $item) {
            $class = $item['class'];
            $rows[] = [
                $item['incident'] !== null ? (string) $item['incident']->id : '-',
                $item['new'] ? 'new' : 'known',
                substr($class, (int) strrpos('\\'.$class, '\\')),
                $item['location'],
                (string) $item['count'],
                $item['incident']?->status->value ?? 'captured',
            ];
        }

        $this->table(['#', '', 'Exception', 'Location', 'In logs', 'Status'], $rows);

        if (! $dryRun) {
            $this->line('  Heal one with <comment>php artisan lazarus:heal {#}</comment>.');
        }
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $paths = $this->argument('paths');
        $paths = is_array($paths) && $paths !== [] ? $paths : [$this->laravel->storagePath('logs')];
        $files = [];

        foreach ($paths as $path) {
            if (! is_string($path)) {
                continue;
            }

            if (is_dir($path)) {
                $matches = glob(rtrim($path, '\\/').'/*.log') ?: [];
                sort($matches);
                array_push($files, ...$matches);
            } elseif (is_file($path)) {
                $files[] = $path;
            }
        }

        return array_values(array_unique($files));
    }

    private function since(): CarbonImmutable|false|null
    {
        $since = $this->option('since');

        if (! is_string($since) || trim($since) === '') {
            return null;
        }

        if (preg_match('/^(\d+)\s*(d|days?|h|hours?)$/i', trim($since), $match) === 1) {
            $hours = strtolower($match[2][0]) === 'd' ? (int) $match[1] * 24 : (int) $match[1];

            return CarbonImmutable::now()->subHours($hours);
        }

        try {
            return CarbonImmutable::parse($since);
        } catch (Throwable) {
            return false;
        }
    }
}
