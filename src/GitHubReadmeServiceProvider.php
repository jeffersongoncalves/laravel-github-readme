<?php

namespace JeffersonGoncalves\GitHubReadme;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class GitHubReadmeServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-github-readme')
            ->hasConfigFile()
            ->hasMigration('create_github_readme_cache_table');
    }
}
