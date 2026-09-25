<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Publishing;

use Alashqar\Lazarus\Healing\Data\HealingReport;
use Alashqar\Lazarus\Sandbox\SandboxException;
use Alashqar\Lazarus\Sandbox\Worktree;
use Alashqar\Lazarus\Support\Path;
use Carbon\CarbonImmutable;

/**
 * The default publisher: a `git am`-ready .patch file and a Markdown report on disk.
 * Needs no credentials, so Lazarus is useful before anyone hands it a GitHub token.
 */
final readonly class PatchFilePublisher implements Publisher
{
    public function __construct(
        private ReportRenderer $renderer,
        private string $directory,
    ) {}

    public function publish(HealingReport $report, Worktree $worktree): PublishResult
    {
        if (! is_dir($this->directory) && ! mkdir($this->directory, 0o775, true) && ! is_dir($this->directory)) {
            throw new SandboxException('Could not create '.$this->directory);
        }

        $name = sprintf('%s-%s', CarbonImmutable::now()->format('Ymd-His'), $report->incident->shortFingerprint());
        $directory = Path::normalize($this->directory);
        $patch = $directory.'/'.$name.'.patch';
        $markdown = $directory.'/'.$name.'.md';

        file_put_contents($patch, $worktree->formatPatch()."\n");
        file_put_contents($markdown, $this->renderer->render($report));

        return new PublishResult(
            description: sprintf('Patch written to %s (apply with `git am %s`)', $patch, basename($patch)),
            path: $patch,
        );
    }

    public function name(): string
    {
        return 'patch';
    }
}
