<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Capture
    |--------------------------------------------------------------------------
    |
    | Lazarus records every reported exception as an incident, grouped by a
    | fingerprint. Nothing leaves the application at this stage: capturing
    | only writes a redacted row to the lazarus_incidents table.
    |
    */

    'enabled' => (bool) env('LAZARUS_ENABLED', true),

    'environments' => ['production', 'staging', 'local'],

    'ignore' => [
        Illuminate\Auth\AuthenticationException::class,
        Illuminate\Auth\Access\AuthorizationException::class,
        Illuminate\Validation\ValidationException::class,
        Illuminate\Database\Eloquent\ModelNotFoundException::class,
        Illuminate\Session\TokenMismatchException::class,
        Symfony\Component\HttpKernel\Exception\HttpException::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Healing
    |--------------------------------------------------------------------------
    |
    | With auto_heal enabled a queued job starts healing a new incident right
    | away. Otherwise run `php artisan lazarus:heal {incident}` yourself.
    |
    */

    'auto_heal' => (bool) env('LAZARUS_AUTO_HEAL', false),

    'queue' => env('LAZARUS_QUEUE'),

    'cooldown_minutes' => 60,

    'max_heals_per_day' => 10,

    'max_test_attempts' => 3,

    'max_patch_attempts' => 2,

    // The root of the application to heal. Null means base_path().
    'project_path' => env('LAZARUS_PROJECT_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Budget
    |--------------------------------------------------------------------------
    |
    | A hard daily ceiling across every heal. When either limit is reached the
    | next LLM call is refused and the incident is marked failed.
    |
    */

    'budget' => [
        'daily_tokens' => (int) env('LAZARUS_DAILY_TOKENS', 300_000),
        'daily_cost' => (float) env('LAZARUS_DAILY_COST', 5.00),
        'cache_store' => env('LAZARUS_CACHE_STORE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Context
    |--------------------------------------------------------------------------
    */

    'context' => [
        'max_frames' => 8,
        'snippet_lines' => 12,
        'max_file_bytes' => 24_000,
        'max_total_bytes' => 80_000,
        'record_queries' => true,
        'max_queries' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redaction
    |--------------------------------------------------------------------------
    |
    | Applied to everything that is stored or sent to a model. Values of these
    | keys are replaced, and so is any value from your .env file that appears
    | anywhere in the text.
    |
    */

    'redaction' => [
        'keys' => [
            'password', 'password_confirmation', 'passwd', 'secret', 'token', 'api_key', 'apikey',
            'access_token', 'refresh_token', 'client_secret', 'authorization', 'cookie', 'set-cookie',
            'private_key', 'credit_card', 'card_number', 'cvv', 'cvc', 'ssn', 'x-api-key',
        ],
        'redact_env_values' => true,
        'min_env_value_length' => 6,
    ],

    /*
    |--------------------------------------------------------------------------
    | LLM
    |--------------------------------------------------------------------------
    |
    | Prices are in USD per million tokens and are only used for the budget
    | and for the cost shown on the pull request. Ollama is always free.
    |
    */

    'llm' => [
        'driver' => env('LAZARUS_LLM', 'anthropic'),
        'max_invalid_responses' => 2,

        'drivers' => [
            'anthropic' => [
                'api_key' => env('ANTHROPIC_API_KEY'),
                'model' => env('LAZARUS_ANTHROPIC_MODEL', 'claude-sonnet-5'),
                'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
                'max_tokens' => 16_000,
                'timeout' => 180,
                'pricing' => ['input' => 2.00, 'output' => 10.00],
            ],

            'openai' => [
                'api_key' => env('OPENAI_API_KEY'),
                'model' => env('LAZARUS_OPENAI_MODEL', 'gpt-5'),
                'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
                'max_tokens' => 16_000,
                'timeout' => 180,
                'pricing' => ['input' => 1.25, 'output' => 10.00],
            ],

            'ollama' => [
                'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
                'model' => env('LAZARUS_OLLAMA_MODEL', 'qwen2.5-coder'),
                'timeout' => 600,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sandbox
    |--------------------------------------------------------------------------
    |
    | Every heal runs in a throwaway git worktree on its own branch. Only paths
    | matching `writable` can be created or edited, and the hard denylist in
    | PathGuard (.env*, vendor/, config/, .git/, composer.*, migrations, CI
    | workflows, phpunit.xml) always wins.
    |
    */

    'sandbox' => [
        'worktrees_path' => env('LAZARUS_WORKTREES_PATH'),
        'branch_prefix' => 'lazarus/fix-',
        'writable' => ['app/', 'tests/', 'routes/'],
        'require_clean_tree' => true,
        'copy_files' => ['.env', '.env.testing'],
        'git_author' => ['name' => 'Lazarus', 'email' => 'lazarus@localhost'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tests
    |--------------------------------------------------------------------------
    |
    | Commands run inside the worktree. `{php}` is the current PHP binary and
    | `{vendor}` is the application's vendor directory, which the worktree
    | reuses through an autoload overlay instead of a fresh composer install.
    | Null picks Pest or PHPUnit automatically.
    |
    */

    'testing' => [
        'command' => null,
        'suite_command' => null,
        'vendor_path' => env('LAZARUS_VENDOR_PATH'),
        'test_timeout' => 120,
        'suite_timeout' => 900,
    ],

    /*
    |--------------------------------------------------------------------------
    | Publishing
    |--------------------------------------------------------------------------
    |
    | "patch" writes a .patch file and a Markdown report to storage/lazarus
    | and needs no credentials. "github" pushes the branch and opens a pull
    | request. Lazarus never merges anything.
    |
    */

    'publisher' => env('LAZARUS_PUBLISHER', 'patch'),

    'publishers' => [
        'patch' => [
            'path' => env('LAZARUS_PATCH_PATH'),
        ],

        'github' => [
            'token' => env('LAZARUS_GITHUB_TOKEN', env('GITHUB_TOKEN')),
            'repository' => env('LAZARUS_GITHUB_REPOSITORY'),
            'base' => env('LAZARUS_GITHUB_BASE'),
            'remote_url' => null,
            'api_url' => 'https://api.github.com',
            'draft' => true,
            'labels' => ['lazarus'],
        ],
    ],

];
