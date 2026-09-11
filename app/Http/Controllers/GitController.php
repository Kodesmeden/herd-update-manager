<?php

namespace App\Http\Controllers;

use App\Models\Installation;
use App\Support\GitRepository;
use Illuminate\Http\JsonResponse;

class GitController extends Controller
{
    /**
     * Branch names that are never deleted, whatever the default branch is.
     *
     * @var array<int, string>
     */
    private const PRIMARY_BRANCHES = ['main', 'master'];

    /**
     * The requested branch name, or null when none was given.
     */
    private function branchName(): ?string
    {
        $validated = request()->validate([
            'branch' => ['nullable', 'string', 'max:255'],
        ]);

        return trim($validated['branch'] ?? '') ?: null;
    }

    /**
     * The commit message for a pull request, falling back to the default.
     */
    private function commitMessage(): string
    {
        $validated = request()->validate([
            'message' => ['nullable', 'string', 'max:200'],
        ]);

        return trim($validated['message'] ?? '') ?: 'Update packages';
    }

    /**
     * List the local branches, and the branches that only exist on origin.
     */
    public function branches(Installation $installation): JsonResponse
    {
        $repository = new GitRepository($installation->path);
        $localBranches = $repository->branches();

        return response()->json([
            'branches' => $localBranches,
            'remote_branches' => array_values(array_diff($repository->remoteBranches(), $localBranches)),
        ]);
    }

    /**
     * Switch to an existing branch and pull latest changes.
     */
    public function switchBranch(Installation $installation): JsonResponse
    {
        $branch = $this->branchName();

        if ($branch === null) {
            return response()->json(['success' => false, 'error' => 'No branch specified'], 422);
        }

        $repository = new GitRepository($installation->path);

        if ($repository->hasUncommittedChanges()) {
            return response()->json([
                'success' => false,
                'error' => 'Cannot switch branch with uncommitted changes. Commit or stash first.',
            ], 422);
        }

        $result = $repository->checkout($branch);

        if (! $result->successful()) {
            return response()->json([
                'success' => false,
                'error' => trim($result->errorOutput()),
            ], 422);
        }

        $repository->pull();

        return response()->json(['success' => true, 'branch' => $branch]);
    }

    /**
     * Create a new branch and switch to it.
     */
    public function createBranch(Installation $installation): JsonResponse
    {
        $branch = $this->branchName() ?? 'develop';

        $result = (new GitRepository($installation->path))->createBranch($branch);

        if ($result->successful()) {
            return response()->json(['success' => true, 'branch' => $branch]);
        }

        return response()->json([
            'success' => false,
            'error' => trim($result->errorOutput()),
        ], 422);
    }

    /**
     * Describe what deleting a branch would remove, so it can be confirmed first.
     */
    public function previewBranchDeletion(Installation $installation): JsonResponse
    {
        $branch = $this->branchName();

        if ($branch === null) {
            return response()->json(['success' => false, 'error' => 'No branch specified'], 422);
        }

        $repository = new GitRepository($installation->path);

        // Stale remote refs would misreport where the branch lives and what only it contains
        $repository->fetchAllRemotes();

        $local = in_array($branch, $repository->branches(), true);
        $remote = in_array($branch, $repository->remoteBranches(), true);

        if (! $local && ! $remote) {
            return response()->json([
                'success' => false,
                'error' => "{$branch} no longer exists.",
            ], 422);
        }

        return response()->json([
            'success' => true,
            'branch' => $branch,
            'local' => $local,
            'remote' => $remote,
            'unique_commits' => $repository->commitsOnlyOnBranch($branch, $local, $remote),
            'pull_request_url' => $repository->pullRequestStatus($branch)['url'] ?? null,
        ]);
    }

