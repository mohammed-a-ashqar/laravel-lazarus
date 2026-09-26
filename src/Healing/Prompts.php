<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Healing;

use Alashqar\Lazarus\Context\IncidentContext;
use Alashqar\Lazarus\Enums\TestFramework;
use Alashqar\Lazarus\Healing\Data\Diagnosis;
use Alashqar\Lazarus\Healing\Data\Patch;
use Alashqar\Lazarus\Healing\Data\ReproductionTest;
use Alashqar\Lazarus\Models\Incident;
use Alashqar\Lazarus\Sandbox\TestRun;
use Alashqar\Lazarus\Sandbox\Worktree;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every prompt Lazarus sends. Kept in one place so they can be read and reviewed together.
 */
final class Prompts
{
    public static function system(): string
    {
        return <<<'TXT'
        You are Lazarus, a senior Laravel engineer who fixes production bugs with proof.
        Nothing you produce is trusted until it is verified: your tests are executed, your patches
        are applied in an isolated git worktree and the full test suite is run. A human reviews the
        result before anything is merged.

        Rules:
        - Answer with a single JSON object and nothing else.
        - Base every claim on the code you are shown. Do not invent classes, methods or files.
        - Prefer the smallest change that fixes the root cause over a workaround at the call site.
        - Secrets in the context were replaced with [REDACTED], [EMAIL] or [CARD]; never try to restore them.
        TXT;
    }

    public static function reproduction(Incident $incident, IncidentContext $context, Worktree $worktree): string
    {
        $framework = $context->framework === TestFramework::Unknown ? TestFramework::PhpUnit : $context->framework;

        return implode("\n\n", array_filter([
            '# Incident',
            self::incident($incident, $context),
            self::conventions($worktree, $framework),
            '# Task',
            <<<TXT
            Write ONE new {$framework->value} test file that reproduces this incident by exercising the application
            code the way production did.

            - On the current code the test must FAIL with `{$incident->exception_class}` ("{$incident->message}").
            - Assert the correct behaviour, so the test passes once the bug is fixed. Do not catch the exception and
              do not expect it (no expectException / toThrow for it): the failure has to come from the bug itself.
            - Keep it focused: one scenario, no network, no sleeps, no reliance on production data.
            - Use a new path under tests/ ending in Test.php. Existing test files cannot be overwritten.
            TXT,
            $framework === TestFramework::PhpUnit
                ? '- PHPUnit only: declare a class that extends Tests\TestCase with a public test method. Pest functions such as test() and it() are not installed.'
                : null,
            'Respond with JSON of this shape:',
            ReproductionTest::schema(),
        ]));
    }

    public static function reproductionFeedback(string $problem, ?TestRun $run): string
    {
        return implode("\n\n", array_filter([
            'That test was rejected: '.$problem,
            $run === null ? null : "Output of `{$run->command}` (exit code {$run->exitCode}):\n```\n{$run->excerpt(60)}\n```",
            'Write a different test that fails with the original exception. Respond with the same JSON shape.',
        ]));
    }

    /**
     * @param  list<string>  $writable
     */
    public static function patch(Incident $incident, IncidentContext $context, ReproductionTest $test, TestRun $red, array $writable): string
    {
        $paths = implode(', ', $writable);

        return implode("\n\n", [
            '# Incident',
            self::incident($incident, $context),
            '# Reproduction test (fails on the current code)',
            "`{$test->path}`\n```php\n{$test->content}\n```",
            "Output:\n```\n{$red->excerpt(40)}\n```",
            '# Task',
            <<<TXT
            Propose the minimal patch that makes this test pass without breaking any other test.

            - Express it as search/replace edits of existing files. Each "search" must be copied exactly from the
              current file (indentation included) and must match exactly once. Include a few surrounding lines.
            - Only edit files under: {$paths}. Never edit tests, configuration, migrations, composer files or .env.
            - Fix the root cause. Do not special-case the test's input and do not silence the exception.
            TXT,
            'Respond with JSON of this shape:',
            Patch::schema(),
        ]);
    }

    public static function patchFeedback(string $problem, ?TestRun $run = null): string
    {
        return implode("\n\n", array_filter([
            'That patch was rejected: '.$problem,
            $run === null ? null : "Output of `{$run->command}` (exit code {$run->exitCode}):\n```\n{$run->excerpt(60)}\n```",
            'The files are back to their original state. Propose a corrected patch with the same JSON shape.',
        ]));
    }

    public static function diagnosis(Incident $incident, ReproductionTest $test, string $diff, TestRun $green): string
    {
        return implode("\n\n", [
            '# Incident',
            "`{$incident->exception_class}`: {$incident->message}\nat {$incident->location()}",
            '# Verified fix',
            "The reproduction test `{$test->path}` now passes and the full suite is green.\n```diff\n{$diff}\n```",
            "Output after the fix:\n```\n{$green->excerpt(15)}\n```",
            '# Task',
            'Write the diagnosis a reviewer reads first. Be concrete and honest; lower the confidence if the fix '
            .'could hide a deeper problem.',
            'Respond with JSON of this shape:',
            Diagnosis::schema(),
        ]);
    }

    private static function incident(Incident $incident, IncidentContext $context): string
    {
        $sections = [
            "`{$incident->exception_class}`: {$incident->message}",
            "Thrown at {$incident->location()}, seen {$incident->occurrences} time(s).",
        ];

        if ($context->route !== null) {
            $sections[] = 'Request: '.trim(($context->method ?? '').' '.$context->route);
        }

        if ($context->input !== []) {
            $sections[] = "Input:\n```json\n".json_encode($context->input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n```";
        }

        if ($context->queries !== []) {
            $sections[] = "Recent queries:\n- ".implode("\n- ", $context->queries);
        }

        foreach ($context->frames as $index => $frame) {
            $title = $index === 0 ? 'Throw site' : 'Caller';
            $sections[] = "{$title} `{$frame->file}:{$frame->line}`".($frame->call !== null ? " in {$frame->call}" : '')."\n```php\n{$frame->snippet}\n```";
        }

        foreach ($context->sources as $path => $source) {
            $sections[] = "Full source of `{$path}`:\n```php\n{$source}\n```";
        }

        return implode("\n\n", $sections);
    }

    private static function conventions(Worktree $worktree, TestFramework $framework): string
    {
        $lines = ["# Test conventions\nFramework: {$framework->value}."];

        foreach (['tests/Pest.php', 'tests/TestCase.php'] as $file) {
            $contents = $worktree->read($file);

            if ($contents !== null && strlen($contents) < 6_000) {
                $lines[] = "`{$file}`:\n```php\n{$contents}\n```";
            }
        }

        $tests = self::existingTests($worktree->absolute('tests'));

        if ($tests !== []) {
            $lines[] = "Existing tests:\n- ".implode("\n- ", $tests);
        }

        return implode("\n\n", $lines);
    }

    /**
     * @return list<string>
     */
    private static function existingTests(string $directory, int $limit = 25): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $found = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if (str_ends_with($file->getFilename(), 'Test.php')) {
                $found[] = 'tests/'.ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($directory))), '/');
            }

            if (count($found) >= $limit) {
                break;
            }
        }

        sort($found);

        return $found;
    }
}
