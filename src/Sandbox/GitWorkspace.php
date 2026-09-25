<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Sandbox;

use Alashqar\Lazarus\Support\Path;
use Alashqar\Lazarus\Support\Project;
use Throwable;

/**
 * Creates and always removes the isolated `git worktree` each heal runs in.
 */
final readonly class GitWorkspace
{
    /**
     * @param  list<string>  $copyFiles  Untracked files (such as .env) the test suite needs.
     */
    public function __construct(
        private Git $git,
        private Project $project,
        private string $worktreesPath,
        private string $branchPrefix = 'lazarus/fix-',
        private bool $requireCleanTree = true,
        private array $copyFiles = [],
    ) {}

    public function isRepository(): bool
    {
        return $this->git->attempt($this->project->root, ['rev-parse', '--is-inside-work-tree'])->passed();
    }

    public function isClean(): bool
    {
        $status = $this->git->attempt($this->project->root, ['status', '--porcelain', '--untracked-files=no']);

        return $status->passed() && trim($status->output) === '';
    }

    public function head(): string
    {
        return $this->git->run($this->project->root, ['rev-parse', 'HEAD']);
    }

    public function currentBranch(): string
    {
        return $this->git->run($this->project->root, ['rev-parse', '--abbrev-ref', 'HEAD']);
    }

    public function remoteUrl(string $remote = 'origin'): ?string
    {
        $result = $this->git->attempt($this->project->root, ['remote', 'get-url', $remote]);

        return $result->passed() ? trim($result->output) : null;
    }

    public function branchFor(string $fingerprint): string
    {
        return $this->branchPrefix.substr($fingerprint, 0, 10);
    }

    public function branchExists(string $branch): bool
    {
        return $this->git->attempt($this->project->root, ['rev-parse', '--verify', '--quiet', 'refs/heads/'.$branch])->passed();
    }

    /**
     * @throws SandboxException
     */
    public function create(string $fingerprint): Worktree
    {
        if (! $this->isRepository()) {
            throw new SandboxException('The project is not a git repository.');
        }

        // The worktree is built from HEAD, so uncommitted edits would silently be left out
        // and the reproduction would run against different code than production.
        if ($this->requireCleanTree && ! $this->isClean()) {
            throw new SandboxException('The working tree has uncommitted changes. Commit or stash them first.');
        }

        $branch = $this->branchFor($fingerprint);
        $base = $this->head();
        $path = Path::normalize($this->worktreesPath).'/'.substr($fingerprint, 0, 10).'-'.bin2hex(random_bytes(3));

        // A branch left behind by a crashed run would make `worktree add -b` fail.
        if ($this->branchExists($branch)) {
            $this->git->attempt($this->project->root, ['worktree', 'prune']);
            $this->git->run($this->project->root, ['branch', '-D', $branch]);
        }

        if (! is_dir($this->worktreesPath)) {
            mkdir($this->worktreesPath, 0o775, true);
        }

        $this->git->run($this->project->root, ['worktree', 'add', '-b', $branch, $path, $base]);

        foreach ($this->copyFiles as $file) {
            if (is_file($source = $this->project->path($file))) {
                copy($source, $path.'/'.$file);
            }
        }

        return new Worktree($path, $branch, $base, $this->git);
    }

    /**
     * Remove the worktree and its local branch. Safe to call on a half-created worktree.
     */
    public function remove(Worktree $worktree): void
    {
        $removed = $this->git->attempt($this->project->root, ['worktree', 'remove', '--force', $worktree->path]);

        if (! $removed->passed() && is_dir($worktree->path)) {
            self::deleteDirectory($worktree->path);
        }

        $this->git->attempt($this->project->root, ['worktree', 'prune']);

        if ($this->branchExists($worktree->branch)) {
            $this->git->attempt($this->project->root, ['branch', '-D', $worktree->branch]);
        }
    }

    /**
     * Doctor check: create and remove a detached worktree for real.
     */
    public function canCreateWorktrees(): bool
    {
        $path = Path::normalize($this->worktreesPath).'/doctor-'.bin2hex(random_bytes(3));

        try {
            if (! is_dir($this->worktreesPath)) {
                mkdir($this->worktreesPath, 0o775, true);
            }

            return $this->git->attempt($this->project->root, ['worktree', 'add', '--detach', $path, 'HEAD'])->passed();
        } catch (Throwable) {
            return false;
        } finally {
            $this->git->attempt($this->project->root, ['worktree', 'remove', '--force', $path]);
            $this->git->attempt($this->project->root, ['worktree', 'prune']);
        }
    }

    private static function deleteDirectory(string $directory): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($directory);
    }
}
