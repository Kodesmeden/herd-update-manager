<?php

use App\Ai\Agents\CommitMessageAgent;
use App\Models\Installation;
use Illuminate\Support\Facades\Process;

it('commits and pushes successfully', function () {
    Process::fake([
        'git rev-parse --abbrev-ref --symbolic-full-name @{upstream}*' => Process::result('origin/main'),
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

it('commits with Update when no message is given', function () {
    Process::fake([
        'git rev-parse --abbrev-ref --symbolic-full-name @{upstream}*' => Process::result('origin/main'),
        'git pull --rebase' => Process::result('Already up to date.'),
        'git add --all' => Process::result(''),
        'git status --porcelain' => Process::result('M routes/web.php'),
        'git commit*' => Process::result('1 file changed'),
        'git push' => Process::result('Everything up-to-date'),
    ]);

    $installation = Installation::factory()->create();

    $this->artisan('app:push-installation', ['id' => $installation->id])->assertSuccessful();

    Process::assertRan("git commit -m 'Update'");
});

it('commits with the message Claude writes when asked', function () {
    config(['ai.providers.anthropic.key' => 'test-key']);
    Process::fake([
        'git rev-parse --abbrev-ref --symbolic-full-name @{upstream}*' => Process::result('origin/main'),
        'git pull --rebase' => Process::result('Already up to date.'),
        'git add --all' => Process::result(''),
        'git status --porcelain' => Process::result('M routes/web.php'),
        'git diff --cached --stat*' => Process::result(' routes/web.php | 1 +'),
        'git diff --cached --no-color*' => Process::result('+Route::get(...)'),
        'git commit*' => Process::result('1 file changed'),
        'git push' => Process::result('Everything up-to-date'),
    ]);
    CommitMessageAgent::fake(['Add the settings route']);

    $installation = Installation::factory()->create();

    $this->artisan('app:push-installation', ['id' => $installation->id, '--generate-message' => true])
        ->assertSuccessful();

    Process::assertRan("git commit -m 'Add the settings route'");
});

it('falls back to the given message when Claude cannot write one', function () {
    config(['ai.providers.anthropic.key' => 'test-key']);
    Process::fake([
        'git rev-parse --abbrev-ref --symbolic-full-name @{upstream}*' => Process::result('origin/main'),
        'git pull --rebase' => Process::result('Already up to date.'),
        'git add --all' => Process::result(''),
        'git status --porcelain' => Process::result('M routes/web.php'),
        'git diff --cached --stat*' => Process::result(' routes/web.php | 1 +'),
        'git diff --cached --no-color*' => Process::result('+Route::get(...)'),
        'git commit*' => Process::result('1 file changed'),
        'git push' => Process::result('Everything up-to-date'),
    ]);
    CommitMessageAgent::fake(fn () => throw new RuntimeException('Overloaded'));

    $installation = Installation::factory()->create();

    $this->artisan('app:push-installation', [
        'id' => $installation->id,
        '--message' => 'Tweak routes',
        '--generate-message' => true,
    ])->assertSuccessful();

    Process::assertRan("git commit -m 'Tweak routes'");
});

it('skips commit when there are no changes', function () {
    Process::fake([
        'git rev-parse --abbrev-ref --symbolic-full-name @{upstream}*' => Process::result('origin/main'),
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
        'git rev-parse --abbrev-ref --symbolic-full-name @{upstream}*' => Process::result('origin/main'),
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
        'git rev-parse --abbrev-ref --symbolic-full-name @{upstream}*' => Process::result('origin/main'),
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
        'git rev-parse --abbrev-ref --symbolic-full-name @{upstream}*' => Process::result('origin/main'),
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

it('publishes a branch that has no upstream instead of failing on the pull', function () {
    Process::fake([
        'git rev-parse --abbrev-ref --symbolic-full-name @{upstream}*' => Process::result(
            output: '', errorOutput: 'fatal: no upstream configured', exitCode: 1,
        ),
        'git branch --show-current*' => Process::result('develop'),
        'git add --all' => Process::result(''),
        'git status --porcelain' => Process::result('M composer.lock'),
        'git commit*' => Process::result('1 file changed'),
        'git push --set-upstream origin*' => Process::result('branch develop set up to track origin/develop'),
    ]);

    $installation = Installation::factory()->create();

    $this->artisan('app:push-installation', ['id' => $installation->id])
        ->assertSuccessful();

    expect($installation->fresh())->status->toBe('completed');

    // Rebasing without tracking information is what used to abort the push
    Process::assertDidntRun('git pull --rebase');
    Process::assertRan(fn ($process) => str_contains($process->command, "git push --set-upstream origin 'develop'"));
});
