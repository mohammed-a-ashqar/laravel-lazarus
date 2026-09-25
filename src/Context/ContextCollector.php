<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Context;

use Alashqar\Lazarus\Capture\ExceptionSnapshot;
use Alashqar\Lazarus\Enums\TestFramework;
use Alashqar\Lazarus\Redaction\Redactor;
use Alashqar\Lazarus\Support\Project;
use Alashqar\Lazarus\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * Turns a raw exception into the redacted, size-capped context a model can reason about.
 */
final readonly class ContextCollector
{
    public function __construct(
        private Project $project,
        private Redactor $redactor,
        private Settings $settings,
        private QueryRecorder $queries,
    ) {}

    public function collect(ExceptionSnapshot $exception, ?Request $request = null): IncidentContext
    {
        $frames = $this->frames($exception);

        return new IncidentContext(
            frames: $frames,
            sources: $this->sources($frames),
            route: $this->route($request),
            method: $request?->getMethod(),
            input: $request === null ? [] : $this->redactor->redactArray($request->except(array_keys($request->allFiles()))),
            queries: $this->settings->bool('context.record_queries', true)
                ? array_map($this->redactor->redact(...), $this->queries->recent())
                : [],
            gitSha: GitHead::sha($this->project->root),
            framework: TestFramework::detect($this->project->root),
        );
    }

    /**
     * @return list<StackFrame>
     */
    private function frames(ExceptionSnapshot $exception): array
    {
        $max = $this->settings->int('context.max_frames', 8);
        $radius = $this->settings->int('context.snippet_lines', 12);
        $frames = [];
        $seen = [];

        $stack = $exception->callStack();

        foreach ($stack as $index => $frame) {
            if ($frame['file'] === null || $frame['line'] === null) {
                continue;
            }

            $relative = $this->project->relative($frame['file']);

            if ($relative === null || isset($seen[$relative.':'.$frame['line']])) {
                continue;
            }

            $seen[$relative.':'.$frame['line']] = true;

            // The frame at index i records where the call to frame i-1's function happened,
            // so the function *running* at this line is the one of the previous entry.
            $running = $stack[$index - 1] ?? null;
            $call = $running !== null && $running['function'] !== null
                ? ($running['class'] !== null ? $running['class'].'::' : '').$running['function'].'()'
                : null;

            $frames[] = new StackFrame($relative, $frame['line'], $index === 0 ? null : $call, $this->snippet($frame['file'], $frame['line'], $radius));

            if (count($frames) >= $max) {
                break;
            }
        }

        return $frames;
    }

    private function snippet(string $file, int $line, int $radius): string
    {
        $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : false;

        if ($lines === false) {
            return '';
        }

        $start = max(1, $line - $radius);
        $end = min(count($lines), $line + $radius);
        $snippet = [];

        for ($number = $start; $number <= $end; $number++) {
            $marker = $number === $line ? '>' : ' ';
            $snippet[] = sprintf('%s%4d | %s', $marker, $number, $lines[$number - 1]);
        }

        return $this->redactor->redact(implode("\n", $snippet));
    }

    /**
     * @param  list<StackFrame>  $frames
     * @return array<string, string>
     */
    private function sources(array $frames): array
    {
        $perFile = $this->settings->int('context.max_file_bytes', 24_000);
        $budget = $this->settings->int('context.max_total_bytes', 80_000);
        $sources = [];

        foreach ($frames as $frame) {
            if (isset($sources[$frame->file]) || ! str_ends_with($frame->file, '.php')) {
                continue;
            }

            $contents = @file_get_contents($this->project->path($frame->file));

            if ($contents === false || $budget <= 0) {
                continue;
            }

            if (strlen($contents) > $perFile) {
                $contents = substr($contents, 0, $perFile)."\n/* ... truncated by Lazarus ... */";
            }

            $sources[$frame->file] = $this->redactor->redact($contents);
            $budget -= strlen($contents);
        }

        return $sources;
    }

    private function route(?Request $request): ?string
    {
        if ($request === null) {
            return null;
        }

        $route = $request->route();

        if (! $route instanceof Route) {
            return '/'.ltrim($request->path(), '/');
        }

        $action = $route->getActionName();

        return trim('/'.ltrim($route->uri(), '/').' '.($route->getName() !== null ? '['.$route->getName().'] ' : '').'-> '.$action);
    }
}
