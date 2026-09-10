<?php

use App\Models\Installation;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Prefix shared by every temporary installation directory this file creates.
 */
const TEMP_INSTALLATION_PREFIX = 'herd-app-info-';

/**
 * Create an installation whose path points at a real temporary directory.
 *
 * @param  array<string, string>  $files  File name mapped to its contents
 */
function installationWithFiles(array $files = []): Installation
{
    $path = sys_get_temp_dir().'/'.TEMP_INSTALLATION_PREFIX.Str::random(12);

    File::ensureDirectoryExists($path);

    foreach ($files as $name => $contents) {
        File::put($path.'/'.$name, $contents);
    }

    return Installation::factory()->create(['path' => $path]);
}

/**
 * Build a composer.lock listing the given packages.
 *
 * @param  array<string, string>  $packages  Package name mapped to its version
 */
function composerLockWith(array $packages): string
{
    $entries = [];

    foreach ($packages as $name => $version) {
        $entries[] = ['name' => $name, 'version' => $version];
    }

    return json_encode(['packages' => $entries]);
}

/**
 * Fake a path that git does not recognise as a work tree.
 */
function fakeNonRepository(): void
{
    Process::fake(['*' => Process::result(output: '', exitCode: 1)]);
}

/**
 * Fake a repository that git and the GitHub CLI both report on cleanly.
 */
function fakeCleanRepository(string $branch = 'main'): void
{
    Process::fake([
        'git rev-parse --is-inside-work-tree*' => Process::result('true'),
        'git branch --show-current*' => Process::result($branch),
        'gh repo view*' => Process::result('main'),
        'git remote get-url origin*' => Process::result('git@github.com:acme/site.git'),
        'git status --porcelain*' => Process::result(''),
        'git log --oneline*' => Process::result('a1b2c3d Update packages'),
        'git rev-list --count*' => Process::result('0'),
        '*' => Process::result(''),
    ]);
}

afterEach(function () {
    foreach (File::glob(sys_get_temp_dir().'/'.TEMP_INSTALLATION_PREFIX.'*') as $directory) {
        File::deleteDirectory($directory);
    }
});

it('returns the app name from the installation .env file', function () {
    fakeNonRepository();
    $installation = installationWithFiles([
        '.env' => "APP_ENV=local\nAPP_NAME=\"Acme Site\"\nAPP_DEBUG=true\n",
    ]);

    $this->getJson(route('installations.app-info', $installation))
        ->assertSuccessful()
        ->assertJsonPath('app_name', 'Acme Site');
});

it('returns a null app name when the installation has no .env file', function () {
    fakeNonRepository();
    $installation = installationWithFiles();

    $this->getJson(route('installations.app-info', $installation))
        ->assertSuccessful()
        ->assertJsonPath('app_name', null);
});

it('returns a null app name when .env does not set APP_NAME', function () {
    fakeNonRepository();
    $installation = installationWithFiles([
        '.env' => "APP_ENV=local\nAPP_DEBUG=true\n",
    ]);

    $this->getJson(route('installations.app-info', $installation))
        ->assertSuccessful()
        ->assertJsonPath('app_name', null);
});

it('returns the Laravel version from composer.lock without the leading v', function () {
    fakeNonRepository();
    $installation = installationWithFiles([
        'composer.lock' => composerLockWith([
            'symfony/console' => 'v7.4.1',
            'laravel/framework' => 'v13.31.0',
        ]),
    ]);

    $this->getJson(route('installations.app-info', $installation))
        ->assertSuccessful()
        ->assertJsonPath('laravel_version', '13.31.0');
});

it('returns an unknown Laravel version when composer.lock cannot name the framework', function (?string $lock) {
    fakeNonRepository();
    $installation = installationWithFiles($lock === null ? [] : ['composer.lock' => $lock]);

    $this->getJson(route('installations.app-info', $installation))
        ->assertSuccessful()
        ->assertJsonPath('laravel_version', 'Unknown');
})->with([
    'no composer.lock' => null,
    'unparsable composer.lock' => 'this is not json',
    'framework not listed' => fn () => composerLockWith(['symfony/console' => 'v7.4.1']),
]);

it('includes the git state of the installation', function () {
    fakeCleanRepository('feature/checkout');
    $installation = installationWithFiles();

    $this->getJson(route('installations.app-info', $installation))
        ->assertSuccessful()
        ->assertJsonPath('git.is_git_repo', true)
        ->assertJsonPath('git.branch', 'feature/checkout')
        ->assertJsonPath('git.default_branch', 'main')
        ->assertJsonPath('git.remote_url', 'git@github.com:acme/site.git')
        ->assertJsonPath('git.has_changes', false)
        ->assertJsonPath('git.is_main_branch', false);
});

it('reports that the installation is not a git repository', function () {
    fakeNonRepository();
    $installation = installationWithFiles();

    $this->getJson(route('installations.app-info', $installation))
        ->assertSuccessful()
        ->assertJsonPath('git.is_git_repo', false)
        ->assertJsonMissingPath('git.branch');
});

it('returns 404 for an installation that does not exist', function () {
    $this->getJson(route('installations.app-info', 999))
        ->assertNotFound();
});
