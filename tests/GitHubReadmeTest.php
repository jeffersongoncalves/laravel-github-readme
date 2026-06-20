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
