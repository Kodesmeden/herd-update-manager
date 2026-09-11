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
        'git for-each-ref*' => Process::result(''),
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

it('commits uncommitted changes before opening the pull request', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'git status --porcelain*' => Process::result(' M composer.lock'),
        'git rev-list --count*' => Process::result('1'),
        'git add --all*' => Process::result(''),
        'git commit*' => Process::result('1 file changed'),
        'gh pr create*' => Process::result('https://github.com/acme/site/pull/7'),
    ]));

    $installation = Installation::factory()->create();

    $this->postJson(route('installations.git.pr', $installation), ['message' => 'Bump packages'])
        ->assertSuccessful()
        ->assertJsonPath('committed', true);

    Process::assertRan(fn ($process) => str_starts_with($process->command, 'git add --all'));
    Process::assertRan(fn ($process) => $process->command === "git commit -m 'Bump packages'");
    Process::assertRan(fn ($process) => str_contains($process->command, 'gh pr create'));
});

it('falls back to the default commit message when none is given', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'git status --porcelain*' => Process::result(' M composer.lock'),
        'git rev-list --count*' => Process::result('1'),
        'git add --all*' => Process::result(''),
        'git commit*' => Process::result('1 file changed'),
        'gh pr create*' => Process::result('https://github.com/acme/site/pull/7'),
    ]));

    $installation = Installation::factory()->create();

    $this->postJson(route('installations.git.pr', $installation))->assertSuccessful();

    Process::assertRan(fn ($process) => $process->command === "git commit -m 'Update packages'");
});

it('does not commit when the work tree is clean', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'git rev-list --count*' => Process::result('2'),
        'gh pr create*' => Process::result('https://github.com/acme/site/pull/7'),
    ]));

    $installation = Installation::factory()->create();

    $this->postJson(route('installations.git.pr', $installation))
        ->assertSuccessful()
        ->assertJsonPath('committed', false);

    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'git commit'));
});

it('reports a failed commit without pushing or opening a pull request', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'git status --porcelain*' => Process::result(' M composer.lock'),
        'git rev-list --count*' => Process::result('1'),
        'git add --all*' => Process::result(''),
        'git commit*' => Process::result(output: '', errorOutput: 'Author identity unknown', exitCode: 1),
    ]));

    $installation = Installation::factory()->create();

    $this->postJson(route('installations.git.pr', $installation))
        ->assertStatus(422)
        ->assertJsonPath('error', 'Could not commit the changes. Author identity unknown');

    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'git push'));
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'gh pr create'));
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

it('lists the local branches and the branches that only exist on origin', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'git branch --format*' => Process::result("develop\nmain\nfeature/checkout\n"),
        'git for-each-ref*' => Process::result(
            "refs/remotes/origin/HEAD\nrefs/remotes/origin/develop\nrefs/remotes/origin/main\nrefs/remotes/origin/claude/old-session\n",
        ),
    ]));

    $installation = Installation::factory()->create();

    $this->getJson(route('installations.git.branches', $installation))
        ->assertSuccessful()
        ->assertExactJson([
            'branches' => ['develop', 'main', 'feature/checkout'],
            'remote_branches' => ['claude/old-session'],
        ]);
});

it('returns empty branch lists when git cannot read the repository', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'git branch --format*' => Process::result(output: '', exitCode: 128),
        'git for-each-ref*' => Process::result(output: '', exitCode: 128),
    ]));

    $installation = Installation::factory()->create();

    $this->getJson(route('installations.git.branches', $installation))
        ->assertSuccessful()
        ->assertExactJson(['branches' => [], 'remote_branches' => []]);
});

it('describes where a branch lives and what deleting it would lose', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'git branch --format*' => Process::result("develop\nmain\nbenchmark\n"),
        'git for-each-ref*' => Process::result("refs/remotes/origin/main\nrefs/remotes/origin/benchmark\n"),
        'git rev-list --count*' => Process::result('3'),
        'gh pr view*' => Process::result(fakePullRequestJson('CLEAN')),
    ]));

    $installation = Installation::factory()->create();

    $this->getJson(route('installations.git.branch-deletion', ['installation' => $installation, 'branch' => 'benchmark']))
        ->assertSuccessful()
        ->assertExactJson([
            'success' => true,
            'branch' => 'benchmark',
            'local' => true,
            'remote' => true,
            'unique_commits' => 3,
            'pull_request_url' => 'https://github.com/acme/site/pull/7',
        ]);

    Process::assertRan('git fetch --all --prune');
});

