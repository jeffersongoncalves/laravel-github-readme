---
name: github-readme-development
description: Development guide for laravel-github-readme, a package that fetches, renders and disk-caches GitHub repository READMEs using conditional ETag requests, a freshness window, 304 reuse, stale fallback, and relative asset/link rewriting.
---

# GitHub Readme Development Skill

## When to use this skill

- When developing or extending the laravel-github-readme package
- When changing the README caching flow (freshness window, conditional requests, stale fallback)
- When modifying the HTML post-processing helpers (relative asset/link rewriting, external links, lazy images, table wrapping)
- When changing the markdown rendering pipeline or the pluggable renderer
- When touching the `ReadmeCache` model or its migration
- When writing tests with `Http::fake` + `Storage::fake`

## Setup

### Requirements
- PHP 8.2+
- Laravel 11, 12, or 13
- `spatie/laravel-package-tools` ^1.14
- `league/commonmark` ^2.4

### Installation

```bash
composer require jeffersongoncalves/laravel-github-readme
php artisan vendor:publish --tag="laravel-github-readme-migrations"
php artisan migrate
```

## Package Structure

```
src/
  GitHubReadme.php                  # Core: fetchHtml() + caching + HTML helpers
  GitHubReadmeServiceProvider.php   # Spatie PackageServiceProvider (config + migration)
  Models/
    ReadmeCache.php                 # Eloquent model for the github_readme_cache table
config/
  github-readme.php                 # disk, check_interval_minutes, token, user_agent, timeout, renderer
database/
  migrations/
    create_github_readme_cache_table.php.stub
```

## Service Provider

Registered via Spatie's `PackageServiceProvider`:

```php
public function configurePackage(Package $package): void
{
    $package
        ->name('laravel-github-readme')
        ->hasConfigFile()
        ->hasMigration('create_github_readme_cache_table');
}
```

`hasMigration('create_github_readme_cache_table')` looks for
`database/migrations/create_github_readme_cache_table.php.stub` and publishes it
under the `laravel-github-readme-migrations` tag.

## Caching Flow (GitHubReadme::fetchHtml)

1. Resolve the `owner/repo` slug from the GitHub URL (`repoFromUrl`). Bail with
   `null` for non-GitHub URLs.
2. Look up (or build) the `ReadmeCache` row keyed by `repo` + `ref`
   (`ref` defaults to the string `default`).
3. **Freshness window** — if a file exists on disk and `checked_at` is within
   `check_interval_minutes`, serve the file with **no** GitHub call.
4. **Conditional request** — otherwise issue a conditional request. `If-None-Match`
   is only sent when the cached file actually exists on disk (so a 304 can never
   leave us with no body and no file).
   - `304 Not Modified` → bump `checked_at`, return the cached file.
   - `200` → re-render markdown, rewrite relative assets/links, write the file,
     update the row (`etag`, `default_branch`, `html_path`, `fetched_at`, `checked_at`).
   - error → return the stale cached file if present, else `null`.
5. The disk write is best-effort: a failed write is logged but the freshly
   rendered HTML is still returned.

## HTML Post-processing Helpers (all public static, all pure)

| Helper | Purpose |
| --- | --- |
| `repoFromUrl(?string $url)` | Extract `owner/repo` from a GitHub URL |
| `rewriteRelativeAssets($md, $repo, $ref)` | Relative image src → `raw.githubusercontent.com` (markdown + `<img>`) |
| `rewriteRelativeLinks($html, $repo, $ref)` | Relative anchor href → GitHub `blob` URL |
| `markExternalLinks($html, $selfHost)` | Off-site anchors get `target="_blank"` + `rel="nofollow noopener"` |
| `lazyloadImages($html)` | `decoding="async"` on all images, `loading="lazy"` on all but the first |
| `wrapTables($html)` | Wrap each `<table>` in `<div class="md-table-scroll">` |

`fetchHtml()` only applies `rewriteRelativeAssets` + `rewriteRelativeLinks`
internally. `markExternalLinks`, `lazyloadImages` and `wrapTables` are left for
the consumer to chain at display time.

## Markdown Rendering

Rendering goes through `config('github-readme.renderer')` when it is a callable;
otherwise the internal `defaultRenderer()` uses League\CommonMark with:

- `CommonMarkCoreExtension`
- `GithubFlavoredMarkdownExtension`
- `HeadingPermalinkExtension` (symbol `#`, class `md-anchor`)
- `html_input => 'allow'` (raw HTML kept — see the security note)

### Security: rendered HTML is UNTRUSTED

The output keeps raw HTML from third-party READMEs. It MUST be sanitized before
display (e.g. `jeffersongoncalves/laravel-html-sanitizer`). This package never
sanitizes for you.

## Configuration Keys

```php
'disk' => env('GITHUB_README_DISK', 'local'),
'check_interval_minutes' => (int) env('GITHUB_README_CHECK_INTERVAL', 10),
'token' => env('GITHUB_TOKEN', env('GITHUB_README_TOKEN')),
'user_agent' => env('GITHUB_README_USER_AGENT', 'laravel-github-readme'),
'timeout' => (int) env('GITHUB_README_TIMEOUT', 8),
'renderer' => null,
```

The token falls back to `config('services.github.token')` when the package key
is null.

## Testing Patterns

Tests use `Http::fake` + `Storage::fake` against the `local` disk. Travel the
clock to step outside the freshness window so a conditional request is made.

```php
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use JeffersonGoncalves\GitHubReadme\GitHubReadme;
use JeffersonGoncalves\GitHubReadme\Models\ReadmeCache;

it('renders and stores on first fetch', function () {
    Storage::fake('local');
    Http::fake([
        'api.github.com/repos/owner/repo/readme*' => Http::response('# Hi', 200, ['ETag' => '"e1"']),
    ]);

    $html = GitHubReadme::fetchHtml('https://github.com/owner/repo', 'main');

    expect($html)->toContain('Hi');
    expect(ReadmeCache::where('repo', 'owner/repo')->exists())->toBeTrue();
    Http::assertSentCount(1);
});

it('reuses the cache on a 304', function () {
    Storage::fake('local');
    Http::fakeSequence('api.github.com/*')
        ->push('# Hi', 200, ['ETag' => '"e1"'])
        ->push('', 304);

    $first = GitHubReadme::fetchHtml('https://github.com/owner/repo', 'main');
    $this->travel(11)->minutes();
    $second = GitHubReadme::fetchHtml('https://github.com/owner/repo', 'main');

    expect($second)->toBe($first);
    Http::assertSentCount(2);
});
```

### Running Tests

```bash
vendor/bin/pest          # run tests
vendor/bin/pest --coverage
vendor/bin/phpstan analyse
vendor/bin/pint
```

## Notes / Gotchas

- Passing an explicit `$ref` skips the extra `default_branch` lookup call —
  `branch = $ref`. Only the default-branch path makes the second
  `GET /repos/{repo}` request (and only when `default_branch` is not cached).
- `If-None-Match` is intentionally omitted when no file is on disk, so a 304
  cannot strand the caller with neither a body nor a file.
- App-specific Filament-branch helpers from the original source
  (`branchForFilamentVersion`, `branchToVersion`, `rewriteSelfRepoLinks`) are
  intentionally **not** part of this package — they depend on the consuming
  app's routes and version scheme.
