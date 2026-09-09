# Changelog

All notable changes to `laravel-github-readme` will be documented in this file.

## v2.0.1 - 2026-09-09

### Fixed

- Root-relative hrefs and asset srcs (e.g. `/curriculum`) were left untouched by `rewriteRelativeLinks()` / `rewriteRelativeAssets()`, pointing at the consuming site's own domain instead of the repo. Fixes #6.

## v2.0.0 - 2026-06-21

**Full Changelog**: https://github.com/jeffersongoncalves/laravel-github-readme/compare/v1.0.1...v2.0.0

## v1.0.1 - 2026-06-20

chore: ignore the .phpunit.cache directory.

## v1.0.0 - 2026-06-20

Initial release.
