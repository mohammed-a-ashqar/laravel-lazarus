<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Sandbox;

use Alashqar\Lazarus\Enums\TestFramework;
use Alashqar\Lazarus\Support\Project;
use Alashqar\Lazarus\Support\Settings;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Runs the application's own test runner inside a worktree, against the existing vendor/.
 *
 * A worktree has no vendor directory, and composer's autoloader (like Pest's root path and
 * Laravel's base path) points back at the main checkout. Instead of a slow composer install,
 * each run gets an `auto_prepend_file` overlay that maps the project's PSR-4 namespaces and
 * base path onto the worktree, so the tests exercise the patched code and nothing else.
 */
final readonly class TestHarness
{
    public const OVERLAY = '.lazarus-overlay.php';

    public function __construct(
        private ProcessRunner $runner,
        private Settings $settings,
        private Project $project,
    ) {}

    public function runTest(Worktree $worktree, TestFramework $framework, string $testPath): TestRun
    {
        return $this->run($worktree, [...$this->command($framework, 'testing.command'), $testPath], $this->settings->int('testing.test_timeout', 120));
    }

    public function runSuite(Worktree $worktree, TestFramework $framework): TestRun
    {
        $command = $this->settings->command('testing.suite_command') !== null
            ? $this->command($framework, 'testing.suite_command')
            : $this->command($framework, 'testing.command');

        return $this->run($worktree, $command, $this->settings->int('testing.suite_timeout', 900));
    }

    /**
     * The command with placeholders expanded, before the overlay is injected.
     *
     * @return list<string>
     */
    public function command(TestFramework $framework, string $key = 'testing.command'): array
    {
        $command = $this->settings->command($key) ?? $framework->defaultCommand();

        return array_map(fn (string $part): string => strtr($part, [
            '{php}' => $this->php(),
            '{vendor}' => $this->project->vendorPath,
        ]), $command);
    }

    public function php(): string
    {
        return (new PhpExecutableFinder)->find(false) ?: PHP_BINARY;
    }

    /**
     * @param  list<string>  $command
     */
    private function run(Worktree $worktree, array $command, int $timeout): TestRun
    {
        $overlay = $this->writeOverlay($worktree);

        if (($command[0] ?? null) === $this->php()) {
            // Quoted, because the ini parser chokes on characters such as ~ in Windows short paths.
            array_splice($command, 1, 0, ['-d', 'auto_prepend_file="'.$overlay.'"']);
        }

        return $this->runner->run($command, $worktree->path, $timeout, [
            'APP_BASE_PATH' => $worktree->path,
            'COMPOSER_VENDOR_DIR' => $this->project->vendorPath,
        ]);
    }

    private function writeOverlay(Worktree $worktree): string
    {
        $file = $worktree->absolute(self::OVERLAY);

        file_put_contents($file, strtr((string) file_get_contents(__DIR__.'/overlay.stub'), [
            '{{ root }}' => var_export($worktree->path, true),
            '{{ vendor }}' => var_export($this->project->vendorPath, true),
            '{{ map }}' => var_export($this->namespaceMap($worktree), true),
        ]));

        return $file;
    }

    /**
     * PSR-4 prefixes from the worktree's composer.json, longest first, mapped to worktree paths.
     *
     * @return array<string, list<string>>
     */
    private function namespaceMap(Worktree $worktree): array
    {
        $composer = json_decode((string) $worktree->read('composer.json'), true);
        $map = [];

        foreach (['autoload', 'autoload-dev'] as $section) {
            $autoload = is_array($composer) && is_array($composer[$section] ?? null) ? $composer[$section] : [];
            $psr4 = is_array($autoload['psr-4'] ?? null) ? $autoload['psr-4'] : [];

            foreach ($psr4 as $prefix => $directories) {
                foreach ((array) $directories as $directory) {
                    if (is_string($directory)) {
                        $map[(string) $prefix][] = rtrim($worktree->absolute($directory), '/');
                    }
                }
            }
        }

        uksort($map, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $map;
    }
}
