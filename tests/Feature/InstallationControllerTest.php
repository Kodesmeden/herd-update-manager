<?php

use App\Models\Installation;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Fake the filesystem so syncInstallations finds the given installations on disk.
 *
 * @param  Collection<int, Installation>|Installation[]  $installations
 */
function fakeSyncFor(iterable $installations = []): void
{
    $paths = collect($installations)->map(fn (Installation $i) => $i->path)->all();
    $real = app(Filesystem::class);

    File::partialMock()
        ->shouldReceive('isDirectory')
        ->andReturn(true)
        ->shouldReceive('directories')
        ->andReturn($paths)
        ->shouldReceive('exists')
        ->andReturnUsing(function (string $path) use ($paths, $real) {
            foreach ($paths as $installPath) {
                if ($path === $installPath.'/artisan') {
                    return true;
                }
            }

            return $real->exists($path);
        });
}

it('displays the home page with installations', function () {
    $installations = Installation::factory()->count(3)->create();
    fakeSyncFor($installations);

    $this->get(route('home'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('welcome')
            ->has('installations', 3)
            ->has('showHidden')
            ->has('herdPath')
            ->where('hiddenCount', 0)
        );
});

it('hides hidden installations by default', function () {
    $visible = Installation::factory()->count(2)->create();
    $hidden = Installation::factory()->hidden()->create();
    fakeSyncFor([...$visible, $hidden]);

    $this->get(route('home'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->has('installations', 2)
            ->where('showHidden', false)
            ->where('hiddenCount', 1)
        );
});

it('shows hidden installations when requested', function () {
    $visible = Installation::factory()->count(2)->create();
    $hidden = Installation::factory()->hidden()->create();
    fakeSyncFor([...$visible, $hidden]);

    $this->get(route('home', ['show_hidden' => true]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->has('installations', 3)
            ->where('showHidden', true)
        );
});

it('keeps known installations when the Herd directory is unavailable', function () {
    Installation::factory()->count(2)->create();

    File::partialMock()
        ->shouldReceive('isDirectory')
        ->andReturn(false)
        ->shouldReceive('directories')
        ->never();

    $this->get(route('home'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->has('installations', 2));
});

it('auto-resets completed installations after 10 seconds', function () {
    $old = Installation::factory()->completed()->create([
        'last_updated_at' => now()->subSeconds(15),
    ]);

    $recent = Installation::factory()->completed()->create([
        'last_updated_at' => now()->subSeconds(5),
    ]);

    fakeSyncFor([$old, $recent]);

    $this->get(route('home'));

    expect($old->fresh())
        ->status->toBe('idle')
        ->progress->toBe(0);

    expect($recent->fresh())
        ->status->toBe('completed');
});

it('can dismiss an installation status', function () {
    $installation = Installation::factory()->failed()->create();

    $this->patch(route('installations.dismiss', $installation))
        ->assertRedirect();

    expect($installation->fresh())
        ->status->toBe('idle')
        ->progress->toBe(0)
        ->current_step->toBeNull()
        ->output->toBeNull();
});

it('can hide an installation', function () {
    $installation = Installation::factory()->create();

    $this->patch(route('installations.hide', $installation))
        ->assertRedirect();

    expect($installation->fresh()->hidden)->toBeTrue();
});

it('can unhide an installation', function () {
    $installation = Installation::factory()->hidden()->create();

    $this->patch(route('installations.unhide', $installation))
        ->assertRedirect();

    expect($installation->fresh()->hidden)->toBeFalse();
});

it('sets status to running when triggering update', function () {
    $installation = Installation::factory()->create();

    $this->post(route('installations.update', $installation))
        ->assertRedirect();

    expect($installation->fresh())
        ->status->toBe('running')
        ->progress->toBe(0);

    expect($this->backgroundCommands())->toBe([
        ['command' => 'app:update-installation', 'installation_id' => $installation->id],
    ]);
});

it('sets status to pushing when triggering push', function () {
    $installation = Installation::factory()->create();

    $this->post(route('installations.push', $installation), ['message' => 'Test commit'])
        ->assertRedirect();

    expect($installation->fresh())
        ->status->toBe('pushing')
        ->progress->toBe(0);

    expect($this->backgroundCommands())->toBe([
        [
            'command' => "app:push-installation --message='Test commit'",
            'installation_id' => $installation->id,
        ],
    ]);
});

it('escapes the commit message before handing it to the shell', function () {
    $installation = Installation::factory()->create();

    $this->post(route('installations.push', $installation), ['message' => "it's a 'quoted' one"])
        ->assertRedirect();

    expect($this->backgroundCommands()[0]['command'])
        ->toBe("app:push-installation --message='it'\\''s a '\\''quoted'\\'' one'");
});

it('sets all visible installations to running on update all', function () {
    $visible = Installation::factory()->count(3)->create();
    $hidden = Installation::factory()->hidden()->create();

    $this->post(route('installations.update-all'))
        ->assertRedirect();

    foreach ($visible as $installation) {
        expect($installation->fresh())->status->toBe('running');
    }

    expect($hidden->fresh())->status->toBe('idle');

    expect($this->backgroundCommands())
        ->toHaveCount(3)
        ->each->toMatchArray(['command' => 'app:update-installation']);
});

it('sets all visible installations to pushing on push all', function () {
    $visible = Installation::factory()->count(2)->create();
    $hidden = Installation::factory()->hidden()->create();

    $this->post(route('installations.push-all'), ['message' => 'Batch push'])
        ->assertRedirect();

    foreach ($visible as $installation) {
        expect($installation->fresh())->status->toBe('pushing');
    }

    expect($hidden->fresh())->status->toBe('idle');

    expect($this->backgroundCommands())
        ->toHaveCount(2)
        ->each->toMatchArray(['command' => "app:push-installation --message='Batch push'"]);
});

/**
 * Fake a clean repository sitting on the given branch for the fetch-all loop.
 *
 * @return array<string, mixed>
 */
function fakeFetchableRepositoryOn(string $branch): array
{
    return [
        'git rev-parse --is-inside-work-tree*' => Process::result('true'),
        'git fetch --all --prune' => Process::result(''),
        'git remote set-head*' => Process::result(''),
        'git status --porcelain*' => Process::result(''),
        'git pull --ff-only' => Process::result('Already up to date.'),
        'gh repo view*' => Process::result('main'),
        'git branch --show-current*' => Process::result($branch),
        'git fetch origin*' => Process::result(''),
    ];
}

it('fast-forwards the local default branch when fetching from a feature branch', function () {
    Process::fake(fakeFetchableRepositoryOn('develop'));

    Installation::factory()->create();

    $this->postJson(route('installations.fetch-all'))
        ->assertSuccessful()
        ->assertJsonPath('fetched', 1);

    Process::assertRan(fn ($process) => str_contains($process->command, 'git fetch origin')
        && str_contains($process->command, 'main'));

    // The active branch must never be swapped out from under the user
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'git checkout'));
});

it('leaves the default branch to the ordinary pull when it is already checked out', function () {
    Process::fake(fakeFetchableRepositoryOn('main'));

    Installation::factory()->create();

    $this->postJson(route('installations.fetch-all'))->assertSuccessful();

    Process::assertRan('git pull --ff-only');
    Process::assertDidntRun(fn ($process) => str_starts_with($process->command, 'git fetch origin'));
});

it('skips directories that are not git repositories', function () {
    Process::fake([
        'git rev-parse --is-inside-work-tree*' => Process::result(output: '', exitCode: 1),
    ]);

    Installation::factory()->create();

    $this->postJson(route('installations.fetch-all'))
        ->assertSuccessful()
        ->assertJsonPath('fetched', 0);

    Process::assertDidntRun('git fetch --all --prune');
});
