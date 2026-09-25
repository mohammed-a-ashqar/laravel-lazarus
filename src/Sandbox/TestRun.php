<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Sandbox;

/**
 * The evidence from one process run: what was executed, how it ended, what it printed.
 */
final readonly class TestRun
{
    public function __construct(
        public string $command,
        public int $exitCode,
        public string $output,
        public float $seconds,
        public bool $timedOut = false,
    ) {}

    public function passed(): bool
    {
        return $this->exitCode === 0 && ! $this->timedOut;
    }

    public function mentions(string $needle): bool
    {
        return $needle !== '' && str_contains($this->output, $needle);
    }

    /**
     * The lines around the first mention of $needle, or the tail when it is not mentioned.
     */
    public function around(string $needle, int $before = 1, int $after = 8): string
    {
        $lines = preg_split('/\R/', rtrim($this->output)) ?: [];

        foreach ($lines as $index => $line) {
            if ($needle !== '' && str_contains($line, $needle)) {
                return implode("\n", array_slice($lines, max(0, $index - $before), $before + $after + 1));
            }
        }

        return $this->excerpt($before + $after + 1);
    }

    /**
     * The last lines of output, where test runners put the failure summary.
     */
    public function excerpt(int $lines = 40): string
    {
        $all = preg_split('/\R/', rtrim($this->output)) ?: [];

        if (count($all) <= $lines) {
            return implode("\n", $all);
        }

        return "...\n".implode("\n", array_slice($all, -$lines));
    }
}
