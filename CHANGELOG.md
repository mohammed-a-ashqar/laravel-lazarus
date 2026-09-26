# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [0.2.0] - 2026-09-26

### Added

- `lazarus:scan` turns errors already in your log files into incidents, without opening a page.
  It groups repeats, skips errors raised entirely inside vendor code, never counts a log line
  twice, maps paths from a production log onto the project and supports `--since` and
  `--dry-run`. Scanned errors never start a heal on their own.
- Free notifications by email, Telegram and Slack or Discord webhooks for new errors, ready fixes
  and failed heals, with `lazarus:notify-test` and a new line in `lazarus:doctor`. A failing
  channel never breaks the app or a heal, and the Telegram token never appears in errors.
- A `FixPublished` event for every published fix (pull request or patch file), and a `fromLog`
  flag on `IncidentCaptured`.

## [0.1.2] - 2026-09-26

### Fixed

- A rate limit (HTTP 429) or an overloaded API (503, 529) no longer fails the heal. Lazarus waits
  as long as the API asks (`Retry-After`, or Groq's "try again in 14.43s") and retries up to four
  times. Errors that will not go away, such as a wrong API key, still fail at once.

## [0.1.1] - 2026-09-26

### Fixed

- The incidents migration failed on MySQL and MariaDB (`1067 Invalid default value for
  last_seen_at`) because of two non-null `timestamp` columns. They are now `datetime` columns.
- MySQL 8 reordered the keys of the stored incident context because the column was `json`. It
  is now a text column, so the context is stored exactly as captured on every database.
- CI now runs the full suite on MariaDB 10.6, MySQL 8.4 and PostgreSQL 16 as well as SQLite.

## [0.1.0] - 2026-09-26

### Added

- Exception capture with fingerprinting, redacted context and throttled auto-heal.
- Healing pipeline: reproduction test (red), search/replace patch, verification (green and full
  suite), diagnosis with confidence.
- Isolated git worktrees with an autoload overlay, a path guard with a hard denylist and a
  scrubbed, time-limited process runner.
- Anthropic, OpenAI, Ollama and fake LLM drivers with strict JSON validation and a daily budget.
- GitHub pull request and patch file publishers.
- `lazarus:list`, `lazarus:heal`, `lazarus:doctor` and `lazarus:ignore` commands.
- `SECURITY.md`, `CONTRIBUTING.md` and issue templates.

- Reproduction tests must assert the correct behaviour; a test that only triggers the bug is
  rejected.
- Markdown fences around generated test files are stripped, and PHPUnit projects are told not to
  use Pest functions. Parse errors and missing classes get specific feedback, which helps small
  local models.

[0.2.0]: https://github.com/mohammed-a-ashqar/laravel-lazarus/releases/tag/v0.2.0
[0.1.2]: https://github.com/mohammed-a-ashqar/laravel-lazarus/releases/tag/v0.1.2
[0.1.1]: https://github.com/mohammed-a-ashqar/laravel-lazarus/releases/tag/v0.1.1
[0.1.0]: https://github.com/mohammed-a-ashqar/laravel-lazarus/releases/tag/v0.1.0
