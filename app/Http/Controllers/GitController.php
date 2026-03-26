<?php

namespace App\Http\Controllers;

use App\Models\Installation;
use App\Support\HerdEnvironment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Process;

class GitController extends Controller
{
    /**
     * Get git information for an installation.
     */
    public function info(Installation $installation): JsonResponse
    {
        $env = HerdEnvironment::env();
        $path = $installation->path;
        $suppress = HerdEnvironment::suppressStderr();

        $isGitRepo = Process::path($path)->env($env)->timeout(5)
            ->run("git rev-parse --is-inside-work-tree {$suppress}");

        if (! $isGitRepo->successful()) {
            return response()->json(['is_git_repo' => false]);
        }

        $branch = trim(Process::path($path)->env($env)->timeout(5)
            ->run('git branch --show-current')->output());

        $remoteUrl = trim(Process::path($path)->env($env)->timeout(5)
            ->run("git remote get-url origin {$suppress}")->output());

        $hasChanges = trim(Process::path($path)->env($env)->timeout(5)
            ->run('git status --porcelain')->output()) !== '';

        $lastCommit = trim(Process::path($path)->env($env)->timeout(5)
            ->run("git log --oneline -1 {$suppress}")->output());

        $defaultBranch = $this->detectDefaultBranch($path, $env);

        $hasOpenPr = false;

        if ($branch !== $defaultBranch) {
            $prCheck = Process::path($path)->env($env)->timeout(10)
                ->run(sprintf("gh pr view %s --json state {$suppress}", escapeshellarg($branch)));

            if ($prCheck->successful()) {
                /** @var array{state: string}|null $prData */
                $prData = json_decode(trim($prCheck->output()), true);
                $hasOpenPr = ($prData['state'] ?? '') === 'OPEN';
            }
        }

        return response()->json([
            'is_git_repo' => true,
            'branch' => $branch,
            'remote_url' => $remoteUrl,
            'has_changes' => $hasChanges,
            'last_commit' => $lastCommit,
            'is_main_branch' => $branch === $defaultBranch,
            'default_branch' => $defaultBranch,
            'has_open_pr' => $hasOpenPr,
        ]);
    }

    /**
     * List all local branches for an installation.
     */
    public function branches(Installation $installation): JsonResponse
    {
        $env = HerdEnvironment::env();
        $path = $installation->path;

        $result = Process::path($path)->env($env)->timeout(5)
            ->run('git branch --format="%(refname:short)"');

        if (! $result->successful()) {
            return response()->json(['branches' => []]);
        }

        $branches = array_filter(array_map('trim', explode("\n", trim($result->output()))));

        return response()->json(['branches' => array_values($branches)]);
    }

    /**
     * Switch to an existing branch and pull latest changes.
     */
    public function switchBranch(Installation $installation): JsonResponse
    {
        $env = HerdEnvironment::env();
        $path = $installation->path;
        $branch = request()->input('branch');

        if (! $branch) {
            return response()->json(['success' => false, 'error' => 'No branch specified'], 422);
        }

        // Check for uncommitted changes
        $hasChanges = trim(Process::path($path)->env($env)->timeout(5)
            ->run('git status --porcelain')->output()) !== '';

        if ($hasChanges) {
            return response()->json([
                'success' => false,
                'error' => 'Cannot switch branch with uncommitted changes. Commit or stash first.',
            ], 422);
        }

        $result = Process::path($path)->env($env)->timeout(15)
            ->run(sprintf('git checkout %s', escapeshellarg($branch)));

        if (! $result->successful()) {
            return response()->json([
                'success' => false,
                'error' => trim($result->errorOutput()),
            ], 422);
        }

        // Pull latest after checkout
        Process::path($path)->env($env)->timeout(30)->run('git pull');

        return response()->json(['success' => true, 'branch' => $branch]);
    }

