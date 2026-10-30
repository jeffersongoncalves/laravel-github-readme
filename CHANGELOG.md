# Changelog

All notable changes to `laravel-github-readme` will be documented in this file.

## v2.1.2 - 2026-10-30

### Fixed

- Log non-2xx GitHub API responses (404 bad ref, 401 bad credentials, 403 rate limit, 5xx) in `fetchConditional()` and `defaultBranch()` with repo, ref, status, GitHub's `message` and `X-RateLimit-Remaining`. Previously these failed silently (#26, #27).
- Logging failures no longer interrupt the stale-cache fallback.

**Full Changelog**: https://github.com/jeffersongoncalves/laravel-github-readme/compare/v2.1.1...v2.1.2

## v2.1.1 - 2026-10-28

Tests now load the package migrations with jeffersongoncalves/pest-plugin-database-migrations, in the order declared by the service provider, instead of copying the .php.stub files by hand. No runtime changes.

## v2.1.0 - 2026-10-24

### What's Changed

* ci: standardize update-changelog workflow (master) by @jeffersongoncalves in https://github.com/jeffersongoncalves/laravel-github-readme/pull/12
* ci: standardize dependabot config by @jeffersongoncalves in https://github.com/jeffersongoncalves/laravel-github-readme/pull/13
* ci: standardize tests workflow (master) by @jeffersongoncalves in https://github.com/jeffersongoncalves/laravel-github-readme/pull/14
* ci: standardize tests workflow (master) by @jeffersongoncalves in https://github.com/jeffersongoncalves/laravel-github-readme/pull/15
* ci: auto-merge dependabot github-actions minor/patch by @jeffersongoncalves in https://github.com/jeffersongoncalves/laravel-github-readme/pull/16
* Point branch references to main by @jeffersongoncalves in https://github.com/jeffersongoncalves/laravel-github-readme/pull/17
* ci: let the Pint workflow push its fixes by @jeffersongoncalves in https://github.com/jeffersongoncalves/laravel-github-readme/pull/18
* build(deps): Bump shivammathur/setup-php from 2.37.2 to 2.40.0 in the actions-deps group by @dependabot[bot] in https://github.com/jeffersongoncalves/laravel-github-readme/pull/19
* feat: serve stale README and revalidate in background by @jeffersongoncalves in https://github.com/jeffersongoncalves/laravel-github-readme/pull/22
* fix: rewrite single-quoted and unquoted href/src attributes by @jeffersongoncalves in https://github.com/jeffersongoncalves/laravel-github-readme/pull/23

**Full Changelog**: https://github.com/jeffersongoncalves/laravel-github-readme/compare/v2.0.3...v2.1.0

## v2.0.3 - 2026-09-19

### What's Changed

* fix: catch ConnectionException on GitHub API timeout by @jeffersongoncalves in https://github.com/jeffersongoncalves/laravel-github-readme/pull/11

**Full Changelog**: https://github.com/jeffersongoncalves/laravel-github-readme/compare/v2.0.2...v2.0.3

## v2.0.2 - 2026-09-14

Fix race condition on ReadmeCache upsert under concurrent requests (#8, #9).

## v2.0.1 - 2026-09-09

### Fixed

- Root-relative hrefs and asset srcs (e.g. `/curriculum`) were left untouched by `rewriteRelativeLinks()` / `rewriteRelativeAssets()`, pointing at the consuming site's own domain instead of the repo. Fixes #6.

## v2.0.0 - 2026-06-21

**Full Changelog**: https://github.com/jeffersongoncalves/laravel-github-readme/compare/v1.0.1...v2.0.0

## v1.0.1 - 2026-06-20

chore: ignore the .phpunit.cache directory.

## v1.0.0 - 2026-06-20

Initial release.
