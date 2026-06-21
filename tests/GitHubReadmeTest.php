<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use JeffersonGoncalves\GitHubReadme\GitHubReadme;
use JeffersonGoncalves\GitHubReadme\Models\ReadmeCache;

it('renders and stores the readme on the first fetch', function () {
    Storage::fake('local');

    Http::fake([
        'api.github.com/repos/owner/repo/readme*' => Http::response(
            "# Hello World\n\nSome content.",
            200,
            ['ETag' => '"abc123"'],
        ),
    ]);

    $html = GitHubReadme::fetchHtml('https://github.com/owner/repo', 'main');

    expect($html)->toContain('Hello World');

    $cache = ReadmeCache::query()
        ->where('repo', 'owner/repo')
        ->where('ref', 'main')
        ->first();

    expect($cache)->not->toBeNull()
        ->and($cache->etag)->toBe('"abc123"')
        ->and($cache->html_path)->not->toBeNull()
        ->and($cache->fetched_at)->not->toBeNull()
        ->and($cache->checked_at)->not->toBeNull();

    Storage::disk('local')->assertExists($cache->html_path);

    Http::assertSentCount(1);
});

it('serves the cached file within the check window without calling github', function () {
    Storage::fake('local');

    Http::fake([
        'api.github.com/*' => Http::response('# Cached', 200, ['ETag' => '"e1"']),
    ]);

    $first = GitHubReadme::fetchHtml('https://github.com/owner/repo', 'main');
    $second = GitHubReadme::fetchHtml('https://github.com/owner/repo', 'main');

    expect($second)->toBe($first);

    // The second call is inside the freshness window, so no extra GitHub
    // request is made — only the single first fetch.
    Http::assertSentCount(1);
});

it('reuses the cached file on a 304 not modified response', function () {
    Storage::fake('local');

    Http::fakeSequence('api.github.com/*')
        ->push('# Conditional', 200, ['ETag' => '"e1"'])
        ->push('', 304);

    $first = GitHubReadme::fetchHtml('https://github.com/owner/repo', 'main');

    // Age the cache past the check window so a conditional request is issued.
    $this->travel(11)->minutes();

    $second = GitHubReadme::fetchHtml('https://github.com/owner/repo', 'main');

    expect($second)->toBe($first);

    Http::assertSentCount(2);
});

it('rewrites relative image src to raw.githubusercontent', function () {
    $markdown = '![logo](art/logo.png)';

    $out = GitHubReadme::rewriteRelativeAssets($markdown, 'owner/repo', 'main');

    expect($out)->toContain('https://raw.githubusercontent.com/owner/repo/main/art/logo.png');
});

it('rewrites relative html image src to raw.githubusercontent', function () {
    $markdown = '<img src="docs/banner.png">';

    $out = GitHubReadme::rewriteRelativeAssets($markdown, 'owner/repo', 'main');

    expect($out)->toContain('https://raw.githubusercontent.com/owner/repo/main/docs/banner.png');
});

it('marks external links with target blank and rel nofollow noopener', function () {
    $html = '<a href="https://example.com">External</a>';

    $out = GitHubReadme::markExternalLinks($html, 'mysite.test');

    expect($out)->toContain('target="_blank"')
        ->and($out)->toContain('rel="nofollow noopener"');
});

it('leaves same-host links untouched when marking external links', function () {
    $html = '<a href="https://mysite.test/page">Internal</a>';

    $out = GitHubReadme::markExternalLinks($html, 'mysite.test');

    expect($out)->not->toContain('target="_blank"')
        ->and($out)->not->toContain('rel="nofollow noopener"');
});

it('wraps tables in a md-table-scroll container', function () {
    $html = '<table><thead><tr><th>A</th></tr></thead><tbody><tr><td>1</td></tr></tbody></table>';

    $out = GitHubReadme::wrapTables($html);

    expect($out)->toContain('<div class="md-table-scroll">')
        ->and($out)->toContain('</table></div>');
});

it('returns null for a non-github url', function () {
    expect(GitHubReadme::fetchHtml('https://gitlab.com/owner/repo'))->toBeNull();
});

