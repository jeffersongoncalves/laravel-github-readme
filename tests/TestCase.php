<?php

namespace JeffersonGoncalves\GitHubReadme\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use JeffersonGoncalves\GitHubReadme\GitHubReadmeServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            GitHubReadmeServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->testing_connection());

        $configPath = __DIR__.'/../config/github-readme.php';
        if (file_exists($configPath)) {
            $app['config']->set('github-readme', require $configPath);
        }
    }

    /**
     * Defaults to an in-memory SQLite connection for local development; CI
     * (tests.yml) sets GITHUB_README_TEST_DB_* to run the same suite
     * against real MySQL and PostgreSQL instances too. Deliberately not the
     * plain DB_* names: Orchestra Testbench itself sets DB_CONNECTION=testing
     * by convention, which would collide with (and always win over) a
     * driver value read from the same variable here.
     *
     * @return array<string, mixed>
     */
    protected function testing_connection(): array
    {
        $driver = env('GITHUB_README_TEST_DB_DRIVER', 'sqlite');

        if ($driver === 'sqlite') {
            return ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
        }

        return [
            'driver' => $driver,
            'host' => env('GITHUB_README_TEST_DB_HOST', '127.0.0.1'),
            'port' => env('GITHUB_README_TEST_DB_PORT'),
            'database' => env('GITHUB_README_TEST_DB_DATABASE', 'testing'),
            'username' => env('GITHUB_README_TEST_DB_USERNAME', 'root'),
            'password' => env('GITHUB_README_TEST_DB_PASSWORD', ''),
            'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
            'prefix' => '',
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        $stubsPath = __DIR__.'/../database/migrations';
        $tempPath = sys_get_temp_dir().'/laravel-github-readme-migrations';

        if (! is_dir($tempPath)) {
            mkdir($tempPath, 0755, true);
        }

        foreach (glob($stubsPath.'/*.php.stub') as $stub) {
            $filename = basename(str_replace('.php.stub', '.php', $stub));
            $target = $tempPath.'/'.$filename;

            if (! file_exists($target)) {
                copy($stub, $target);
            }
        }

        $this->loadMigrationsFrom($tempPath);
    }
}