it('counts lost commits against every other branch and tag but not the branch itself', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'git branch --format*' => Process::result("develop\nmain\nbenchmark\n"),
        'git for-each-ref*' => Process::result("refs/remotes/origin/benchmark\n"),
    ]));

    $installation = Installation::factory()->create();

    $this->getJson(route('installations.git.branch-deletion', ['installation' => $installation, 'branch' => 'benchmark']))
        ->assertSuccessful();

    Process::assertRan("git rev-list --count --no-merges 'refs/heads/benchmark' 'refs/remotes/origin/benchmark' --not '--exclude=benchmark' --branches '--exclude=origin/benchmark' --remotes --tags");
});

it('refuses with 422 to preview deleting a branch that no longer exists', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'git branch --format*' => Process::result("develop\nmain\n"),
    ]));

    $installation = Installation::factory()->create();

    $this->getJson(route('installations.git.branch-deletion', ['installation' => $installation, 'branch' => 'benchmark']))
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error', 'benchmark no longer exists.');
});

it('refuses with 422 to delete a branch that must be kept', function (string $branch, string $error) {
    Process::fake(fakeRepositoryOn('develop', defaultBranch: 'trunk', overrides: [
        'git branch --format*' => Process::result("develop\ntrunk\nmain\nmaster\n"),
        'git branch -D*' => Process::result(''),
    ]));

    $installation = Installation::factory()->create();

    $this->deleteJson(route('installations.git.delete-branch', $installation), ['branch' => $branch])
        ->assertStatus(422)
        ->assertJsonPath('error', $error);

    Process::assertDidntRun(fn ($process) => str_starts_with($process->command, 'git branch -D'));
    Process::assertDidntRun(fn ($process) => str_starts_with($process->command, 'git push'));
})->with([
    'the checked out branch' => ['develop', 'develop is checked out. Switch to another branch before deleting it.'],
    'the default branch' => ['trunk', 'trunk is a primary branch and cannot be deleted.'],
    'main' => ['main', 'main is a primary branch and cannot be deleted.'],
    'master' => ['master', 'master is a primary branch and cannot be deleted.'],
]);

it('deletes a branch locally and on origin', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'git branch --format*' => Process::result("develop\nmain\nbenchmark\n"),
        'git for-each-ref*' => Process::result("refs/remotes/origin/main\nrefs/remotes/origin/benchmark\n"),
        'git branch -D*' => Process::result('Deleted branch benchmark (was a1b2c3d).'),
    ]));

    $installation = Installation::factory()->create();

    $this->deleteJson(route('installations.git.delete-branch', $installation), ['branch' => 'benchmark'])
        ->assertSuccessful()
        ->assertExactJson(['success' => true, 'branch' => 'benchmark']);

    Process::assertRan("git branch -D 'benchmark'");
    Process::assertRan("git push origin --delete 'benchmark'");
});

it('deletes a branch that only exists on origin without touching local branches', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'git branch --format*' => Process::result("develop\nmain\n"),
        'git for-each-ref*' => Process::result("refs/remotes/origin/main\nrefs/remotes/origin/claude/old-session\n"),
        'git branch -D*' => Process::result(''),
    ]));

    $installation = Installation::factory()->create();

    $this->deleteJson(route('installations.git.delete-branch', $installation), ['branch' => 'claude/old-session'])
        ->assertSuccessful();

    Process::assertRan("git push origin --delete 'claude/old-session'");
    Process::assertDidntRun(fn ($process) => str_starts_with($process->command, 'git branch -D'));
});

it('refuses with 422 and leaves origin untouched when the local branch cannot be deleted', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'git branch --format*' => Process::result("develop\nmain\nbenchmark\n"),
        'git for-each-ref*' => Process::result("refs/remotes/origin/benchmark\n"),
        'git branch -D*' => Process::result(
            output: '',
            errorOutput: "error: cannot delete branch 'benchmark' used by worktree at '/tmp/benchmark'\n",
            exitCode: 1,
        ),
    ]));

    $installation = Installation::factory()->create();

    $this->deleteJson(route('installations.git.delete-branch', $installation), ['branch' => 'benchmark'])
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error', "error: cannot delete branch 'benchmark' used by worktree at '/tmp/benchmark'");

    Process::assertDidntRun(fn ($process) => str_starts_with($process->command, 'git push'));
});

it('reports with 422 that origin kept the branch when deleting it there fails', function () {
    Process::fake(fakeRepositoryOn('develop', overrides: [
        'git branch --format*' => Process::result("develop\nmain\nbenchmark\n"),
        'git for-each-ref*' => Process::result("refs/remotes/origin/benchmark\n"),
        'git branch -D*' => Process::result('Deleted branch benchmark (was a1b2c3d).'),
        'git push*' => Process::result(
            output: '',
            errorOutput: "remote: error: Cannot delete this protected branch\n",
            exitCode: 1,
        ),
    ]));

    $installation = Installation::factory()->create();

    $this->deleteJson(route('installations.git.delete-branch', $installation), ['branch' => 'benchmark'])
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error', 'Could not delete benchmark on origin. remote: error: Cannot delete this protected branch');
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