    /**
     * Create a new branch and switch to it.
     */
    public function createBranch(Installation $installation): JsonResponse
    {
        $branch = request()->input('branch', 'develop');

        $result = Process::path($installation->path)
            ->env(HerdEnvironment::env())
            ->timeout(15)
            ->run(sprintf('git checkout -b %s', escapeshellarg($branch)));

        if ($result->successful()) {
            return response()->json(['success' => true, 'branch' => $branch]);
        }

        return response()->json([
            'success' => false,
            'error' => trim($result->errorOutput()),
        ], 422);
    }

    /**
     * Create a pull request to the default branch.
     */
    public function createPr(Installation $installation): JsonResponse
    {
        $env = HerdEnvironment::env();
        $path = $installation->path;

        $branch = trim(Process::path($path)->env($env)->timeout(5)
            ->run('git branch --show-current')->output());

        $defaultBranch = $this->detectDefaultBranch($path, $env);

        $lastCommitMsg = trim(Process::path($path)->env($env)->timeout(5)
            ->run('git log --format=%s -1')->output());

        $title = $lastCommitMsg ?: "Merge {$branch} into {$defaultBranch}";

        $result = Process::path($path)
            ->env($env)
            ->timeout(30)
            ->run(sprintf(
                'gh pr create --base %s --head %s --title %s --body %s',
                escapeshellarg($defaultBranch),
                escapeshellarg($branch),
                escapeshellarg($title),
                escapeshellarg(''),
            ));

        if ($result->successful()) {
            $prUrl = trim($result->output());

            return response()->json(['success' => true, 'pr_url' => $prUrl]);
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
     * Merge an open pull request for the current branch.
     */
    public function mergePr(Installation $installation): JsonResponse
    {
        $env = HerdEnvironment::env();
        $path = $installation->path;

        $branch = trim(Process::path($path)->env($env)->timeout(5)
            ->run('git branch --show-current')->output());

        $prCheck = Process::path($path)
            ->env($env)
            ->timeout(15)
            ->run(sprintf('gh pr view %s --json mergeable,url,state', escapeshellarg($branch)));

        if (! $prCheck->successful()) {
            return response()->json([
                'success' => false,
                'error' => 'No open pull request found for this branch.',
            ], 422);
        }

        /** @var array{mergeable: string, url: string, state: string} $prData */
        $prData = json_decode(trim($prCheck->output()), true);

        if (($prData['mergeable'] ?? '') === 'CONFLICTING') {
            return response()->json([
                'success' => false,
                'has_conflicts' => true,
                'pr_url' => $prData['url'] ?? null,
                'error' => 'This pull request has merge conflicts. Please resolve them on GitHub.',
            ], 422);
        }

        $mergeResult = Process::path($path)
            ->env($env)
            ->timeout(30)
            ->run(sprintf('gh pr merge %s --merge', escapeshellarg($branch)));

        if ($mergeResult->successful()) {
            return response()->json([
                'success' => true,
                'merged' => true,
                'pr_url' => $prData['url'] ?? null,
            ]);
        }

        return response()->json([
            'success' => false,
            'error' => trim($mergeResult->errorOutput()),
            'pr_url' => $prData['url'] ?? null,
        ], 422);
    }

    /**
     * Detect the default branch for a repository.
     *
     * @param  array<string, string>  $env
     */
    private function detectDefaultBranch(string $path, array $env): string
    {
        $suppress = HerdEnvironment::suppressStderr();

        // GitHub CLI is authoritative for the default branch
        $ghResult = Process::path($path)->env($env)->timeout(10)
            ->run("gh repo view --json defaultBranchRef -q .defaultBranchRef.name {$suppress}");

        if ($ghResult->successful() && trim($ghResult->output()) !== '') {
            return trim($ghResult->output());
        }

        // Fall back to git symbolic-ref (may be stale)
        $result = Process::path($path)->env($env)->timeout(5)
            ->run("git symbolic-ref refs/remotes/origin/HEAD {$suppress}");

        if ($result->successful()) {
            $ref = trim($result->output());

            return str_replace('refs/remotes/origin/', '', $ref);
        }

        // Final fallback: check branch names
        $branches = trim(Process::path($path)->env($env)->timeout(5)
            ->run('git branch -a')->output());

        if (str_contains($branches, 'main')) {
            return 'main';
        }

        return 'master';
    }
}
