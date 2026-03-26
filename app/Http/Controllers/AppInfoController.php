<?php

namespace App\Http\Controllers;

use App\Models\Installation;
use App\Support\HerdEnvironment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class AppInfoController extends Controller
{
    /**
     * Get combined app info and git info for an installation.
     */
    public function show(Installation $installation): JsonResponse
    {
        $path = $installation->path;
        $env = HerdEnvironment::env();

        return response()->json([
            'app_name' => $this->resolveAppName($path),
            'laravel_version' => $this->resolveLaravelVersion($path),
            'git' => $this->resolveGitInfo($path, $env),
        ]);
    }

    /**
     * Resolve APP_NAME from the installation's .env file.
     */
    private function resolveAppName(string $path): ?string
    {
        $envFile = $path.'/.env';

        if (! File::exists($envFile)) {
            return null;
        }

        $contents = File::get($envFile);

        if (preg_match('/^APP_NAME=(.+)$/m', $contents, $matches)) {
            return trim($matches[1], "\"' ");
        }

        return null;
    }

    /**
     * Resolve the installed Laravel framework version from composer.lock.
     */
    private function resolveLaravelVersion(string $path): string
    {
        $lockFile = $path.'/composer.lock';

        if (! File::exists($lockFile)) {
            return 'Unknown';
        }

        /** @var array{packages: array<int, array{name: string, version: string}>}|null $lock */
        $lock = json_decode(File::get($lockFile), true);

        if (! $lock) {
            return 'Unknown';
        }

        foreach ($lock['packages'] ?? [] as $package) {
            if ($package['name'] === 'laravel/framework') {
                return ltrim($package['version'], 'v');
            }
        }

        return 'Unknown';
    }

    /**
     * Resolve git information for an installation.
     *
     * @param  array<string, string>  $env
     * @return array<string, mixed>
     */
    private function resolveGitInfo(string $path, array $env): array
    {
        $isGitRepo = Process::path($path)->env($env)->timeout(5)
            ->run('git rev-parse --is-inside-work-tree 2>/dev/null');

        if (! $isGitRepo->successful()) {
            return ['is_git_repo' => false];
        }

        $branch = trim(Process::path($path)->env($env)->timeout(5)
            ->run('git branch --show-current')->output());

        $remoteUrl = trim(Process::path($path)->env($env)->timeout(5)
            ->run('git remote get-url origin 2>/dev/null')->output());

        $hasChanges = trim(Process::path($path)->env($env)->timeout(5)
            ->run('git status --porcelain')->output()) !== '';

        $lastCommit = trim(Process::path($path)->env($env)->timeout(5)
            ->run('git log --oneline -1 2>/dev/null')->output());

        $defaultBranch = $this->detectDefaultBranch($path, $env);

        $hasOpenPr = false;
        $aheadOfDefault = false;

        if ($branch !== $defaultBranch) {
            // Check if branch has commits ahead of default
            $aheadCheck = Process::path($path)->env($env)->timeout(5)
                ->run(sprintf('git rev-list --count %s..%s', escapeshellarg($defaultBranch), escapeshellarg($branch)));
            $aheadOfDefault = (int) trim($aheadCheck->output()) > 0;

            if ($aheadOfDefault) {
                $prCheck = Process::path($path)->env($env)->timeout(10)
                    ->run(sprintf('gh pr view %s --json state 2>/dev/null', escapeshellarg($branch)));

                if ($prCheck->successful()) {
                    /** @var array{state: string}|null $prData */
                    $prData = json_decode(trim($prCheck->output()), true);
                    $hasOpenPr = ($prData['state'] ?? '') === 'OPEN';
                }
            }
        }

        return [
            'is_git_repo' => true,
            'branch' => $branch,
            'remote_url' => $remoteUrl,
            'has_changes' => $hasChanges,
            'last_commit' => $lastCommit,
            'is_main_branch' => $branch === $defaultBranch,
            'default_branch' => $defaultBranch,
            'has_open_pr' => $hasOpenPr,
            'ahead_of_default' => $aheadOfDefault,
        ];
    }

    /**
     * Detect the default branch for a repository.
     *
     * @param  array<string, string>  $env
     */
    private function detectDefaultBranch(string $path, array $env): string
    {
        // GitHub CLI is authoritative for the default branch
        $ghResult = Process::path($path)->env($env)->timeout(10)
            ->run('gh repo view --json defaultBranchRef -q .defaultBranchRef.name 2>/dev/null');

        if ($ghResult->successful() && trim($ghResult->output()) !== '') {
            return trim($ghResult->output());
        }

        // Fall back to git symbolic-ref (may be stale)
        $result = Process::path($path)->env($env)->timeout(5)
            ->run('git symbolic-ref refs/remotes/origin/HEAD 2>/dev/null');

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
