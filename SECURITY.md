# Security Policy

Lazarus runs code written by a language model and handles stack traces that may contain secrets,
so security reports are taken seriously.

## Supported versions

Only the latest release receives security fixes.

## Reporting a vulnerability

Please **do not open a public issue**. Report it privately through
[GitHub Security Advisories](https://github.com/mohammed-a-ashqar/laravel-lazarus/security/advisories/new)
or by email to mohammedname2002@gmail.com.

Include the version, your configuration (without secrets) and the steps to reproduce. You will get
an answer within 7 days, and a fix or a mitigation plan as soon as the issue is confirmed.

Areas of particular interest:

- a secret that escapes the `Redactor` and reaches the model, the database or a pull request
- a write that escapes the path guard or the worktree
- environment variables that leak into model-written test processes
- anything that could merge or push to a branch other than `lazarus/fix-*`
