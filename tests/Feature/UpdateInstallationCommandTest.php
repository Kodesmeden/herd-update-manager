<?php

use App\Models\Installation;
use Illuminate\Support\Facades\Process;

it('runs all update steps and marks installation as completed', function () {
    Process::fake(['*' => Process::result('ok')]);

    $installation = Installation::factory()->create();

    $this->artisan('app:update-installation', ['id' => $installation->id])
        ->assertSuccessful();

    expect($installation->fresh())
        ->status->toBe('completed')
        ->progress->toBe(100)
        ->current_step->toBeNull()
        ->output->not->toBeEmpty()
        ->last_updated_at->not->toBeNull();
});

it('marks installation as failed when a step fails', function () {
    Process::fake([
        'composer update' => Process::result('ok'),
        '*npm update*' => Process::result(output: '', errorOutput: 'npm ERR! network error', exitCode: 1),
    ]);

    $installation = Installation::factory()->create();

    $this->artisan('app:update-installation', ['id' => $installation->id])
        ->assertFailed();

    expect($installation->fresh())
        ->status->toBe('failed')
        ->progress->toBe(50)
        ->current_step->toBe('NPM update')
        ->output->toContain('npm ERR!');
});

it('captures output from all steps', function () {
    Process::fake([
        'composer update' => Process::result('Updating dependencies'),
        '*npm update*' => Process::result('updated 5 packages'),
        'npm run build' => Process::result('built successfully'),
        '*optimize:clear*' => Process::result('Caches cleared successfully'),
    ]);

    $installation = Installation::factory()->create();

    $this->artisan('app:update-installation', ['id' => $installation->id])
        ->assertSuccessful();

    $output = $installation->fresh()->output;

    expect($output)
        ->toContain('Updating dependencies')
        ->toContain('updated 5 packages')
        ->toContain('built successfully')
        ->toContain('Caches cleared successfully');
});

it('runs exactly composer update, npm update, npm run build and optimize:clear', function () {
    Process::fake(['*' => Process::result('ok')]);

    $installation = Installation::factory()->create();

    $this->artisan('app:update-installation', ['id' => $installation->id])
        ->assertSuccessful();

    Process::assertRan('composer update');
    Process::assertRan('npm update');
    Process::assertRan('npm run build');
    Process::assertRan(fn ($process) => str_ends_with($process->command, 'artisan optimize:clear'));

    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'view:clear'));
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'config:clear'));
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'route:clear'));
});
