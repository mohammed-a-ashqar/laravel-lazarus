<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Publishing;

use Alashqar\Lazarus\Healing\Data\HealingReport;
use Alashqar\Lazarus\Redaction\Redactor;

/**
 * The Markdown case for a fix, used as the pull request body and as the local report.
 */
final readonly class ReportRenderer
{
    public function __construct(private Redactor $redactor) {}

    public function render(HealingReport $report): string
    {
        $incident = $report->incident;
        $diagnosis = $report->diagnosis;
        $test = $report->test;

        $markdown = <<<MD
        ## Lazarus: verified fix for `{$incident->shortClass()}`

        > {$this->quote($incident->exception_class.': '.$incident->message)}
        >
        > `{$incident->location()}` · seen **{$incident->occurrences}** time(s) · first {$incident->first_seen_at->toDateTimeString()} · last {$incident->last_seen_at->toDateTimeString()}

        | Proof | Result |
        | --- | --- |
        | Reproduction test on the current code | :red_circle: fails with `{$incident->shortClass()}` ({$report->red->seconds}s) |
        | Reproduction test with this patch | :green_circle: passes ({$report->green->seconds}s) |
        | Full test suite with this patch | :green_circle: passes ({$report->suite->seconds}s) |

        ### Diagnosis

        **Root cause:** {$diagnosis->rootCause}

        {$diagnosis->explanation}

        **Confidence:** {$diagnosis->confidencePercent()}%

        ### The fix

        {$report->patch->summary}

        ```diff
        {$this->fenced($report->diff)}
        ```

        ### Reproduction test

        `{$test->path}`{$this->reasoning($test->reasoning)}

        ```php
        {$this->fenced($test->content)}
        ```

        <details>
        <summary>Red: output before the fix</summary>

        ```
        {$this->fenced($report->red->excerpt(40))}
        ```

        </details>

        <details>
        <summary>Green: output after the fix</summary>

        ```
        {$this->fenced($report->green->excerpt(20))}
        ```

        Full suite: `{$report->suite->command}`

        ```
        {$this->fenced($report->suite->excerpt(15))}
        ```

        </details>

        ---

        Branch `{$report->branch}` from `{$this->short($report->baseSha)}` · model `{$report->model}` · {$this->number($report->usage->totalTokens())} tokens · \${$this->money($report->usage->cost)} · incident `{$incident->shortFingerprint()}`

        Lazarus never merges. Review this like any other pull request.
        MD;

        return $this->redactor->redact($markdown)."\n";
    }

    /**
     * Content placed inside a code fence must not be able to close it early.
     */
    private function fenced(string $text): string
    {
        return rtrim(str_replace('```', "'''", $text));
    }

    private function quote(string $text): string
    {
        return str_replace("\n", "\n> ", trim($text));
    }

    private function reasoning(string $reasoning): string
    {
        return $reasoning === '' ? '' : ': '.$reasoning;
    }

    private function short(string $sha): string
    {
        return substr($sha, 0, 7);
    }

    private function number(int $value): string
    {
        return number_format($value);
    }

    private function money(float $value): string
    {
        return number_format($value, 4);
    }
}
