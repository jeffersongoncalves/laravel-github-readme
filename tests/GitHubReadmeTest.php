<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use JeffersonGoncalves\GitHubReadme\GitHubReadme;
use JeffersonGoncalves\GitHubReadme\Jobs\RevalidateReadmeJob;
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

it('serves the stale file and queues a revalidation when background revalidation is on', function () {
    Storage::fake('local');
    Queue::fake();

    Http::fake(['api.github.com/*' => Http::response('# Stale', 200, ['ETag' => '"e1"'])]);

    $first = GitHubReadme::fetchHtml('https://github.com/owner/repo', 'main');

    config()->set('github-readme.revalidate_in_background', true);
    $this->travel(11)->minutes();

    $second = GitHubReadme::fetchHtml('https://github.com/owner/repo', 'main');
    GitHubReadme::fetchHtml('https://github.com/owner/repo', 'main');
    GitHubReadme::fetchHtml('https://github.com/owner/repo', 'main');

    expect($second)->toBe($first);

    // Only the initial synchronous fetch hit GitHub; the burst queued one job.
    Http::assertSentCount(1);
    Queue::assertPushed(RevalidateReadmeJob::class, 1);
    Queue::assertPushed(RevalidateReadmeJob::class, fn ($job) => $job->repo === 'owner/repo' && $job->ref === 'main');
});

it('still serves the stale file when dispatching the revalidation job fails', function () {
    Storage::fake('local');

    Http::fake(['api.github.com/*' => Http::response('# Stale', 200, ['ETag' => '"e1"'])]);

    $first = GitHubReadme::fetchHtml('https://github.com/owner/repo', 'main');

    config()->set('github-readme.revalidate_in_background', true);
    config()->set('queue.default', 'missing');
    $this->travel(11)->minutes();

    expect(GitHubReadme::fetchHtml('https://github.com/owner/repo', 'main'))->toBe($first);

    Http::assertSentCount(1);
});

it('fetches synchronously when background revalidation is on but no file exists', function () {
    Storage::fake('local');
    Queue::fake();

    config()->set('github-readme.revalidate_in_background', true);

    Http::fake(['api.github.com/*' => Http::response('# First', 200, ['ETag' => '"e1"'])]);

    expect(GitHubReadme::fetchHtml('https://github.com/owner/repo', 'main'))->toContain('First');

    Http::assertSentCount(1);
    Queue::assertNothingPushed();
});

it('does not queue a revalidation when background revalidation is off', function () {
    Storage::fake('local');
    Queue::fake();

    Http::fakeSequence('api.github.com/*')
        ->push('# Conditional', 200, ['ETag' => '"e1"'])
        ->push('', 304);

    GitHubReadme::fetchHtml('https://github.com/owner/repo', 'main');
    $this->travel(11)->minutes();
    GitHubReadme::fetchHtml('https://github.com/owner/repo', 'main');

    Http::assertSentCount(2);
    Queue::assertNothingPushed();
});

