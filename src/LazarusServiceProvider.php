<?php

declare(strict_types=1);

namespace Alashqar\Lazarus;

use Alashqar\Lazarus\Capture\ExceptionCapturer;
use Alashqar\Lazarus\Capture\HealingThrottle;
use Alashqar\Lazarus\Commands\DoctorCommand;
use Alashqar\Lazarus\Commands\HealCommand;
use Alashqar\Lazarus\Commands\IgnoreCommand;
use Alashqar\Lazarus\Commands\ListCommand;
use Alashqar\Lazarus\Commands\NotifyTestCommand;
use Alashqar\Lazarus\Commands\ScanCommand;
use Alashqar\Lazarus\Context\QueryRecorder;
use Alashqar\Lazarus\Events\FixPublished;
use Alashqar\Lazarus\Events\HealingFailed;
use Alashqar\Lazarus\Events\IncidentCaptured;
use Alashqar\Lazarus\Healing\Healer;
use Alashqar\Lazarus\Healing\Steps\ProposePatch;
use Alashqar\Lazarus\Healing\Steps\WriteReproductionTest;
use Alashqar\Lazarus\Llm\Contracts\LlmDriver;
use Alashqar\Lazarus\Llm\LlmClient;
use Alashqar\Lazarus\Llm\LlmManager;
use Alashqar\Lazarus\Llm\TokenBudget;
use Alashqar\Lazarus\Notifications\Notifier;
use Alashqar\Lazarus\Publishing\GitHubPublisher;
use Alashqar\Lazarus\Publishing\PatchFilePublisher;
use Alashqar\Lazarus\Publishing\Publisher;
use Alashqar\Lazarus\Publishing\ReportRenderer;
use Alashqar\Lazarus\Redaction\Redactor;
use Alashqar\Lazarus\Sandbox\Git;
use Alashqar\Lazarus\Sandbox\GitWorkspace;
use Alashqar\Lazarus\Sandbox\PathGuard;
use Alashqar\Lazarus\Sandbox\ProcessRunner;
use Alashqar\Lazarus\Support\Project;
use Alashqar\Lazarus\Support\Settings;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Throwable;

