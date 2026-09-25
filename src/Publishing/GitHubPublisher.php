<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Publishing;

use Alashqar\Lazarus\Healing\Data\HealingReport;
use Alashqar\Lazarus\Sandbox\SandboxException;
use Alashqar\Lazarus\Sandbox\Worktree;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;

/**
 * Pushes the fix branch and opens a (draft, by default) pull request. It never merges.
 *
 * The token reaches git through GIT_CONFIG_* environment variables, never through the
 * command line or the remote URL, so it cannot leak into process lists, logs or .git/config.
 */
final readonly class GitHubPublisher implements Publisher
{
    /**
     * @param  list<string>  $labels
     */
    public function __construct(
        private Factory $http,
        private ReportRenderer $renderer,
        private string $token,
        private string $repository,
        private string $base,
        private string $apiUrl = 'https://api.github.com',
        private ?string $remoteUrl = null,
        private bool $draft = true,
        private array $labels = [],
    ) {}

    public function publish(HealingReport $report, Worktree $worktree): PublishResult
    {
        if ($this->token === '' || $this->repository === '') {
            throw new SandboxException('The GitHub publisher needs a token and an owner/repository.');
        }

        $worktree->push($this->remote(), [
            'GIT_CONFIG_COUNT' => '1',
            'GIT_CONFIG_KEY_0' => 'http.extraHeader',
            'GIT_CONFIG_VALUE_0' => 'Authorization: Basic '.base64_encode('x-access-token:'.$this->token),
            'GIT_TERMINAL_PROMPT' => '0',
        ]);

        $response = $this->client()->post("/repos/{$this->repository}/pulls", [
            'title' => $report->title(),
            'head' => $report->branch,
            'base' => $this->base,
            'body' => $this->renderer->render($report),
            'draft' => $this->draft,
            'maintainer_can_modify' => true,
        ]);

        try {
            $response->throw();
        } catch (RequestException $exception) {
            $message = $response->json('message');

            throw new SandboxException(sprintf('GitHub refused the pull request (HTTP %d): %s', $response->status(), is_string($message) ? $message : 'no details'), previous: $exception);
        }

        $url = $response->json('html_url');
        $number = $response->json('number');

        if ($this->labels !== [] && is_int($number)) {
            // Labels are a convenience; a missing label permission must not fail the heal.
            $this->client()->post("/repos/{$this->repository}/issues/{$number}/labels", ['labels' => $this->labels]);
        }

        return new PublishResult(
            description: 'Pull request opened: '.(is_string($url) ? $url : '#'.(is_int($number) ? $number : '?')),
            url: is_string($url) ? $url : null,
        );
    }

    public function name(): string
    {
        return 'github';
    }

    /**
     * "owner/repo" from a GitHub remote such as git@github.com:owner/repo.git.
     */
    public static function repositoryFromRemote(?string $remote): ?string
    {
        if ($remote !== null && preg_match('#github\.com[:/]+([\w.-]+/[\w.-]+?)(?:\.git)?/?$#', $remote, $match)) {
            return $match[1];
        }

        return null;
    }

    private function remote(): string
    {
        return $this->remoteUrl ?? "https://github.com/{$this->repository}.git";
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return $this->http
            ->baseUrl(rtrim($this->apiUrl, '/'))
            ->withToken($this->token)
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->accept('application/vnd.github+json')
            ->timeout(30);
    }
}