it('re-renders the readme when the revalidation job runs', function () {
    Storage::fake('local');

    Http::fakeSequence('api.github.com/*')
        ->push('# Old', 200, ['ETag' => '"e1"'])
        ->push('# New', 200, ['ETag' => '"e2"']);

    GitHubReadme::fetchHtml('https://github.com/owner/repo', 'main');

    (new RevalidateReadmeJob('owner/repo', 'main'))->handle();

    $cache = ReadmeCache::query()->where('repo', 'owner/repo')->where('ref', 'main')->first();

    expect($cache->etag)->toBe('"e2"')
        ->and(Storage::disk('local')->get($cache->html_path))->toContain('New');
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

it('leaves protocol-relative image src untouched when rewriting assets', function () {
    $markdown = '<img src="//cdn.example.com/logo.png">';

    $out = GitHubReadme::rewriteRelativeAssets($markdown, 'owner/repo', 'main');

    expect($out)->toContain('src="//cdn.example.com/logo.png"');
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

it('logs github error responses with the api message', function () {
    Storage::fake('local');
    Log::spy();

    Http::fake([
        'api.github.com/repos/owner/repo/readme*' => Http::response(
            ['message' => 'No commit found for the ref master'],
            404,
            ['X-RateLimit-Remaining' => '4999'],
        ),
    ]);

    expect(GitHubReadme::fetchHtml('https://github.com/owner/repo', 'master'))->toBeNull();

    Log::shouldHaveReceived('warning')->with('GitHubReadme API request returned an error status', [
        'repo' => 'owner/repo',
        'ref' => 'master',
        'status' => 404,
        'message' => 'No commit found for the ref master',
        'rate_limit_remaining' => '4999',
    ]);
});

it('keeps a zero rate limit remaining header in the log context', function () {
    Storage::fake('local');
    Log::spy();

    Http::fake([
        'api.github.com/*' => Http::response(
            ['message' => 'API rate limit exceeded'],
            403,
            ['X-RateLimit-Remaining' => '0'],
        ),
    ]);

    GitHubReadme::fetchHtml('https://github.com/owner/repo', 'main');

    Log::shouldHaveReceived('warning')->with('GitHubReadme API request returned an error status', [
        'repo' => 'owner/repo',
        'ref' => 'main',
        'status' => 403,
        'message' => 'API rate limit exceeded',
        'rate_limit_remaining' => '0',
    ]);
});

it('still serves the stale file when logging the error response throws', function () {
    Storage::fake('local');

    Http::fakeSequence('api.github.com/*')
        ->push('# Original', 200, ['ETag' => '"e1"'])
        ->push(['message' => 'Server Error'], 500);

    $first = GitHubReadme::fetchHtml('https://github.com/owner/repo', 'main');

    $this->travel(11)->minutes();

    Log::shouldReceive('warning')->andThrow(new RuntimeException('log file not writable'));

    expect(GitHubReadme::fetchHtml('https://github.com/owner/repo', 'main'))->toBe($first);
});

it('logs github error responses on the default branch lookup', function () {
    Storage::fake('local');
    Log::spy();

    Http::fake([
        'api.github.com/repos/owner/repo/readme*' => Http::response('# Hi', 200),
        'api.github.com/repos/owner/repo' => Http::response(['message' => 'Bad credentials'], 401),
    ]);

    GitHubReadme::fetchHtml('https://github.com/owner/repo');

    Log::shouldHaveReceived('warning')->with('GitHubReadme default branch lookup returned an error status', [
        'repo' => 'owner/repo',
        'ref' => null,
        'status' => 401,
        'message' => 'Bad credentials',
        'rate_limit_remaining' => null,
    ]);
});

it('serves the stale cached file when the github request times out', function () {
    Storage::fake('local');

    Http::fake(['api.github.com/repos/owner/repo/readme*' => Http::response(
        '# Original',
        200,
        ['ETag' => '"e1"'],
    )]);

    $first = GitHubReadme::fetchHtml('https://github.com/owner/repo', 'main');

    $this->travel(11)->minutes();

    Http::fake(['api.github.com/*' => fn () => throw new ConnectionException(
        'cURL error 28: Operation timed out after 8002 milliseconds with 0 bytes received'
    )]);

    $second = GitHubReadme::fetchHtml('https://github.com/owner/repo', 'main');

    expect($second)->toBe($first)
        ->and($second)->toContain('Original');
});

it('returns null on a github timeout when no cached file exists', function () {
    Storage::fake('local');

    Http::fake(['api.github.com/*' => fn () => throw new ConnectionException('cURL error 28: timed out')]);

    expect(GitHubReadme::fetchHtml('https://github.com/owner/repo', 'main'))->toBeNull();
});

it('falls back to HEAD when the default branch lookup times out', function () {
    Storage::fake('local');

    Http::fake([
        'api.github.com/repos/owner/repo/readme*' => Http::response(
            '![logo](art/logo.png)',
            200,
            ['ETag' => '"abc123"'],
        ),
        'api.github.com/repos/owner/repo' => fn () => throw new ConnectionException('cURL error 28: timed out'),
    ]);

    $html = GitHubReadme::fetchHtml('https://github.com/owner/repo');

    expect($html)->toContain('https://raw.githubusercontent.com/owner/repo/HEAD/art/logo.png');

    $cache = ReadmeCache::query()->where('repo', 'owner/repo')->where('ref', 'default')->first();

    expect($cache->default_branch)->toBeNull();
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

it('rewrites relative anchor links in every quoting style', function (string $html) {
    $out = GitHubReadme::rewriteRelativeLinks($html, 'microg/GmsCore', 'master');

    expect($out)->toBe('<a href="https://github.com/microg/GmsCore/blob/master/TRANSLATION.md">T</a>');
})->with([
    'unquoted' => '<a href=TRANSLATION.md>T</a>',
    'single-quoted' => "<a href='TRANSLATION.md'>T</a>",
    'double-quoted' => '<a href="TRANSLATION.md">T</a>',
]);

it('leaves unquoted absolute and anchor hrefs untouched', function (string $html) {
    expect(GitHubReadme::rewriteRelativeLinks($html, 'owner/repo', 'main'))->toBe($html);
})->with([
    '<a href=https://example.com/x>X</a>',
    '<a href=#x>X</a>',
    "<a href='mailto:a@b.com'>X</a>",
]);

it('rewrites relative html image src in every quoting style', function (string $markdown) {
    $out = GitHubReadme::rewriteRelativeAssets($markdown, 'owner/repo', 'main');

    expect($out)->toBe('<img alt="logo" src="https://raw.githubusercontent.com/owner/repo/main/art/logo.png">');
})->with([
    'unquoted' => '<img alt="logo" src=art/logo.png>',
    'single-quoted' => "<img alt=\"logo\" src='art/logo.png'>",
    'double-quoted' => '<img alt="logo" src="art/logo.png">',
]);

it('leaves unquoted absolute image src untouched', function () {
    $markdown = '<img src=https://cdn.example.com/logo.png>';

    expect(GitHubReadme::rewriteRelativeAssets($markdown, 'owner/repo', 'main'))->toBe($markdown);
});

it('rewrites root-relative anchor links to repo blob urls', function () {
    $html = '<a href="/curriculum"><code>/curriculum</code></a>';

    $out = GitHubReadme::rewriteRelativeLinks($html, 'freeCodeCamp/freeCodeCamp', 'main');

    expect($out)->toContain('href="https://github.com/freeCodeCamp/freeCodeCamp/blob/main/curriculum"');
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
