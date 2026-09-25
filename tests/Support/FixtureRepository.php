<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Tests\Support;

use Alashqar\Lazarus\Capture\ExceptionSnapshot;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Process\Process;

/**
 * A throwaway git repository holding the invoice-app fixture: a real project with a real bug.
 */
final class FixtureRepository
{
    public const SECRET = 'e2e-db-password-that-must-never-leak';

    public readonly string $path;

    public readonly string $scratch;

    private function __construct()
    {
        $this->scratch = str_replace('\\', '/', sys_get_temp_dir()).'/lazarus-e2e-'.bin2hex(random_bytes(4));
        $this->path = $this->scratch.'/invoice-app';
    }

    public static function create(): self
    {
        $repository = new self;

        self::copyDirectory(dirname(__DIR__).'/Fixtures/invoice-app', $repository->path);

        file_put_contents($repository->path.'/.gitignore', "/.env\n/.phpunit.cache/\n");
        file_put_contents($repository->path.'/.env', 'APP_ENV=local'."\n".'DB_PASSWORD='.self::SECRET."\n");

        $repository->git('init', '-q', '-b', 'main');
        $repository->git('config', 'core.autocrlf', 'false');
        $repository->git('add', '-A');
        $repository->git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.com', 'commit', '-q', '-m', 'Initial commit');

        return $repository;
    }

    public function git(string ...$arguments): string
    {
        $process = new Process(['git', ...$arguments], $this->path);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('git '.implode(' ', $arguments).' failed: '.$process->getErrorOutput());
        }

        return trim($process->getOutput());
    }

    /**
     * Trigger the bug in a separate PHP process and return what the exception handler would see.
     */
    public function triggerBug(): ExceptionSnapshot
    {
        $process = new Process([PHP_BINARY, 'trigger.php', dirname(__DIR__, 2).'/vendor/autoload.php'], $this->path);
        $process->mustRun();

        $data = json_decode($process->getOutput(), true);

        if (! is_array($data)) {
            throw new RuntimeException('The fixture did not throw: '.$process->getOutput().$process->getErrorOutput());
        }

        return ExceptionSnapshot::fromArray($data);
    }

    public function read(string $relative): string
    {
        return (string) file_get_contents($this->path.'/'.$relative);
    }

    /**
     * @return list<string>
     */
    public function branches(): array
    {
        $output = $this->git('branch', '--format=%(refname:short)');

        return $output === '' ? [] : explode("\n", $output);
    }

    public function worktreeCount(): int
    {
        return substr_count($this->git('worktree', 'list', '--porcelain'), 'worktree ');
    }

    public function delete(): void
    {
        self::deleteDirectory($this->scratch);
    }

    private static function copyDirectory(string $from, string $to): void
    {
        mkdir($to, 0o777, true);

        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            $target = $to.'/'.substr(str_replace('\\', '/', $item->getPathname()), strlen(str_replace('\\', '/', $from)) + 1);
            $item->isDir() ? @mkdir($target, 0o777, true) : copy($item->getPathname(), $target);
        }
    }

    private static function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            @chmod($item->getPathname(), 0o777);
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($directory);
    }
}