    /**
     * Delete a branch locally and on origin.
     *
     * The local branch goes first. Git refuses to delete a branch that is checked
     * out in another worktree, and stopping there leaves both copies untouched.
     * A copy left behind on origin still shows up in the branch list.
     */
    public function deleteBranch(Installation $installation): JsonResponse
    {
        $branch = $this->branchName();

        if ($branch === null) {
            return response()->json(['success' => false, 'error' => 'No branch specified'], 422);
        }

        $repository = new GitRepository($installation->path);

        if ($branch === $repository->currentBranch()) {
            return response()->json([
                'success' => false,
                'error' => "{$branch} is checked out. Switch to another branch before deleting it.",
            ], 422);
        }

        if (in_array($branch, self::PRIMARY_BRANCHES, true) || $branch === $repository->defaultBranch()) {
            return response()->json([
                'success' => false,
                'error' => "{$branch} is a primary branch and cannot be deleted.",
            ], 422);
        }

        if (in_array($branch, $repository->branches(), true)) {
            $localDeletion = $repository->deleteLocalBranch($branch);

            if (! $localDeletion->successful()) {
                return response()->json([
                    'success' => false,
                    'error' => trim($localDeletion->errorOutput()),
                ], 422);
            }
        }

        if (in_array($branch, $repository->remoteBranches(), true)) {
            $remoteDeletion = $repository->deleteRemoteBranch($branch);

            if (! $remoteDeletion->successful()) {
                return response()->json([
                    'success' => false,
                    'error' => "Could not delete {$branch} on origin. ".trim($remoteDeletion->errorOutput()),
                ], 422);
            }
        }

        return response()->json(['success' => true, 'branch' => $branch]);
    }

    /**
     * Bring the current branch up to date with the default branch.
     *
     * Fetches first so the comparison is made against the real remote state,
     * refuses to touch a dirty work tree, and rolls back cleanly if the merge
     * runs into conflicts.
     */
    public function syncWithDefault(Installation $installation): JsonResponse
    {
        $repository = new GitRepository($installation->path);

        $branch = $repository->currentBranch();
        $defaultBranch = $repository->defaultBranch();

        if ($repository->hasUncommittedChanges()) {
            return response()->json([
                'success' => false,
                'error' => "Commit or stash your changes before updating from {$defaultBranch}.",
            ], 422);
        }

        // Never compare against a stale remote ref
        $repository->fetchAllRemotes();

        $behind = $repository->commitsBehindDefault($branch, $defaultBranch);

        if ($behind === 0) {
            return response()->json([
                'success' => true,
                'updated' => false,
                'message' => "Already up to date with {$defaultBranch}.",
            ]);
        }

        $merge = $repository->mergeDefaultBranch($defaultBranch);

        if (! $merge->successful()) {
            // Leave the work tree exactly as it was before the attempt
            $repository->abortMerge();

            return response()->json([
                'success' => false,
                'error' => "{$defaultBranch} could not be merged into {$branch} automatically. Resolve the conflicts manually. Nothing was changed.",
            ], 422);
        }

        return response()->json([
            'success' => true,
            'updated' => true,
            'message' => $behind === 1
                ? "Merged 1 commit from {$defaultBranch}."
                : "Merged {$behind} commits from {$defaultBranch}.",
        ]);
    }

    /**
     * Create a pull request to the default branch.
     */
    public function createPr(Installation $installation): JsonResponse
    {
        $repository = new GitRepository($installation->path);

        $branch = $repository->currentBranch();
        $defaultBranch = $repository->defaultBranch();

        if ($branch === $defaultBranch) {
            return response()->json([
                'success' => false,
                'error' => "Already on {$defaultBranch}. Switch to another branch to open a pull request.",
            ], 422);
        }

        // Uncommitted work is not a commit yet, so it would never reach the pull
        // request. Commit it first and let it count towards the comparison below.
        $committed = false;

        if ($repository->hasUncommittedChanges()) {
            $stage = $repository->stageAll();

            if (! $stage->successful()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Could not stage the changes. '.trim($stage->errorOutput().$stage->output()),
                ], 422);
            }

