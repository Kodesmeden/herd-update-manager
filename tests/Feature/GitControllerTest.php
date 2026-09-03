<?php

use App\Models\Installation;
use Illuminate\Support\Facades\Process;

/**
 * Build the process fakes for a repository on the given branch.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function fakeRepositoryOn(string $branch, string $defaultBranch = 'main', array $overrides = []): array
{
    return array_merge([
        'git branch --show-current*' => Process::result($branch),
        'gh repo view*' => Process::result($defaultBranch),
        'gh pr view*' => Process::result(output: '', exitCode: 1),
        'git status --porcelain*' => Process::result(''),
        'git rev-parse --verify*' => Process::result('a1b2c3d'),
        'git rev-list --count*' => Process::result('0'),
        'git log --format=%s*' => Process::result('Update packages'),
        'git fetch --all --prune' => Process::result(''),
        'git fetch origin*' => Process::result(''),
        'git merge --abort*' => Process::result(''),
        'git merge*' => Process::result('Fast-forward'),
    ], $overrides);
}

/**
 * Build the gh payload for an open pull request in a given merge state.
 */
function fakePullRequestJson(
    string $mergeStateStatus,
    string $mergeable = 'MERGEABLE',
    bool $isDraft = false,
): string {
    return json_encode([
        'state' => 'OPEN',
        'url' => 'https://github.com/acme/site/pull/7',
        'mergeable' => $mergeable,
        'mergeStateStatus' => $mergeStateStatus,
        'isDraft' => $isDraft,
    ]);
}

it('refuses to create a pull request from the default branch', function () {
    Process::fake(fakeRepositoryOn('main'));

    $installation = Installation::factory()->create();

    $this->postJson(route('installations.git.pr', $installation))
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error', 'Already on main. Switch to another branch to open a pull request.');

    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'gh pr create'));
});

it('refuses to create a pull request when the branch matches the default branch', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'git rev-list --count*' => Process::result('0'),
    ]));

    $installation = Installation::factory()->create();

    $this->postJson(route('installations.git.pr', $installation))
        ->assertStatus(422)
        ->assertJsonPath('error', 'No commits ahead of main. There is nothing to merge.');

    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'gh pr create'));
});

it('creates a pull request when the branch is ahead of the default branch', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'git rev-list --count*' => Process::result('2'),
        'gh pr create*' => Process::result('https://github.com/acme/site/pull/7'),
    ]));

    $installation = Installation::factory()->create();

    $this->postJson(route('installations.git.pr', $installation))
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('pr_url', 'https://github.com/acme/site/pull/7');
});

it('compares against the remote default branch when it is available', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'git rev-list --count*' => Process::result('1'),
        'gh pr create*' => Process::result('https://github.com/acme/site/pull/7'),
    ]));

    $installation = Installation::factory()->create();

    $this->postJson(route('installations.git.pr', $installation))->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains($process->command, 'git rev-list --count')
        && str_contains($process->command, 'origin/main'));
});

it('falls back to the local default branch when the remote ref is missing', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'git rev-parse --verify*' => Process::result(output: '', exitCode: 1),
        'git rev-list --count*' => Process::result('1'),
        'gh pr create*' => Process::result('https://github.com/acme/site/pull/7'),
    ]));

    $installation = Installation::factory()->create();

    $this->postJson(route('installations.git.pr', $installation))->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains($process->command, 'git rev-list --count')
        && ! str_contains($process->command, 'origin/main'));
});

it('fast-forwards the local default branch after merging without leaving the current branch', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'gh pr view*' => Process::result(fakePullRequestJson('CLEAN')),
        'gh pr merge*' => Process::result('Merged pull request #7'),
        'git fetch origin*' => Process::result(''),
    ]));

    $installation = Installation::factory()->create();

    $this->postJson(route('installations.git.merge', $installation))
        ->assertSuccessful()
        ->assertJsonPath('merged', true)
        ->assertJsonPath('warning', null);

    Process::assertRan(fn ($process) => str_contains($process->command, 'git fetch origin')
        && str_contains($process->command, 'main'));

    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'git checkout'));
});

it('reports a warning when the local default branch has diverged', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'gh pr view*' => Process::result(fakePullRequestJson('CLEAN')),
        'gh pr merge*' => Process::result('Merged pull request #7'),
        'git fetch origin*' => Process::result(output: '', errorOutput: 'non-fast-forward', exitCode: 1),
    ]));

    $installation = Installation::factory()->create();

    $this->postJson(route('installations.git.merge', $installation))
        ->assertSuccessful()
        ->assertJsonPath('merged', true)
        ->assertJsonPath('warning', 'Local main could not be updated automatically. It has diverged from origin/main.');
});