it('serves the stale cached file when the github request errors', function () {
    Storage::fake('local');

    Http::fakeSequence('api.github.com/*')
        ->push('# Original', 200, ['ETag' => '"e1"'])
        ->push('Service Unavailable', 503);

    $first = GitHubReadme::fetchHtml('https://github.com/owner/repo', 'main');

    // Age past the freshness window so a fresh (failing) request is issued.
    $this->travel(11)->minutes();

    $second = GitHubReadme::fetchHtml('https://github.com/owner/repo', 'main');

    // The 503 falls back to the stale cached file rather than returning null.
    expect($second)->toBe($first)
        ->and($second)->toContain('Original');

    Http::assertSentCount(2);
});

it('returns null on a github error when no cached file exists', function () {
    Storage::fake('local');

    Http::fake([
        'api.github.com/*' => Http::response('Not Found', 404),
    ]);

    expect(GitHubReadme::fetchHtml('https://github.com/owner/repo', 'main'))->toBeNull();
});

it('strips raw html from the rendered readme by default', function () {
    Storage::fake('local');

    Http::fake([
        'api.github.com/repos/owner/repo/readme*' => Http::response(
            "# Safe\n\n<script>alert('xss')</script>",
            200,
            ['ETag' => '"abc"'],
        ),
    ]);

    $html = GitHubReadme::fetchHtml('https://github.com/owner/repo', 'main');

    expect($html)->toContain('Safe')
        ->and($html)->not->toContain('<script>');
});

it('uses a custom renderer callable when configured', function () {
    Storage::fake('local');

    config()->set('github-readme.renderer', fn (string $markdown): string => '<div class="custom">'.trim($markdown).'</div>');

    Http::fake([
        'api.github.com/repos/owner/repo/readme*' => Http::response(
            '# Raw Markdown',
            200,
            ['ETag' => '"abc"'],
        ),
    ]);

    $html = GitHubReadme::fetchHtml('https://github.com/owner/repo', 'main');

    expect($html)->toContain('<div class="custom">')
        ->and($html)->toContain('# Raw Markdown');
});

it('sends the authorization bearer header when a token is configured', function () {
    Storage::fake('local');

    config()->set('github-readme.token', 'secret-token');

    Http::fake([
        'api.github.com/*' => Http::response('# Token', 200, ['ETag' => '"e1"']),
    ]);

    GitHubReadme::fetchHtml('https://github.com/owner/repo', 'main');

    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization', 'Bearer secret-token')
            && $request->hasHeader('User-Agent');
    });
});

it('rawurlencodes repo path segments in the api url', function () {
    Storage::fake('local');

    Http::fake([
        'api.github.com/*' => Http::response('# Encoded', 200, ['ETag' => '"e1"']),
    ]);

    GitHubReadme::fetchHtml('https://github.com/owner/repo.name', 'main');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'api.github.com/repos/owner/repo.name/readme');
    });
});

it('rewrites relative anchor links to absolute github blob urls', function () {
    $html = '<a href="LICENSE">License</a>';

    $out = GitHubReadme::rewriteRelativeLinks($html, 'owner/repo', 'main');

    expect($out)->toContain('href="https://github.com/owner/repo/blob/main/LICENSE"');
});

it('leaves absolute and special anchor links untouched when rewriting', function () {
    $html = '<a href="https://example.com">Ext</a><a href="#anchor">Hash</a><a href="mailto:a@b.com">Mail</a>';

    $out = GitHubReadme::rewriteRelativeLinks($html, 'owner/repo', 'main');

    expect($out)->toContain('href="https://example.com"')
        ->and($out)->toContain('href="#anchor"')
        ->and($out)->toContain('href="mailto:a@b.com"')
        ->and($out)->not->toContain('blob/main');
});

it('lazy-loads every image except the first and adds async decoding', function () {
    $html = '<img src="hero.png"><img src="second.png"><img src="third.png">';

    $out = GitHubReadme::lazyloadImages($html);

    // First image stays eager (LCP candidate) but still gets async decoding.
    expect($out)->toContain('<img src="hero.png" decoding="async">');

    // Both subsequent images become lazy.
    expect(substr_count($out, 'loading="lazy"'))->toBe(2);

    // decoding="async" is applied to all three.
    expect(substr_count($out, 'decoding="async"'))->toBe(3);
});

it('does not duplicate existing loading and decoding attributes', function () {
    $html = '<img src="a.png" loading="eager" decoding="sync">';

    $out = GitHubReadme::lazyloadImages($html);

    expect($out)->toBe($html);
});
