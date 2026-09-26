# Contributing

Thanks for helping. Bug reports, fixes and new LLM drivers are all welcome.

## Setup

```bash
git clone https://github.com/mohammed-a-ashqar/laravel-lazarus.git
cd laravel-lazarus
composer install
```

## Before you open a pull request

```bash
composer test       # Pest
composer analyse    # PHPStan, level max
composer lint:check # Pint
```

All three must pass; CI runs them on PHP 8.2, 8.3 and 8.4.

- Add a test for every change in behaviour. Pipeline changes should also be covered by the
  end-to-end suite in `tests/Feature`.
- Keep the safety model intact: nothing may merge, write outside the worktree or send unredacted
  context to a model.
- Describe the change in `CHANGELOG.md` under `[Unreleased]`.

Security issues follow [SECURITY.md](SECURITY.md), not public issues.
