<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Sandbox;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs commands with a hard timeout and a scrubbed environment.
 *
 * Model-written tests are code Lazarus did not write, so they never inherit the parent's
 * environment: no API keys, no GitHub token and, crucially, no production DB_* variables.
 * Only what a PHP or git process needs to start is passed through.
 */
final readonly class ProcessRunner
{
    private const INHERITED = [
        'PATH', 'PATHEXT', 'SYSTEMROOT', 'SYSTEMDRIVE', 'WINDIR', 'COMSPEC', 'TEMP', 'TMP', 'TMPDIR',
        'HOME', 'USERPROFILE', 'APPDATA', 'LOCALAPPDATA', 'PROGRAMDATA', 'LANG', 'LC_ALL', 'TZ',
        'COMPOSER_HOME', 'PHPRC', 'PHP_INI_SCAN_DIR', 'XDG_CONFIG_HOME',
    ];

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env  Extra variables for this process only.
     */
    public function run(array $command, string $cwd, int $timeout, array $env = []): TestRun
    {
        $process = new Process($command, $cwd, $this->environment($env), null, max(1, $timeout));
        $started = microtime(true);
        $timedOut = false;

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $timedOut = true;
        }

        $output = $process->getOutput().$process->getErrorOutput();

        if ($timedOut) {
            $output .= sprintf("\n[Lazarus] Process killed after the %d second timeout.", $timeout);
        }

        return new TestRun(
            command: $this->display($command),
            exitCode: $process->getExitCode() ?? 1,
            output: self::stripAnsi($output),
            seconds: round(microtime(true) - $started, 2),
            timedOut: $timedOut,
        );
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string|false>
     */
    private function environment(array $extra): array
    {
        $env = [];

        foreach (array_keys(getenv()) as $name) {
            // false tells Symfony Process to remove an inherited variable.
            $env[$name] = in_array(strtoupper($name), self::INHERITED, true) ? (string) getenv($name) : false;
        }

        foreach (array_merge(array_keys($_ENV), array_keys($_SERVER)) as $name) {
            if (is_string($name) && ! array_key_exists($name, $env) && ! in_array(strtoupper($name), self::INHERITED, true)) {
                $env[$name] = false;
            }
        }

        return array_merge($env, $extra);
    }

    /**
     * @param  list<string>  $command
     */
    private function display(array $command): string
    {
        return implode(' ', array_map(
            static fn (string $part): string => preg_match('/[\s"]/', $part) ? '"'.str_replace('"', '\"', $part).'"' : $part,
            $command,
        ));
    }

    public static function stripAnsi(string $text): string
    {
        return (string) preg_replace('/\e\[[\d;?]*[A-Za-z]/', '', $text);
    }
}
