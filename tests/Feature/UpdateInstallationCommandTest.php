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
        ->progress->toBe(40)
        ->current_step->toBe('NPM update')
        ->output->toContain('npm ERR!');
});

it('captures output from all steps', function () {
    Process::fake([
        'composer update' => Process::result('Updating dependencies'),
        '*npm update*' => Process::result('updated 5 packages'),
        'npm run build' => Process::result('built successfully'),
        '*view:clear*' => Process::result('Compiled views cleared'),
        '*config:clear*' => Process::result('Configuration cache cleared'),
        '*route:clear*' => Process::result('Route cache cleared'),
    ]);

    $installation = Installation::factory()->create();

    $this->artisan('app:update-installation', ['id' => $installation->id])
        ->assertSuccessful();

    $output = $installation->fresh()->output;

    expect($output)
        ->toContain('Updating dependencies')
        ->toContain('updated 5 packages')
        ->toContain('built successfully');
});
