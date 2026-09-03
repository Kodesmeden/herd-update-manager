<?php

namespace App\Http\Controllers;

use App\Models\Installation;
use App\Support\GitRepository;
use Illuminate\Http\JsonResponse;

class GitController extends Controller
{
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
     * List all local branches for an installation.
     */
    public function branches(Installation $installation): JsonResponse
    {
        return response()->json([
            'branches' => (new GitRepository($installation->path))->branches(),
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

        if ($repository->commitsAheadOfDefault($branch, $defaultBranch) === 0) {
            return response()->json([
                'success' => false,
                'error' => "No commits ahead of {$defaultBranch}. There is nothing to merge.",
            ], 422);
        }

        $title = $repository->lastCommitSubject() ?: "Merge {$branch} into {$defaultBranch}";

        $result = $repository->createPullRequest($defaultBranch, $branch, $title);

        if ($result->successful()) {
            return response()->json(['success' => true, 'pr_url' => trim($result->output())]);
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