            $commit = $repository->commit($this->commitMessage());

            if (! $commit->successful()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Could not commit the changes. '.trim($commit->errorOutput().$commit->output()),
                ], 422);
            }

            $committed = true;
        }

        // GitHub compares the pushed branches, so a stale remote-tracking ref
        // would let a branch look ahead of a default branch that already has its commits
        $repository->fetchRemoteBranch($defaultBranch);

        if ($repository->commitsAheadOfDefault($branch, $defaultBranch) === 0) {
            return response()->json([
                'success' => false,
                'error' => "No commits ahead of {$defaultBranch}. There is nothing to merge.",
            ], 422);
        }

        // Unpushed commits are invisible to GitHub, which would reject the
        // pull request for having no commits between the two branches. Always
        // setting the upstream targets origin/<branch> even when the branch
        // tracks something else, and is a no-op once it is already pushed.
        $push = $repository->pushSetUpstream($branch);

        if (! $push->successful()) {
            $pushError = trim($push->errorOutput().$push->output());

            return response()->json([
                'success' => false,
                'error' => str_contains($pushError, '[rejected]')
                    ? "origin/{$branch} has commits that are not in your local branch. Pull before opening a pull request."
                    : "Could not push {$branch} to origin. ".$pushError,
            ], 422);
        }

        $title = $repository->lastCommitSubject() ?: "Merge {$branch} into {$defaultBranch}";

        $result = $repository->createPullRequest($defaultBranch, $branch, $title);

        if ($result->successful()) {
            return response()->json([
                'success' => true,
                'pr_url' => trim($result->output()),
                'committed' => $committed,
            ]);
        }

        $error = trim($result->errorOutput().$result->output());

        if (str_contains($error, 'already exists')) {
            preg_match('/https:\/\/github\.com\/[^\s]+/', $error, $matches);

            return response()->json([
                'success' => false,
                'error' => 'A pull request already exists.',
                'pr_url' => $matches[0] ?? null,
            ], 422);
        }

        return response()->json([
            'success' => false,
            'error' => $error,
        ], 422);
    }

    /**
     * Report whether the pull request for the current branch is ready to merge.
     */
    public function prStatus(Installation $installation): JsonResponse
    {
        $repository = new GitRepository($installation->path);

        return response()->json([
            'pull_request' => $repository->pullRequestStatus($repository->currentBranch()),
        ]);
    }

    /**
     * Merge an open pull request for the current branch.
     */
    public function mergePr(Installation $installation): JsonResponse
    {
        $repository = new GitRepository($installation->path);

        $branch = $repository->currentBranch();
        $pullRequest = $repository->pullRequestStatus($branch);

        if ($pullRequest === null) {
            return response()->json([
                'success' => false,
                'error' => 'No open pull request found for this branch.',
            ], 422);
        }

        // Re-check right before merging, in case the dashboard state is stale
        if (! $pullRequest['ready']) {
            return response()->json([
                'success' => false,
                'pr_state' => $pullRequest['state'],
                'pr_url' => $pullRequest['url'],
                'error' => $pullRequest['reason'],
            ], 422);
        }

        $mergeResult = $repository->mergePullRequest($branch);

        if (! $mergeResult->successful()) {
            return response()->json([
                'success' => false,
                'error' => trim($mergeResult->errorOutput()),
                'pr_url' => $pullRequest['url'],
            ], 422);
        }

        // Bring the local default branch up to date without leaving the current branch
        $defaultBranch = $repository->defaultBranch();
        $fastForwarded = $repository->fastForwardFromOrigin($defaultBranch);

        return response()->json([
            'success' => true,
            'merged' => true,
            'pr_url' => $pullRequest['url'],
            'warning' => $fastForwarded
                ? null
                : "Local {$defaultBranch} could not be updated automatically. It has diverged from origin/{$defaultBranch}.",
        ]);
    }
}
