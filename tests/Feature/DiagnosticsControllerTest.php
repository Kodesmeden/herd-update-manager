<?php

use Illuminate\Support\Facades\Process;

it('runs a valid diagnostic check', function () {
    Process::fake([
        'git --version' => Process::result('git version 2.45.0'),
    ]);

    $this->getJson(route('diagnostics.run', 'git'))
        ->assertSuccessful()
        ->assertJson([
            'ok' => true,
            'output' => 'git version 2.45.0',
        ]);
});

it('returns failure for a failing diagnostic', function () {
    Process::fake([
        'gh --version' => Process::result(output: '', errorOutput: 'command not found: gh', exitCode: 127),
    ]);

    $this->getJson(route('diagnostics.run', 'gh'))
        ->assertSuccessful()
        ->assertJson([
            'ok' => false,
        ]);
});

it('returns error for unknown diagnostic check', function () {
    $this->getJson(route('diagnostics.run', 'unknown'))
        ->assertSuccessful()
        ->assertJson([
            'ok' => false,
            'output' => 'Unknown check',
        ]);
});

it('treats ssh authentication success as ok', function () {
    Process::fake([
        'ssh -T git@github.com' => Process::result(
            output: '',
            errorOutput: "Hi user! You've successfully authenticated, but GitHub does not provide shell access.",
            exitCode: 1,
        ),
    ]);

    $this->getJson(route('diagnostics.run', 'ssh'))
        ->assertSuccessful()
        ->assertJson(['ok' => true]);
});

it('supports all expected diagnostic checks', function (string $check) {
    Process::fake(['*' => Process::result('ok')]);

    $this->getJson(route('diagnostics.run', $check))
        ->assertSuccessful()
        ->assertJsonStructure(['ok', 'output']);
})->with(['git', 'gh', 'gh-auth', 'ssh', 'composer', 'php', 'node', 'npm']);
