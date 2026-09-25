# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Exception capture with fingerprinting, redacted context and throttled auto-heal.
- Healing pipeline: reproduction test (red), search/replace patch, verification (green and full
  suite), diagnosis with confidence.
- Isolated git worktrees with an autoload overlay, a path guard with a hard denylist and a
  scrubbed, time-limited process runner.
- Anthropic, OpenAI, Ollama and fake LLM drivers with strict JSON validation and a daily budget.
- GitHub pull request and patch file publishers.
- `lazarus:list`, `lazarus:heal`, `lazarus:doctor` and `lazarus:ignore` commands.
