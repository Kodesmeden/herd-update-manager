<?php

use App\Models\Installation;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

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
});

it('sets status to pushing when triggering push', function () {
    $installation = Installation::factory()->create();

    $this->post(route('installations.push', $installation), ['message' => 'Test commit'])
        ->assertRedirect();

    expect($installation->fresh())
        ->status->toBe('pushing')
        ->progress->toBe(0);
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
});
