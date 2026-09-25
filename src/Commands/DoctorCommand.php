<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Commands;

use Alashqar\Lazarus\Enums\TestFramework;
use Alashqar\Lazarus\Llm\Contracts\LlmDriver;
use Alashqar\Lazarus\Llm\TokenBudget;
use Alashqar\Lazarus\Publishing\GitHubPublisher;
use Alashqar\Lazarus\Sandbox\GitWorkspace;
use Alashqar\Lazarus\Sandbox\ProcessRunner;
use Alashqar\Lazarus\Sandbox\TestHarness;
use Alashqar\Lazarus\Support\Project;
use Alashqar\Lazarus\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionResolverInterface;
use Throwable;

final class DoctorCommand extends Command
{
    protected $signature = 'lazarus:doctor';

    protected $description = 'Check that everything a heal needs is in place';

    private bool $healthy = true;

    public function handle(
        Settings $settings,
        Project $project,
        ProcessRunner $runner,
        GitWorkspace $workspace,
        TestHarness $harness,
        TokenBudget $budget,
        ConnectionResolverInterface $db,
    ): int {
        $this->newLine();
        $this->line('  <options=bold>Lazarus doctor</> · '.$project->root);
        $this->newLine();

        $git = $runner->run(['git', '--version'], $project->root, 15);
        $this->check('git is installed', $git->passed(), trim($git->output));

        $isRepository = $git->passed() && $workspace->isRepository();
        $this->check('Project is a git repository', $isRepository);
        $this->check('Working tree is clean', ! $isRepository || $workspace->isClean() || ! $settings->bool('sandbox.require_clean_tree', true), 'commit or stash before healing', required: false);
        $this->check('Can create git worktrees', $isRepository && $workspace->canCreateWorktrees());

        $framework = TestFramework::detect($project->root);
        $command = $harness->command($framework);
        $binary = $command[1] ?? $command[0];
        $this->check('Test runner found ('.$framework->value.')', is_file($binary), implode(' ', $command));

        try {
            $db->connection()->getSchemaBuilder()->hasTable('lazarus_incidents')
                ? $this->check('lazarus_incidents table exists', true)
                : $this->check('lazarus_incidents table exists', false, 'run php artisan migrate');
        } catch (Throwable $exception) {
            $this->check('Database is reachable', false, $exception->getMessage());
        }

        try {
            $driver = $this->laravel->make(LlmDriver::class);
            $this->check("LLM driver {$driver->name()} ({$driver->model()}) is configured", $driver->isConfigured(), $driver->isConfigured() ? '' : 'set the API key in .env');
        } catch (Throwable $exception) {
            $this->check('LLM driver can be created', false, $exception->getMessage());
        }

        if ($settings->string('publisher', 'patch') === 'github') {
            $repository = $settings->nullableString('publishers.github.repository') ?? GitHubPublisher::repositoryFromRemote($workspace->remoteUrl());
            $this->check('GitHub token is set', $settings->string('publishers.github.token') !== '', 'set LAZARUS_GITHUB_TOKEN');
            $this->check('GitHub repository is known', $repository !== null, $repository ?? 'set LAZARUS_GITHUB_REPOSITORY=owner/repo');
        } else {
            $this->check('Publisher: patch files', true, 'set LAZARUS_PUBLISHER=github to open pull requests', required: false);
        }

        $tokens = $budget->remainingTokens();
        $cost = $budget->remainingCost();
        $this->check(
            'Daily budget has room',
            ($tokens === null || $tokens > 0) && ($cost === null || $cost > 0),
            sprintf('%s tokens and %s left today', $tokens === null ? 'unlimited' : number_format($tokens), $cost === null ? 'unlimited' : '$'.number_format($cost, 2)),
        );

        $this->check('Auto-heal', $settings->bool('auto_heal'), $settings->bool('auto_heal') ? 'queued on capture' : 'off: run lazarus:heal manually', required: false);

        $this->newLine();

        if (! $this->healthy) {
            $this->components->error('Lazarus is not ready to heal yet.');

            return self::FAILURE;
        }

        $this->components->info('Lazarus is ready.');

        return self::SUCCESS;
    }

    private function check(string $label, bool $ok, string $detail = '', bool $required = true): void
    {
        $status = $ok ? '<fg=green;options=bold>OK</>' : ($required ? '<fg=red;options=bold>FAIL</>' : '<fg=yellow;options=bold>WARN</>');

        $this->components->twoColumnDetail($label.($detail !== '' ? " <fg=gray>{$detail}</>" : ''), $status);

        if (! $ok && $required) {
            $this->healthy = false;
        }
    }
}
