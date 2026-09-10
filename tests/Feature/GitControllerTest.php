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
        'git push*' => Process::result('Everything up-to-date'),
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

it('refreshes the remote default branch before counting commits ahead', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'git rev-list --count*' => Process::result('2'),
        'gh pr create*' => Process::result('https://github.com/acme/site/pull/7'),
    ]));

    $installation = Installation::factory()->create();

    $this->postJson(route('installations.git.pr', $installation))->assertSuccessful();

    Process::assertRan(fn ($process) => str_starts_with($process->command, "git fetch origin 'main'"));
});

it('pushes the branch to its own remote branch before creating the pull request', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'git rev-list --count*' => Process::result('2'),
        'gh pr create*' => Process::result('https://github.com/acme/site/pull/7'),
    ]));

    $installation = Installation::factory()->create();

    $this->postJson(route('installations.git.pr', $installation))->assertSuccessful();

    Process::assertRan(fn ($process) => $process->command === "git push --set-upstream origin 'develop'");
});

it('reports a failed push instead of opening a pull request', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'git rev-list --count*' => Process::result('2'),
        'git push*' => Process::result(output: '', errorOutput: 'fatal: could not read from remote', exitCode: 1),
    ]));

    $installation = Installation::factory()->create();

    $this->postJson(route('installations.git.pr', $installation))
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error', 'Could not push develop to origin. fatal: could not read from remote');

    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'gh pr create'));
});

it('explains a rejected push instead of returning raw git output', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'git rev-list --count*' => Process::result('2'),
        'git push*' => Process::result(
            output: '',
            errorOutput: ' ! [rejected] develop -> develop (fetch first)',
            exitCode: 1,
        ),
    ]));

    $installation = Installation::factory()->create();

    $this->postJson(route('installations.git.pr', $installation))
        ->assertStatus(422)
        ->assertJsonPath('error', 'origin/develop has commits that are not in your local branch. Pull before opening a pull request.');

    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'gh pr create'));
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

it('lists the local branches of the installation', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'git branch --format*' => Process::result("develop\nmain\nfeature/checkout\n"),
    ]));

    $installation = Installation::factory()->create();

    $this->getJson(route('installations.git.branches', $installation))
        ->assertSuccessful()
        ->assertExactJson(['branches' => ['develop', 'main', 'feature/checkout']]);
});

it('returns an empty branch list when git cannot read the repository', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'git branch --format*' => Process::result(output: '', exitCode: 128),
    ]));

    $installation = Installation::factory()->create();

    $this->getJson(route('installations.git.branches', $installation))
        ->assertSuccessful()
        ->assertExactJson(['branches' => []]);
});

it('refuses with 422 to switch branch when no branch is given', function () {
    Process::fake(fakeRepositoryOn('develop'));

    $installation = Installation::factory()->create();

    $this->postJson(route('installations.git.switch', $installation))
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error', 'No branch specified');

    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'git checkout'));
});

it('refuses with 422 to switch branch while the work tree is dirty', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'git status --porcelain*' => Process::result(' M app/Models/Installation.php'),
    ]));

    $installation = Installation::factory()->create();

    $this->postJson(route('installations.git.switch', $installation), ['branch' => 'main'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'Cannot switch branch with uncommitted changes. Commit or stash first.');

    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'git checkout'));
});

it('checks out the branch and pulls it when switching succeeds', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'git checkout*' => Process::result("Switched to branch 'main'"),
        'git pull*' => Process::result('Already up to date.'),
    ]));

    $installation = Installation::factory()->create();

    $this->postJson(route('installations.git.switch', $installation), ['branch' => 'main'])
        ->assertSuccessful()
        ->assertExactJson(['success' => true, 'branch' => 'main']);

    Process::assertRan(fn ($process) => str_starts_with($process->command, 'git checkout '));
    Process::assertRan(fn ($process) => str_starts_with($process->command, 'git pull'));
});

it('reports the git error with 422 when the checkout fails', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'git checkout*' => Process::result(
            output: '',
            errorOutput: "error: pathspec 'nope' did not match any file(s) known to git\n",
            exitCode: 1,
        ),
    ]));

    $installation = Installation::factory()->create();

    $this->postJson(route('installations.git.switch', $installation), ['branch' => 'nope'])
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error', "error: pathspec 'nope' did not match any file(s) known to git");

    Process::assertDidntRun(fn ($process) => str_starts_with($process->command, 'git pull'));
});

it('creates the requested branch and switches to it', function () {
    Process::fake(fakeRepositoryOn('main', overrides: [
        'git checkout -b*' => Process::result("Switched to a new branch 'feature/checkout'"),
    ]));

    $installation = Installation::factory()->create();

    $this->postJson(route('installations.git.branch', $installation), ['branch' => 'feature/checkout'])
        ->assertSuccessful()
        ->assertExactJson(['success' => true, 'branch' => 'feature/checkout']);

    Process::assertRan(fn ($process) => $process->command === "git checkout -b 'feature/checkout'");
});

it('falls back to develop when creating a branch without a name', function () {
    Process::fake(fakeRepositoryOn('main', overrides: [
        'git checkout -b*' => Process::result("Switched to a new branch 'develop'"),
    ]));

    $installation = Installation::factory()->create();

    $this->postJson(route('installations.git.branch', $installation))
        ->assertSuccessful()
        ->assertJsonPath('branch', 'develop');

    Process::assertRan(fn ($process) => $process->command === "git checkout -b 'develop'");
});

it('reports the git error with 422 when the branch already exists', function () {
    Process::fake(fakeRepositoryOn('main', overrides: [
        'git checkout -b*' => Process::result(
            output: '',
            errorOutput: "fatal: a branch named 'develop' already exists\n",
            exitCode: 128,
        ),
    ]));

    $installation = Installation::factory()->create();

    $this->postJson(route('installations.git.branch', $installation), ['branch' => 'develop'])
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error', "fatal: a branch named 'develop' already exists");
});

it('rejects a branch name longer than 255 characters', function () {
    Process::fake(fakeRepositoryOn('main'));

    $installation = Installation::factory()->create();

    $this->postJson(route('installations.git.switch', $installation), ['branch' => str_repeat('a', 256)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('branch');

    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'git checkout'));
});

it('escapes the branch name before handing it to the shell', function () {
    Process::fake(fakeRepositoryOn('main', overrides: [
        'git checkout*' => Process::result(''),
    ]));

    $installation = Installation::factory()->create();

    $this->postJson(route('installations.git.branch', $installation), ['branch' => "x'; rm -rf /tmp; #"])
        ->assertSuccessful();

    Process::assertRan(fn ($process) => $process->command === "git checkout -b 'x'\\''; rm -rf /tmp; #'");
});
