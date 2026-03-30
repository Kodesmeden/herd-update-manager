<?php

use App\Models\Installation;
use Illuminate\Support\Facades\Process;

it('commits and pushes successfully', function () {
    Process::fake([
        'git pull --rebase' => Process::result('Already up to date.'),
        'git add --all' => Process::result(''),
        'git status --porcelain' => Process::result('M composer.lock'),
        'git commit*' => Process::result('1 file changed'),
        'git push' => Process::result('Everything up-to-date'),
    ]);

    $installation = Installation::factory()->create();

    $this->artisan('app:push-installation', [
        'id' => $installation->id,
        '--message' => 'Update packages',
    ])->assertSuccessful();

    expect($installation->fresh())
        ->status->toBe('completed')
        ->progress->toBe(100);
});

it('skips commit when there are no changes', function () {
    Process::fake([
        'git pull --rebase' => Process::result('Already up to date.'),
        'git add --all' => Process::result(''),
        'git status --porcelain' => Process::result(''),
        'git push' => Process::result('Everything up-to-date'),
    ]);

    $installation = Installation::factory()->create();

    $this->artisan('app:push-installation', ['id' => $installation->id])
        ->assertSuccessful();

    Process::assertDidntRun('git commit*');
});

it('fails when git pull fails', function () {
    Process::fake([
        'git add --all' => Process::result(''),
        'git status --porcelain' => Process::result('M composer.lock'),
        'git commit*' => Process::result('1 file changed'),
        'git pull --rebase' => Process::result(output: '', errorOutput: 'CONFLICT', exitCode: 1),
    ]);

    $installation = Installation::factory()->create();

    $this->artisan('app:push-installation', ['id' => $installation->id])
        ->assertFailed();

    expect($installation->fresh())
        ->status->toBe('failed')
        ->output->toContain('CONFLICT');
});

it('treats everything up-to-date push as success', function () {
    Process::fake([
        'git pull --rebase' => Process::result('Already up to date.'),
        'git add --all' => Process::result(''),
        'git status --porcelain' => Process::result(''),
        'git push' => Process::result(output: 'Everything up-to-date', exitCode: 1),
    ]);

    $installation = Installation::factory()->create();

    $this->artisan('app:push-installation', ['id' => $installation->id])
        ->assertSuccessful();

    expect($installation->fresh())->status->toBe('completed');
});

it('fails when push fails', function () {
    Process::fake([
        'git pull --rebase' => Process::result('ok'),
        'git add --all' => Process::result(''),
        'git status --porcelain' => Process::result(''),
        'git push' => Process::result(output: '', errorOutput: 'rejected', exitCode: 1),
    ]);

    $installation = Installation::factory()->create();

    $this->artisan('app:push-installation', ['id' => $installation->id])
        ->assertFailed();

    expect($installation->fresh())->status->toBe('failed');
});
