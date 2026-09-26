<p align="center"><img src="art/banner.png" alt="Lazarus: self-healing Laravel apps" width="100%"></p>

# Lazarus

[![CI](https://github.com/mohammed-a-ashqar/laravel-lazarus/actions/workflows/ci.yml/badge.svg)](https://github.com/mohammed-a-ashqar/laravel-lazarus/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/mohammed-a-ashqar/laravel-lazarus)](https://packagist.org/packages/mohammed-a-ashqar/laravel-lazarus)
![PHP](https://img.shields.io/badge/PHP-8.2%20%7C%208.3%20%7C%208.4-777BB4)
![Laravel](https://img.shields.io/badge/Laravel-11%20%7C%2012-FF2D20)
![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-2A5EA7)

**Self-healing Laravel apps.** When your application throws in production, Lazarus writes a test
that reproduces the bug, proves it fails, finds a minimal patch, proves the test now passes *and*
the rest of your suite still does, then opens a pull request with the whole case. A human merges
it or doesn't. If any step can't be proven, nothing is published and you are told why.

A real run on the [demo app](examples/demo-app.md), healed by `openai/gpt-oss-120b` through Groq's
OpenAI-compatible API:

```text
$ php artisan lazarus:heal 1

  Lazarus is healing incident #1 with openai:openai/gpt-oss-120b
  DivisionByZeroError: Division by zero
  app/Services/InvoiceCalculator.php:22 · seen 2 time(s)

  ● Preparing an isolated worktree...
  ✔ Worktree .../lazarus-worktrees/495c3d2fa6-f56dfb on branch lazarus/fix-495c3d2fa6 1.1s
  ● Writing a test that reproduces the bug...
  ✔ Reproduced in tests/Feature/Lazarus/InvoiceAverageZeroQuantityTest.php (red after 6.8s, attempt 1) 12.3s
    │ DivisionByZeroError: Division by zero in .../app/Services/InvoiceCalculator.php:22
  ● Proposing a minimal patch...
  ✔ Guard against division by zero in averageUnitPrice (1 edit in app/Services/InvoiceCalculator.php) 2.6s
  ● Verifying the patch...
  ✔ Reproduction test is green (0.4s) and the full suite passes (0.4s) 1.3s
    │ OK (4 tests, 6 assertions)
  ● Writing the diagnosis...
  ✔ Division by zero when calculating average unit price with zero total quantity (confidence 93%) 4.5s
  ● Publishing the fix for review...
  ✔ Patch written to storage/lazarus/20260926-112052-495c3d2fa6.patch (apply with `git am ...`) 0.2s

  Cost ............................................................... 8,149 tokens · $0.0240
```

## Quick start (5 minutes)

You need a Laravel 11 or 12 app that is a git repository and has at least one passing test.

### 1. Install

```bash
composer require mohammed-a-ashqar/laravel-lazarus
php artisan vendor:publish --tag=lazarus-config
php artisan migrate
```

### 2. Connect an AI model

Lazarus needs a language model to write the test and the fix. Pick **one** option and paste its
lines at the end of your `.env` file.

| Option | Cost | Best for |
| --- | --- | --- |
| **A. Groq** | Free tier | Trying Lazarus today |
| **B. Ollama** | Free, runs on your computer | Keeping your code private |
| **C. Anthropic or OpenAI** | Paid, per use | Real projects |

**A. Groq (free).** Create a key at [console.groq.com/keys](https://console.groq.com/keys), then:

```dotenv
LAZARUS_LLM=openai
OPENAI_API_KEY=gsk_your_key_here
OPENAI_BASE_URL=https://api.groq.com/openai
LAZARUS_OPENAI_MODEL=openai/gpt-oss-120b
```

**B. Ollama (free, private).** Install [Ollama](https://ollama.com/download) and download a model
of at least 7B (about 4.7 GB; 3B models are too small to work):

```bash
ollama pull qwen2.5-coder:7b
```

```dotenv
LAZARUS_LLM=ollama
LAZARUS_OLLAMA_MODEL=qwen2.5-coder:7b
```

**C. Anthropic or OpenAI (paid).** Create a key at
[console.anthropic.com](https://console.anthropic.com/) or
[platform.openai.com/api-keys](https://platform.openai.com/api-keys), then use one of:

```dotenv
LAZARUS_LLM=anthropic
ANTHROPIC_API_KEY=sk-ant-your_key_here
```

```dotenv
LAZARUS_LLM=openai
OPENAI_API_KEY=sk-your_key_here
```

Never commit your `.env` file: it holds your key.

### 3. Check the setup

```bash
php artisan lazarus:doctor
```

Every line should say `OK`. A `WARN` on `Auto-heal` is normal. If something fails, see
[Common problems](#common-problems).

### 4. Find errors

**Your app already has errors in its logs?** Turn them into incidents in one command, without
opening a single page:

```bash
php artisan lazarus:scan              # reads storage/logs/*.log
php artisan lazarus:scan --since=7d   # only the last 7 days
php artisan lazarus:scan --dry-run    # preview, store nothing
```

It groups repeats of the same bug, skips errors raised entirely inside vendor code (a database
that was down, a wrong artisan option) because no patch in your app could fix them, and never
counts the same log line twice. A log copied from your production server works too: its paths
are mapped onto your project.

**No errors yet?** Make one on purpose. Add a route with a bug to `routes/web.php`:

```php
Route::get('/average', function () {
    $prices = [];

    return ['average' => array_sum($prices) / count($prices)]; // Division by zero
});
```

Commit it (Lazarus only works on committed code), then open the page once so the error is
captured:

```bash
git add -A && git commit -m "Add average page"
php artisan serve
```

Visit `http://127.0.0.1:8000/average`. You will see a `DivisionByZeroError`.

### 5. Heal it

```bash
php artisan lazarus:list      # shows the error as incident #1
php artisan lazarus:heal 1    # writes a test, fixes the bug and proves the fix
```

The fix is saved in `storage/lazarus/` as a `.patch` file, next to a `.md` report that explains
the bug. Read it, and if you agree, apply it:

```bash
git am storage/lazarus/<file>.patch
```

Your code is never changed until you apply the patch yourself. To get a pull request on GitHub
instead of a file, see [Configuration](#configuration).

### 6. Get notified (free, optional)

Hear about new errors, ready fixes and failed heals by email, Telegram, Slack or Discord. Add
any of these to `.env`:

```dotenv
LAZARUS_NOTIFY_MAIL=you@example.com                 # uses your app's mailer
LAZARUS_NOTIFY_TELEGRAM_TOKEN=123456:ABC...         # from @BotFather
LAZARUS_NOTIFY_TELEGRAM_CHAT=987654321              # your chat id
LAZARUS_NOTIFY_WEBHOOK=https://hooks.slack.com/...  # Slack or Discord incoming webhook
```

Then check it works:

```bash
php artisan lazarus:notify-test
```

A message looks like this:

```text
[My Shop] Fix ready for review

ErrorException: Attempt to read property "name" on null
Where: routes/web.php:305
Environment: production · incident #2 · seen 14 time(s)
Fix: Fall back to an empty brand name when a product has no brand
Confidence: 94%, test red then green, full suite passes
Review: https://github.com/acme/shop/pull/42
```

**Telegram in two minutes:** open [@BotFather](https://t.me/BotFather), send `/newbot` and copy
the token. Send any message to your new bot, then open
`https://api.telegram.org/bot<token>/getUpdates` and copy the number after `"chat":{"id":`.

### Common problems

| Message | What to do |
| --- | --- |
| `model ... does not exist` or `HTTP 404` | The model name is wrong. For Groq, check the list at [console.groq.com/docs/models](https://console.groq.com/docs/models). For Ollama, run `ollama list`. |
| `HTTP 401` | The API key is wrong or expired. Create a new one. |
| `The working tree has uncommitted changes` | Run `git add -A && git commit -m "wip"` first. |
| `Could not reproduce the bug in 3 attempts` | The model could not write a test for this bug. With Ollama, use a bigger model (7B or more). Some bugs, like those that depend on live data or an external API, cannot be reproduced by a test. |
| `lazarus:list` is empty | The error was not captured. Make sure `APP_ENV` is `local`, `staging` or `production`. |
| Your own tests fail | Lazarus needs your test suite to pass before it can prove a fix. Run `php artisan test` and fix it first. |

## The problem

An exception tracker tells you *that* something broke. Then a developer still has to read the
trace, guess the input, write a failing test, fix it and prove nothing else broke, usually for a
bug that turns out to be a one-line guard. AI tools can suggest that line, but a suggestion is not
evidence: nobody should merge a patch that was never shown to fix the failure it claims to fix.

Lazarus only ever hands you **evidence**: a test that fails on your current code with the same
exception production saw, the same test passing with the patch, and your full suite green.

## How it works

```mermaid
flowchart LR
    A[Exception reported] --> B[Fingerprint + redact]
    B --> C[(lazarus_incidents)]
    C -->|auto_heal or lazarus:heal| D[git worktree on lazarus/fix-*]
    D --> E[LLM writes a reproduction test]
    E --> F{Fails with the<br/>original exception?}
    F -- no, retry with output --> E
    F -- yes: RED --> G[LLM proposes search/replace edits]
    G --> H{Path guard +<br/>exact-once match}
    H -- blocked --> X[Record why, publish nothing]
    H -- ok --> I{Repro test passes<br/>and full suite passes?}
    I -- no, retry with output --> G
    I -- yes: GREEN --> J[Diagnosis + confidence]
    J --> K[Pull request or .patch file]
    F -. attempts exhausted .-> X
    I -. attempts exhausted .-> X
    K --> L[Worktree and branch removed]
    X --> L
```

1. **Capture.** A reportable callback turns every reported exception into an incident. The
   fingerprint is the exception class, the innermost *application* frame (vendor frames are
   skipped) and the message with ids, numbers, UUIDs and hashes stripped, so 10,000 occurrences
   of one bug are one row. The stack, code snippets, full source of the involved files, route,
   method, input and recent queries are stored, already redacted.
2. **Isolate.** Each heal runs in a throwaway `git worktree` on `lazarus/fix-{fingerprint}`. Your
   working tree is never touched, and Lazarus refuses to start if it has uncommitted changes
   (the worktree is built from `HEAD`, so a dirty tree would mean testing different code).
3. **Red.** The model returns a test file. Lazarus writes it and runs it with your own test
   runner. It only counts if it **fails** and the output mentions the original exception class
   or message. Otherwise the model gets the real output and tries again.
4. **Patch.** The model returns search/replace edits, never whole files. Every search block must
   match exactly once, every path must pass the path guard, and a patch may not edit tests.
5. **Green.** The reproduction test must now pass, then your full suite must pass. A patch that
   fails either is reverted and sent back with the output.
6. **Publish.** A diagnosis with a confidence score is written, the fix is committed on the
   branch and published as a draft pull request (or a `.patch` file). Lazarus never merges.

## The 60-second demo

<!-- GIF: docs/demo.gif, recorded with examples/demo-app.md -->
> **Demo GIF coming soon.** Break a fresh Laravel app, run `php artisan lazarus:heal 1`, watch the
> red run, the patch and the green suite scroll by, then open the pull request that just arrived.

The demo will show a fresh Laravel app with an `InvoiceCalculator` that divides by zero when an
invoice only contains free samples. Visiting the page returns a 500; `lazarus:list` shows the
incident; `lazarus:heal 1` reproduces it, patches it and verifies it live; the pull request lands
on GitHub with the diagnosis, the test, the diff and both test runs. Every step to reproduce it is
in [`examples/demo-app.md`](examples/demo-app.md).

## Requirements

- PHP 8.2+ and Laravel 11 or 12
- `git` on the machine that runs heals (2.31+ for token-safe pushes)
- A Pest or PHPUnit test suite
- An LLM: Anthropic, OpenAI or a local Ollama model

## Installation

```bash
composer require mohammed-a-ashqar/laravel-lazarus
php artisan vendor:publish --tag=lazarus-config
php artisan migrate
php artisan lazarus:doctor
```

The service provider is auto-discovered and starts capturing immediately. Healing starts when you
ask for it (or when you turn on `auto_heal`).

## Configuration

Everything lives in `config/lazarus.php`; the common settings come from the environment:

```dotenv
LAZARUS_LLM=anthropic              # anthropic, openai, ollama
ANTHROPIC_API_KEY=sk-ant-...
LAZARUS_ANTHROPIC_MODEL=claude-sonnet-5

LAZARUS_PUBLISHER=github           # or "patch" (default, no credentials needed)
LAZARUS_GITHUB_TOKEN=github_pat_...   # contents: write, pull requests: write
LAZARUS_GITHUB_REPOSITORY=acme/shop   # optional, read from the origin remote otherwise

LAZARUS_AUTO_HEAL=false            # queue a heal as soon as a new incident is captured
LAZARUS_DAILY_TOKENS=300000
LAZARUS_DAILY_COST=5.00
```

| Option | Default | What it does |
| --- | --- | --- |
| `environments` | production, staging, local | Where exceptions are captured |
| `ignore` | auth, validation, 404/HTTP, CSRF | Exception classes that are never incidents |
| `auto_heal` | `false` | Queue `HealIncident` for new incidents |
| `cooldown_minutes` / `max_heals_per_day` | 60 / 10 | Throttle automatic heals |
| `max_test_attempts` / `max_patch_attempts` | 3 / 2 | How many tries each proof gets |
| `sandbox.writable` | `app/`, `tests/`, `routes/` | Where the model may write (the denylist still wins) |
| `sandbox.copy_files` | `.env`, `.env.testing` | Untracked files your suite needs in the worktree |
| `testing.command` / `suite_command` | auto (Pest or PHPUnit) | Override, e.g. `['{php}', '{vendor}/bin/pest', '--parallel']` |
| `testing.test_timeout` / `suite_timeout` | 120 / 900 s | Hard process timeouts |
| `publishers.github.draft` | `true` | Open pull requests as drafts |

## Commands

| Command | Purpose |
| --- | --- |
| `lazarus:list [--status=] [--limit=]` | Incidents, occurrences, status and outcome (PR link or failure reason) |
| `lazarus:heal {incident} [--queue]` | Heal one incident with live, step-by-step output |
| `lazarus:doctor` | Checks git, worktree creation, the test runner, the database, the API key, the GitHub token and the remaining budget |
| `lazarus:ignore {incident}` | Never heal this incident; occurrences are still counted |
| `lazarus:scan [paths] [--since=] [--dry-run]` | Turn errors already in your log files into incidents |
| `lazarus:notify-test` | Send a test message to every configured notification channel |

`{incident}` is the numeric id or any unique prefix of the fingerprint.

## Safety model

### Why it never auto-merges

Lazarus proves that a patch fixes *the failure it reproduced* and breaks *no existing test*. It
cannot prove that the reproduction matches what your users meant to do, that your suite covers
everything that matters, or that the fix is the one your team wants. That judgement is a code
review, so the output is always a pull request (a draft by default) or a patch file. There is no
merge code path in the package.

### Redaction

Every stored context and every outgoing LLM request passes through the `Redactor`, including the
test output that is fed back to the model:

- values of sensitive keys: `password`, `token`, `secret`, `api_key`, `authorization`, `cookie`,
  `client_secret`, `private_key`, `card_number`, `cvv`, `ssn` and more (substring match, any case)
- every secret value from your `.env` file wherever it appears (configuration values such as
  `APP_NAME` or `DB_CONNECTION` are deliberately left alone)
- bearer tokens, JWTs and well-known key formats (Anthropic, OpenAI, GitHub, AWS, Slack, Stripe)
- `Authorization` and `Cookie` headers, `?token=` style query parameters
- email addresses and card numbers that pass the Luhn check

### Path guard

The model can only create or edit files under `sandbox.writable`. A hard denylist that
configuration cannot override always wins: `.env*`, `.git/`, `vendor/`, `node_modules/`, `config/`,
`bootstrap/`, `storage/`, `public/`, `database/migrations/`, CI workflows, `composer.*`,
`package*.json`, `phpunit.xml*`, `tests/Pest.php` and `tests/TestCase.php`. Absolute paths and `..`
are rejected, and writes through symlinks that leave the worktree are refused. A patch that trips
the guard ends the heal immediately; it is not retried. Patches may not edit tests at all, and
the reproduction test must be a new file, so the model cannot make a suite green by weakening it.

### Test processes

Tests the model wrote are code you did not write, so they run with a scrubbed environment: only
what PHP and git need to start (`PATH`, `HOME`, `TEMP`...) is inherited. API keys, the GitHub
token and, most importantly, your production `DB_*` variables never reach them. Every process has
a hard timeout. The GitHub token reaches git through `GIT_CONFIG_*` environment variables, never
the command line or the remote URL.

### Budget

`TokenBudget` enforces a daily token and dollar ceiling across all heals. It is checked before
every model call; once it is exhausted, heals fail with the reason instead of spending more.
Tokens and cost are stored per incident and shown on every pull request.

## LLM drivers

| Driver | Default model | Cost | Notes |
| --- | --- | --- | --- |
| `anthropic` | `claude-sonnet-5` | $2 / $10 per million tokens | Messages API through Laravel's HTTP client |
| `openai` | `gpt-5` | $1.25 / $10 per million tokens | Chat Completions in JSON mode; any compatible `base_url` |
| `ollama` | `qwen2.5-coder` | **free** | **Runs locally: your code never leaves the machine** |
| `fake` | - | free | Scripted responses for tests |

Prices only feed the budget and the cost line on the pull request; adjust `pricing` if your
contract differs.

**Ollama is the private option.** Point Lazarus at a local model and the whole pipeline, source
code, stack traces and all, stays on your hardware, at no cost:

```bash
ollama pull qwen2.5-coder
```

```dotenv
LAZARUS_LLM=ollama
OLLAMA_BASE_URL=http://localhost:11434
LAZARUS_OLLAMA_MODEL=qwen2.5-coder
```

Smaller local models fail more proofs than hosted ones; when they do, Lazarus simply publishes
nothing. In testing, `qwen2.5-coder:3b` could not write a valid reproduction for the demo bug, so
use a 7B model or larger. The `openai` driver also works with any OpenAI-compatible API through
`OPENAI_BASE_URL` (for example `https://api.groq.com/openai`, which healed the demo above). Every response, from any driver, must be strict JSON validated into a readonly DTO; an
invalid answer is sent back with the exact validation error. Add your own driver with
`app(LlmManager::class)->extend('name', fn () => new MyDriver)`.

## Events

| Event | When |
| --- | --- |
| `IncidentCaptured` | An exception was recorded (`isNew` tells a first occurrence from a repeat, `fromLog` a scanned one) |
| `ReproductionConfirmed` | A test fails with the original exception (red) |
| `ReproductionFailed` | No attempt produced a valid reproduction |
| `FixVerified` | The test is green and the full suite passes |
| `PullRequestOpened` | The pull request exists; carries the URL and the full report |
| `FixPublished` | A verified fix is ready as a pull request or a patch file |
| `HealingFailed` | A heal stopped; carries the stage and the reason |
| `HealingStepStarted` / `HealingStepCompleted` | Progress for each pipeline step (drives `lazarus:heal`) |

```php
Event::listen(PullRequestOpened::class, fn ($event) => Slack::send("Lazarus fixed {$event->incident->shortClass()}: {$event->url}"));
```

## Testing

```bash
composer test       # Pest
composer analyse    # PHPStan, level max
composer lint:check # Pint
```

The suite has unit tests for the fingerprinter, the redactor, the path guard, JSON validation,
search/replace edits and the budget, and an **end-to-end suite that runs the real pipeline**: it
creates a throwaway git repository holding a small project with a real divide-by-zero bug and its
own Pest suite, triggers the bug in a separate PHP process, then heals it with a real worktree,
real test runs, a real commit and a real patch file that `git am` applies. Only the model is
scripted. The negative cases prove that a test that doesn't reproduce is rejected, a patch that
doesn't fix (or breaks the suite) is rejected, a patch touching `.env` or the tests is blocked, a
dirty tree is refused, and that no secret from the fixture's `.env` ever reaches the model.
Another end-to-end test pushes to a local bare remote and checks the pull request request body.

On Windows with XAMPP, `pdo_sqlite` is shipped but disabled; run
`php -d extension=pdo_sqlite vendor/bin/pest`.

## Limitations

Lazarus is honest about what it can prove, and these are the cases where it usually can't:

- **Only bugs a test can reproduce.** Failures that depend on production data, third-party APIs,
  timing, queues or infrastructure are usually not reproducible from the context it has. Those
  heals fail with a recorded reason; they don't produce guesses.
- **Your test suite is the safety net.** "The full suite passes" is only as strong as the suite.
  That is the main reason a human reviews every fix.
- **Model-written tests run on the healing machine.** The environment is scrubbed and paths are
  guarded, but the code still executes. Run heals where you run CI (a staging box or a container),
  not on a production web server, and make sure `phpunit.xml` pins a test database.
- **The worktree reuses your `vendor/`.** Instead of a slow `composer install`, an
  `auto_prepend_file` overlay maps your PSR-4 namespaces onto the worktree. Composer `files`
  autoloads and classmap-only code still load from the main checkout, and a patch cannot change
  dependencies. Untracked build output (such as `public/build` for `@vite`) is not in the
  worktree unless you add it to `copy_files`.
- **Fingerprints include the line number,** so an unrelated edit above the throw site creates a
  new incident after the next deploy.
- **Pest and PHPUnit only,** and GitHub only for pull requests (the patch publisher works anywhere).

## License

Lazarus is open-source software licensed under the [MIT license](LICENSE).
