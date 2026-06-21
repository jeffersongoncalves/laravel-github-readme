<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cache Disk
    |--------------------------------------------------------------------------
    |
    | The filesystem disk used to store the rendered README HTML files. The
    | default is the framework's built-in "local" disk so the package works
    | out of the box. For a dedicated location, define your own disk in
    | config/filesystems.php (for example a "github" disk) and set its name
    | here:
    |
    |   'github' => [
    |       'driver' => 'local',
    |       'root'   => storage_path('app/github'),
    |       'throw'  => false,
    |   ],
    |
    */
    'disk' => env('GITHUB_README_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Check Interval
    |--------------------------------------------------------------------------
    |
    | Minutes during which a freshly verified README is served straight from
    | disk without issuing even a conditional request to GitHub. Outside this
    | window a conditional (If-None-Match) request is made.
    |
    */
    'check_interval_minutes' => (int) env('GITHUB_README_CHECK_INTERVAL', 10),

    /*
    |--------------------------------------------------------------------------
    | GitHub API Token
    |--------------------------------------------------------------------------
    |
    | Optional personal access token used to authenticate GitHub API requests
    | and raise the rate limit. Falls back to the framework's services.github
    | token configuration when this value is null.
    |
    */
    'token' => env('GITHUB_TOKEN', env('GITHUB_README_TOKEN')),

    /*
    |--------------------------------------------------------------------------
    | User Agent
    |--------------------------------------------------------------------------
    |
    | The User-Agent header sent with every GitHub API request. GitHub
    | requires a User-Agent on all API calls.
    |
    */
    'user_agent' => env('GITHUB_README_USER_AGENT', 'laravel-github-readme'),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | The timeout, in seconds, applied to each GitHub API request.
    |
    */
    'timeout' => (int) env('GITHUB_README_TIMEOUT', 8),

    /*
    |--------------------------------------------------------------------------
    | Markdown Renderer
    |--------------------------------------------------------------------------
    |
    | An optional callable that receives the raw README markdown and returns
    | rendered HTML. When null, an internal League\CommonMark renderer is used
    | (GitHub Flavored Markdown + heading permalinks).
    |
    | The internal renderer STRIPS raw HTML ('html_input' => 'strip') so an
    | embedded <script> in a third-party README cannot become stored XSS. If you
    | need raw HTML passed through ('allow'), provide your own renderer callable
    | here AND sanitize its output yourself before display.
    |
    | WARNING: The rendered HTML is still UNTRUSTED. Always sanitize it before
    | display, e.g. with jeffersongoncalves/laravel-html-sanitizer.
    |
    */
    'renderer' => null,
];