final class LazarusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/lazarus.php', 'lazarus');

        $this->app->singleton(Settings::class);

        $this->app->singleton(Project::class, fn (Container $app): Project => new Project(
            $this->settings()->nullableString('project_path') ?? $this->app->basePath(),
            $this->settings()->nullableString('testing.vendor_path'),
        ));

        $this->app->singleton(Redactor::class, function (): Redactor {
            $settings = $this->settings();

            return Redactor::withEnvironmentFile(
                $settings->strings('redaction.keys'),
                $settings->bool('redaction.redact_env_values', true) ? $this->project()->path('.env') : null,
                $settings->int('redaction.min_env_value_length', 6),
            );
        });

        $this->app->singleton(QueryRecorder::class, fn (): QueryRecorder => new QueryRecorder($this->settings()->int('context.max_queries', 10)));

        $this->app->singleton(HealingThrottle::class, fn (): HealingThrottle => new HealingThrottle($this->cache(), $this->settings()));

        $this->app->singleton(TokenBudget::class, fn (): TokenBudget => new TokenBudget(
            $this->cache(),
            $this->settings()->int('budget.daily_tokens'),
            $this->settings()->float('budget.daily_cost'),
        ));

        $this->app->singleton(LlmManager::class);
        $this->app->bind(LlmDriver::class, fn (Container $app): LlmDriver => $app->make(LlmManager::class)->driver());

        $this->app->bind(LlmClient::class, fn (Container $app): LlmClient => new LlmClient(
            $app->make(LlmDriver::class),
            $app->make(Redactor::class),
            $app->make(TokenBudget::class),
            $this->settings()->int('llm.max_invalid_responses', 2),
        ));

        $this->app->singleton(Notifier::class, fn (Container $app): Notifier => new Notifier(
            $this->settings(),
            $app->make(Http::class),
            $app->make(Mailer::class),
            is_string($name = $app->make('config')->get('app.name')) ? $name : 'Laravel',
            $this->app->environment(),
        ));

        $this->registerSandbox();
        $this->registerHealing();

        $this->app->singleton(ExceptionCapturer::class, fn (Container $app): ExceptionCapturer => new ExceptionCapturer(
            $this->settings(),
            $app->make(Capture\Fingerprinter::class),
            $app->make(Context\ContextCollector::class),
            $app->make(Redactor::class),
            $app->make(HealingThrottle::class),
            $app->make(Dispatcher::class),
            $app->make(\Illuminate\Contracts\Bus\Dispatcher::class),
            $this->app->environment(),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/lazarus.php' => $this->app->configPath('lazarus.php')], 'lazarus-config');

            $this->commands([ListCommand::class, HealCommand::class, DoctorCommand::class, IgnoreCommand::class, ScanCommand::class, NotifyTestCommand::class]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (! $this->settings()->bool('enabled', true)) {
            return;
        }

        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if (method_exists($handler, 'reportable')) {
                $handler->reportable(function (Throwable $exception): void {
                    $this->app->make(ExceptionCapturer::class)->report($exception, $this->currentRequest());
                });
            }
        });

        $events = $this->app->make(Dispatcher::class);
        $events->listen(IncidentCaptured::class, fn (IncidentCaptured $event) => $this->app->make(Notifier::class)->captured($event));
        $events->listen(FixPublished::class, fn (FixPublished $event) => $this->app->make(Notifier::class)->fixed($event));
        $events->listen(HealingFailed::class, fn (HealingFailed $event) => $this->app->make(Notifier::class)->failed($event));

        if ($this->settings()->bool('context.record_queries', true)) {
            $this->app->make(Dispatcher::class)->listen(
                QueryExecuted::class,
                fn (QueryExecuted $event) => $this->app->make(QueryRecorder::class)->record($event),
            );
        }
    }

    private function registerSandbox(): void
    {
        $this->app->singleton(ProcessRunner::class);

        $this->app->singleton(Git::class, function (Container $app): Git {
            $author = $this->settings()->section('sandbox.git_author');

            return new Git($app->make(ProcessRunner::class), [
                'name' => is_string($author['name'] ?? null) ? $author['name'] : 'Lazarus',
                'email' => is_string($author['email'] ?? null) ? $author['email'] : 'lazarus@localhost',
            ]);
        });

        $this->app->bind(GitWorkspace::class, fn (Container $app): GitWorkspace => new GitWorkspace(
            $app->make(Git::class),
            $this->project(),
            $this->settings()->nullableString('sandbox.worktrees_path') ?? sys_get_temp_dir().DIRECTORY_SEPARATOR.'lazarus-worktrees',
            $this->settings()->string('sandbox.branch_prefix', 'lazarus/fix-'),
            $this->settings()->bool('sandbox.require_clean_tree', true),
            $this->settings()->strings('sandbox.copy_files'),
        ));

        $this->app->bind(PathGuard::class, fn (): PathGuard => new PathGuard($this->writable()));
    }

    private function registerHealing(): void
    {
        $this->app->bind(WriteReproductionTest::class, fn (Container $app): WriteReproductionTest => new WriteReproductionTest(
            $app->make(LlmClient::class),
            $app->make(PathGuard::class),
            $app->make(Sandbox\TestHarness::class),
            $app->make(Dispatcher::class),
            max(1, $this->settings()->int('max_test_attempts', 3)),
        ));

        $this->app->bind(ProposePatch::class, fn (Container $app): ProposePatch => new ProposePatch(
            $app->make(LlmClient::class),
            $app->make(PathGuard::class),
            $app->make(Healing\EditApplier::class),
            $this->writable(),
        ));

        $this->app->bind(Publisher::class, fn (Container $app): Publisher => $this->settings()->string('publisher', 'patch') === 'github'
            ? $this->gitHubPublisher($app)
            : new PatchFilePublisher(
                $app->make(ReportRenderer::class),
                $this->settings()->nullableString('publishers.patch.path') ?? $this->app->storagePath('lazarus'),
            ));

        $this->app->bind(Healer::class, fn (Container $app): Healer => new Healer(
            $app->make(GitWorkspace::class),
            $app->make(WriteReproductionTest::class),
            $app->make(ProposePatch::class),
            $app->make(Healing\Steps\ApplyAndVerify::class),
            $app->make(Healing\Steps\Diagnose::class),
            $app->make(Publisher::class),
            $app->make(LlmClient::class),
            $app->make(TokenBudget::class),
            $app->make(Dispatcher::class),
            max(1, $this->settings()->int('max_patch_attempts', 2)),
        ));
    }

    private function gitHubPublisher(Container $app): GitHubPublisher
    {
        $settings = $this->settings();
        $workspace = $app->make(GitWorkspace::class);

        return new GitHubPublisher(
            http: $app->make(Http::class),
            renderer: $app->make(ReportRenderer::class),
            token: $settings->string('publishers.github.token'),
            repository: $settings->nullableString('publishers.github.repository') ?? GitHubPublisher::repositoryFromRemote($workspace->remoteUrl()) ?? '',
            base: $settings->nullableString('publishers.github.base') ?? $workspace->currentBranch(),
            apiUrl: $settings->string('publishers.github.api_url', 'https://api.github.com'),
            remoteUrl: $settings->nullableString('publishers.github.remote_url'),
            draft: $settings->bool('publishers.github.draft', true),
            labels: $settings->strings('publishers.github.labels'),
        );
    }

    private function currentRequest(): ?Request
    {
        if (! $this->app->bound('request')) {
            return null;
        }

        // Console commands and queue workers get a synthetic request without a route.
        $request = $this->app->make('request');

        return $request instanceof Request && $request->route() !== null ? $request : null;
    }

    /**
     * @return list<string>
     */
    private function writable(): array
    {
        return $this->settings()->strings('sandbox.writable', ['app/', 'tests/', 'routes/']);
    }

    private function settings(): Settings
    {
        return $this->app->make(Settings::class);
    }

    private function project(): Project
    {
        return $this->app->make(Project::class);
    }

    private function cache(): Cache
    {
        return $this->app->make(CacheFactory::class)->store($this->settings()->nullableString('budget.cache_store'));
    }
}
