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
