<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Sandbox;

/**
 * A disposable checkout of HEAD on its own branch. All reads and writes stay inside it.
 */
final readonly class Worktree
{
    public function __construct(
        public string $path,
        public string $branch,
        public string $baseSha,
        private Git $git,
    ) {}

    public function exists(string $relative): bool
    {
        return is_file($this->absolute($relative));
    }

    public function read(string $relative): ?string
    {
        $file = $this->absolute($relative);

        if (! is_file($file)) {
            return null;
        }

        $contents = file_get_contents($file);

        return $contents === false ? null : $contents;
    }

    /**
     * @throws SandboxException when the target would resolve outside the worktree.
     */
    public function write(string $relative, string $contents): void
    {
        $file = $this->absolute($relative);
        $directory = dirname($file);

        if (! is_dir($directory) && ! mkdir($directory, 0o775, true) && ! is_dir($directory)) {
            throw new SandboxException('Could not create '.$directory);
        }

        // A symlinked directory inside the repository must not become a way out of it.
        $real = realpath($directory);
        $root = realpath($this->path);

        if ($real === false || $root === false || ! str_starts_with(strtolower(str_replace('\\', '/', $real).'/'), strtolower(str_replace('\\', '/', $root).'/'))) {
            throw new SandboxException(sprintf('"%s" resolves outside the worktree.', $relative));
        }

        if (file_put_contents($file, $contents) === false) {
            throw new SandboxException('Could not write '.$relative);
        }
    }

    public function delete(string $relative): void
    {
        if (is_file($file = $this->absolute($relative))) {
            unlink($file);
        }
    }

    /**
     * Stage exactly these paths and return their diff against the base commit.
     *
     * @param  list<string>  $paths
     */
    public function diff(array $paths): string
    {
        $this->git->run($this->path, ['add', '--', ...$paths]);

        return $this->git->run($this->path, ['diff', '--cached', '--no-color', '--no-ext-diff', $this->baseSha, '--', ...$paths]);
    }

    /**
     * @param  list<string>  $paths
     */
    public function commit(array $paths, string $message): string
    {
        $this->git->run($this->path, ['add', '--', ...$paths]);
        $this->git->run($this->path, ['commit', '--no-verify', '-m', $message, '--', ...$paths]);

        return $this->git->run($this->path, ['rev-parse', 'HEAD']);
    }

    /**
     * The committed fix as an mbox patch that `git am` can apply.
     */
    public function formatPatch(): string
    {
        return $this->git->run($this->path, ['format-patch', '-1', '--stdout', '--no-color', 'HEAD']);
    }

    /**
     * @param  array<string, string>  $env
     */
    public function push(string $remote, array $env = []): void
    {
        $this->git->run($this->path, ['push', '--no-verify', $remote, $this->branch.':refs/heads/'.$this->branch], $env, 300);
    }

    public function absolute(string $relative): string
    {
        return rtrim($this->path, '/').'/'.ltrim(str_replace('\\', '/', $relative), '/');
    }
}
