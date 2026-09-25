<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Sandbox;

/**
 * Thin wrapper over the git CLI that fails loudly.
 */
final readonly class Git
{
    /**
     * @param  array{name: string, email: string}  $author
     */
    public function __construct(
        private ProcessRunner $runner,
        private array $author = ['name' => 'Lazarus', 'email' => 'lazarus@localhost'],
    ) {}

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $env
     *
     * @throws SandboxException
     */
    public function run(string $cwd, array $arguments, array $env = [], int $timeout = 120): string
    {
        $result = $this->attempt($cwd, $arguments, $env, $timeout);

        if (! $result->passed()) {
            throw new SandboxException(sprintf('`git %s` failed: %s', implode(' ', $arguments), trim($result->output)));
        }

        return rtrim($result->output);
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $env
     */
    public function attempt(string $cwd, array $arguments, array $env = [], int $timeout = 120): TestRun
    {
        // Commits made by Lazarus carry its own identity and are never signed: a signing
        // prompt would hang an unattended queue worker.
        $identity = [
            '-c', 'user.name='.$this->author['name'],
            '-c', 'user.email='.$this->author['email'],
            '-c', 'commit.gpgsign=false',
        ];

        return $this->runner->run(['git', ...$identity, ...$arguments], $cwd, $timeout, $env);
    }
}