it('refuses to merge while GitHub is still checking mergeability', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'gh pr view*' => Process::result(fakePullRequestJson('UNKNOWN', 'UNKNOWN')),
    ]));

    $installation = Installation::factory()->create();

    $this->postJson(route('installations.git.merge', $installation))
        ->assertStatus(422)
        ->assertJsonPath('pr_state', 'checking')
        ->assertJsonPath('error', 'GitHub is still checking whether this branch can be merged');

    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'gh pr merge'));
});

it('refuses to merge a conflicting pull request', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'gh pr view*' => Process::result(fakePullRequestJson('DIRTY', 'CONFLICTING')),
    ]));

    $installation = Installation::factory()->create();

    $this->postJson(route('installations.git.merge', $installation))
        ->assertStatus(422)
        ->assertJsonPath('pr_state', 'conflicting');

    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'gh pr merge'));
});

it('refuses to merge when branch protection blocks it', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'gh pr view*' => Process::result(fakePullRequestJson('BLOCKED')),
    ]));

    $installation = Installation::factory()->create();

    $this->postJson(route('installations.git.merge', $installation))
        ->assertStatus(422)
        ->assertJsonPath('pr_state', 'blocked');

    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'gh pr merge'));
});

it('allows merging when non-required checks are failing', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'gh pr view*' => Process::result(fakePullRequestJson('UNSTABLE')),
        'gh pr merge*' => Process::result('Merged pull request #7'),
    ]));

    $installation = Installation::factory()->create();

    $this->postJson(route('installations.git.merge', $installation))
        ->assertSuccessful()
        ->assertJsonPath('merged', true);
});

it('reports the pull request merge state for polling', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'gh pr view*' => Process::result(fakePullRequestJson('CLEAN')),
    ]));

    $installation = Installation::factory()->create();

    $this->getJson(route('installations.git.pr-status', $installation))
        ->assertSuccessful()
        ->assertJsonPath('pull_request.state', 'clean')
        ->assertJsonPath('pull_request.ready', true)
        ->assertJsonPath('pull_request.reason', null);
});

it('reports no pull request when the branch has none', function () {
    Process::fake(fakeRepositoryOn('develop'));

    $installation = Installation::factory()->create();

    $this->getJson(route('installations.git.pr-status', $installation))
        ->assertSuccessful()
        ->assertJsonPath('pull_request', null);
});

it('refuses to sync a branch with uncommitted changes', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'git status --porcelain*' => Process::result('M composer.lock'),
    ]));

    $installation = Installation::factory()->create();

    $this->postJson(route('installations.git.sync', $installation))
        ->assertStatus(422)
        ->assertJsonPath('error', 'Commit or stash your changes before updating from main.');

    Process::assertDidntRun(fn ($process) => str_starts_with($process->command, 'git merge'));
});

it('reports the branch is already up to date without merging', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'git rev-list --count*' => Process::result('0'),
    ]));

    $installation = Installation::factory()->create();

    $this->postJson(route('installations.git.sync', $installation))
        ->assertSuccessful()
        ->assertJsonPath('updated', false)
        ->assertJsonPath('message', 'Already up to date with main.');

    Process::assertDidntRun(fn ($process) => str_starts_with($process->command, 'git merge'));
});

it('fetches before deciding whether the branch is behind', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'git rev-list --count*' => Process::result('3'),
    ]));

    $installation = Installation::factory()->create();

    $this->postJson(route('installations.git.sync', $installation))
        ->assertSuccessful()
        ->assertJsonPath('updated', true)
        ->assertJsonPath('message', 'Merged 3 commits from main.');

    Process::assertRan('git fetch --all --prune');
    Process::assertRan(fn ($process) => str_starts_with($process->command, 'git merge')
        && str_contains($process->command, 'origin/main'));
});

it('aborts the merge and changes nothing when syncing hits conflicts', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'git rev-list --count*' => Process::result('2'),
        'git merge*' => Process::result(output: 'CONFLICT (content): Merge conflict', exitCode: 1),
    ]));

    $installation = Installation::factory()->create();

    $this->postJson(route('installations.git.sync', $installation))
        ->assertStatus(422)
        ->assertJsonPath('error', 'main could not be merged into develop automatically. Resolve the conflicts manually. Nothing was changed.');

    Process::assertRan(fn ($process) => str_starts_with($process->command, 'git merge --abort'));
});

it('ignores merge commits when counting how far a branch has drifted', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'git rev-list --count*' => Process::result('1'),
        'gh pr create*' => Process::result('https://github.com/acme/site/pull/7'),
    ]));

    $installation = Installation::factory()->create();

    $this->postJson(route('installations.git.pr', $installation))->assertSuccessful();
    $this->postJson(route('installations.git.sync', $installation))->assertSuccessful();

    // Merging a pull request leaves a merge commit on the default branch that the
    // source branch never sees, so counting it would report a false drift
    Process::assertRan(fn ($process) => str_contains($process->command, 'git rev-list --count --no-merges'));
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'git rev-list --count')
        && ! str_contains($process->command, '--no-merges'));
});
