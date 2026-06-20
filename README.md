<div class="filament-hidden">

![Laravel GitHub Readme](https://raw.githubusercontent.com/jeffersongoncalves/laravel-github-readme/master/art/jeffersongoncalves-laravel-github-readme.png)

</div>

# Laravel GitHub Readme

[![Latest Version on Packagist](https://img.shields.io/packagist/v/jeffersongoncalves/laravel-github-readme.svg?style=flat-square)](https://packagist.org/packages/jeffersongoncalves/laravel-github-readme)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/jeffersongoncalves/laravel-github-readme/run-tests.yml?branch=master&label=tests&style=flat-square)](https://github.com/jeffersongoncalves/laravel-github-readme/actions?query=workflow%3Arun-tests+branch%3Amaster)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/jeffersongoncalves/laravel-github-readme/fix-php-code-style-issues.yml?branch=master&label=code%20style&style=flat-square)](https://github.com/jeffersongoncalves/laravel-github-readme/actions?query=workflow%3A"Fix+PHP+code+styling"+branch%3Amaster)
[![Total Downloads](https://img.shields.io/packagist/dt/jeffersongoncalves/laravel-github-readme.svg?style=flat-square)](https://packagist.org/packages/jeffersongoncalves/laravel-github-readme)

Fetch, render and disk-cache GitHub repository READMEs in your Laravel application. The package issues conditional `If-None-Match` (ETag) requests with a short freshness window, reuses the cached HTML on `304 Not Modified` responses, falls back to stale content on network/API errors, and rewrites relative assets and links to absolute GitHub URLs.

> [!WARNING]
> The HTML returned by this package is **untrusted** — it comes from third-party GitHub READMEs. Always run it through an HTML sanitizer (for example [`jeffersongoncalves/laravel-html-sanitizer`](https://github.com/jeffersongoncalves/laravel-html-sanitizer)) before rendering it in a browser.

## Installation

You can install the package via composer:

```bash
composer require jeffersongoncalves/laravel-github-readme
```

Publish and run the migration (it creates the `github_readme_cache` table):

```bash
php artisan vendor:publish --tag="laravel-github-readme-migrations"
php artisan migrate
```

Optionally publish the config file:

```bash
php artisan vendor:publish --tag="laravel-github-readme-config"
```

## Usage

```php
use JeffersonGoncalves\GitHubReadme\GitHubReadme;
use JeffersonGoncalves\HtmlSanitizer\Facades\HtmlSanitizer; // example sanitizer

// Fetch the default-branch README, rendered to HTML and cached on disk.
$html = GitHubReadme::fetchHtml('https://github.com/owner/repo');

// Fetch a specific branch/tag/ref.
$html = GitHubReadme::fetchHtml('https://github.com/owner/repo', '2.x');

// ALWAYS sanitize before display — the HTML is untrusted.
echo HtmlSanitizer::clean($html);
```

### Post-processing helpers

The rendered HTML can be further decorated with the following static helpers (all pure, all safe to chain):

```php
// Open off-site links in a new tab with rel="nofollow noopener".
$html = GitHubReadme::markExternalLinks($html, 'mysite.test');

// Lazy-load every image except the first (kept eager as the LCP candidate).
$html = GitHubReadme::lazyloadImages($html);

// Wrap wide tables in a horizontally-scrollable container.
$html = GitHubReadme::wrapTables($html);
```

Other available helpers: `GitHubReadme::repoFromUrl()`, `GitHubReadme::rewriteRelativeAssets()`, and `GitHubReadme::rewriteRelativeLinks()`.

## How caching works

1. **Freshness window** — within `check_interval_minutes` of the last successful check, the cached HTML file is served straight from disk with **no** GitHub call at all.
2. **Conditional request** — outside the window a conditional `If-None-Match` request is made. A `304 Not Modified` reuses the cached file (and does **not** count against the rate limit).
3. **Re-render** — a `200` response re-renders the markdown, rewrites relative assets/links, stores the file on disk and updates the `github_readme_cache` row.
4. **Stale fallback** — on a network/API error the stale cached file is served when present.

## Configuration

```php
return [
    // The filesystem disk used to store rendered README HTML files.
    'disk' => env('GITHUB_README_DISK', 'local'),

    // Minutes a freshly verified README is served without contacting GitHub.
    'check_interval_minutes' => (int) env('GITHUB_README_CHECK_INTERVAL', 10),

    // Optional GitHub token (falls back to config('services.github.token')).
    'token' => env('GITHUB_TOKEN', env('GITHUB_README_TOKEN')),

    // User-Agent header sent on every GitHub API request.
    'user_agent' => env('GITHUB_README_USER_AGENT', 'laravel-github-readme'),

    // Request timeout in seconds.
    'timeout' => (int) env('GITHUB_README_TIMEOUT', 8),

    // Optional renderer callable: fn (string $markdown): string.
    // Null uses the internal League\CommonMark renderer (GFM + heading permalinks).
    'renderer' => null,
];
```

### Using a dedicated disk

By default the framework's built-in `local` disk is used. To isolate cached READMEs, define a dedicated disk in `config/filesystems.php` and point the `disk` option at it:

```php
// config/filesystems.php
'disks' => [
    'github' => [
        'driver' => 'local',
        'root' => storage_path('app/github'),
        'throw' => false,
    ],
],
```

```dotenv
GITHUB_README_DISK=github
```

### Custom renderer

Provide your own markdown renderer (for example to add server-side syntax highlighting) by setting `renderer` to any callable:

```php
'renderer' => fn (string $markdown): string => \JeffersonGoncalves\Markdown\Markdown::render($markdown, headingPermalinks: true),
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](.github/CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Jèfferson Gonçalves](https://github.com/jeffersongoncalves)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
