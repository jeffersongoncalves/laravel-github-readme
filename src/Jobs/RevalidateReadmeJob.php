<?php

namespace JeffersonGoncalves\GitHubReadme\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use JeffersonGoncalves\GitHubReadme\GitHubReadme;

/**
 * Runs the conditional GitHub fetch for a stale README outside the request
 * (stale-while-revalidate). Unique per repo/ref so a burst of visits to the
 * same stale page queues a single job.
 */
class RevalidateReadmeJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    /**
     * Release the unique lock after an hour even if the job never ran
     * (e.g. no worker), so revalidation is not blocked forever.
     */
    public int $uniqueFor = 3600;

    public function __construct(
        public string $repo,
        public ?string $ref = null,
    ) {
        $this->onQueue(config('github-readme.revalidate_queue'));
    }

    public function uniqueId(): string
    {
        return $this->repo.':'.($this->ref ?: 'default');
    }

    public function handle(): void
    {
        GitHubReadme::revalidate($this->repo, $this->ref);
    }
}
