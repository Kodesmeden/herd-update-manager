<?php

namespace App\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

class GitRepository
{
    /**
     * Why a pull request in a given merge state cannot be merged yet.
     *
     * @var array<string, string>
     */
    private const MERGE_STATE_REASONS = [
        'checking' => 'GitHub is still checking whether this branch can be merged',
        'conflicting' => 'Merge conflicts - resolve them on GitHub',
        'draft' => 'Pull request is still a draft',
        'behind' => 'Branch is behind the base branch and must be updated first',
        'blocked' => 'Merge is blocked by branch protection, such as a required review or check',
        'unstable' => 'Some checks are failing',
    ];

    /** @var array<string, string|false> */
    private readonly array $env;

    public function __construct(private readonly string $path)
    {
        $this->env = HerdEnvironment::projectEnv();
    }

    /**
     * Collect the git state used by the dashboard.
     *
     * @return array{is_git_repo: bool, branch?: string, remote_url?: string, has_changes?: bool, last_commit?: string, is_main_branch?: bool, default_branch?: string, pull_request?: array{state: string, ready: bool, reason: string|null, url: string|null}|null, ahead_of_default?: bool, behind_default?: int}
     */
    public function info(): array
    {
        if (! $this->isRepository()) {
            return ['is_git_repo' => false];
        }

        $branch = $this->currentBranch();
        $defaultBranch = $this->defaultBranch();
        $isDefaultBranch = $branch === $defaultBranch;

        $aheadOfDefault = ! $isDefaultBranch && $this->commitsAheadOfDefault($branch, $defaultBranch) > 0;

        $pullRequest = $aheadOfDefault ? $this->pullRequestStatus($branch) : null;
        $behindDefault = $isDefaultBranch ? 0 : $this->commitsBehindDefault($branch, $defaultBranch);

        return [
            'is_git_repo' => true,
            'branch' => $branch,
            'remote_url' => $this->remoteUrl(),
            'has_changes' => $this->hasUncommittedChanges(),
            'last_commit' => $this->lastCommit(),
            'is_main_branch' => $isDefaultBranch,
            'default_branch' => $defaultBranch,
            'pull_request' => $pullRequest,
            'ahead_of_default' => $aheadOfDefault,
            'behind_default' => $behindDefault,
        ];
    }

    /**
     * Check whether the path is inside a git work tree.
     */
    public function isRepository(): bool
    {
        return $this->run('git rev-parse --is-inside-work-tree', quiet: true)->successful();
    }

    /**
     * Get the currently checked out branch.
     */
    public function currentBranch(): string
    {
        return trim($this->run('git branch --show-current')->output());
    }

    /**
     * Get the origin remote URL.
     */
    public function remoteUrl(): string
    {
        return trim($this->run('git remote get-url origin', quiet: true)->output());
    }

    /**
     * Check whether the work tree has uncommitted changes.
     */
    public function hasUncommittedChanges(): bool
    {
        return trim($this->run('git status --porcelain')->output()) !== '';
    }

    /**
     * Get the most recent commit as a one-line summary.
     */
    public function lastCommit(): string
    {
        return trim($this->run('git log --oneline -1', quiet: true)->output());
    }

    /**
     * Get the subject of the most recent commit.
     */
    public function lastCommitSubject(): string
    {
        return trim($this->run('git log --format=%s -1')->output());
    }

    /**
     * List all local branches.
     *
     * @return array<int, string>
     */
    public function branches(): array
    {
        $result = $this->run('git branch --format="%(refname:short)"');

        if (! $result->successful()) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", trim($result->output())))));
    }

    /**
     * Count commits on the branch that are not yet on the default branch.
     *
     * Compares against origin/<default> when available, since the local
     * default branch may lag behind the remote.
     */
    public function commitsAheadOfDefault(string $branch, string $defaultBranch): int
    {
        $base = $this->resolveDefaultBranchRef($defaultBranch);

        $result = $this->run(sprintf(
            'git rev-list --count --no-merges %s..%s',
            escapeshellarg($base),
            escapeshellarg($branch),
        ), quiet: true);

        if (! $result->successful()) {
            return 0;
        }

        return (int) trim($result->output());
    }

    /**
     * Count commits on the default branch that the branch has not picked up yet.
     *
     * Merge commits are excluded. Merging a pull request with a merge commit
     * puts one on the default branch that the source branch never sees, even
     * though it carries no content the branch is missing.
     */
    public function commitsBehindDefault(string $branch, string $defaultBranch): int
    {
        $base = $this->resolveDefaultBranchRef($defaultBranch);

        $result = $this->run(sprintf(
            'git rev-list --count --no-merges %s..%s',
            escapeshellarg($branch),
            escapeshellarg($base),
        ), quiet: true);

        if (! $result->successful()) {
            return 0;
        }

        return (int) trim($result->output());
    }

    /**
     * Merge the default branch into the current branch.
     *
     * Merges rather than rebases so history is never rewritten and an
     * already-pushed branch never needs a force push.
     */
    public function mergeDefaultBranch(string $defaultBranch): ProcessResult
    {
        return $this->run(sprintf(
            'git merge %s --no-edit',
            escapeshellarg($this->resolveDefaultBranchRef($defaultBranch)),
        ), timeout: 60);
    }

    /**
     * Abandon an in-progress merge and restore the pre-merge state.
     */
    public function abortMerge(): ProcessResult
    {
        return $this->run('git merge --abort', timeout: 30, quiet: true);
    }

    /**
     * Resolve the open pull request for a branch and whether GitHub will let it merge.
     *
     * Returns null when the branch has no open pull request. GitHub computes
     * mergeability lazily, so a freshly opened pull request reports the
     * "checking" state until a request has triggered the calculation.
     *
     * @return array{state: string, ready: bool, reason: string|null, url: string|null}|null
     */
    public function pullRequestStatus(string $branch): ?array
    {
        $result = $this->run(sprintf(
            'gh pr view %s --json state,url,mergeable,mergeStateStatus,isDraft',
            escapeshellarg($branch),
        ), timeout: 15, quiet: true);

        if (! $result->successful()) {
            return null;
        }

        /** @var array{state?: string, url?: string, mergeable?: string, mergeStateStatus?: string, isDraft?: bool}|null $data */
        $data = json_decode(trim($result->output()), true);

        if (! is_array($data) || ($data['state'] ?? '') !== 'OPEN') {
            return null;
        }

        $state = $this->resolveMergeState($data);

        return [
            'state' => $state,
            'ready' => in_array($state, ['clean', 'unstable'], true),
            'reason' => self::MERGE_STATE_REASONS[$state] ?? null,
            'url' => $data['url'] ?? null,
        ];
    }

    /**
     * Map the GitHub merge state onto the states the dashboard understands.
     *
     * @param  array{mergeable?: string, mergeStateStatus?: string, isDraft?: bool}  $data
     */
    private function resolveMergeState(array $data): string
    {
        $mergeable = $data['mergeable'] ?? 'UNKNOWN';
        $mergeStateStatus = $data['mergeStateStatus'] ?? 'UNKNOWN';

        return match (true) {
            ($data['isDraft'] ?? false) || $mergeStateStatus === 'DRAFT' => 'draft',
            $mergeable === 'CONFLICTING' || $mergeStateStatus === 'DIRTY' => 'conflicting',
            $mergeStateStatus === 'BEHIND' => 'behind',
            $mergeStateStatus === 'BLOCKED' => 'blocked',
            $mergeStateStatus === 'UNSTABLE' => 'unstable',
            $mergeable === 'UNKNOWN' || $mergeStateStatus === 'UNKNOWN' => 'checking',
            $mergeable === 'MERGEABLE' => 'clean',
            default => 'checking',
        };
    }

    /**
     * Fetch all remotes and prune deleted branches.
     */
    public function fetchAllRemotes(): ProcessResult
    {
        return $this->run('git fetch --all --prune', timeout: 30);
    }

    /**
     * Refresh the recorded default branch of the origin remote.
     */
    public function updateRemoteHead(): ProcessResult
    {
        return $this->run('git remote set-head origin --auto', timeout: 10);
    }

    /**
     * Pull the current branch, refusing anything but a fast-forward.
     */
    public function pullFastForwardOnly(): ProcessResult
    {
        return $this->run('git pull --ff-only', timeout: 30);
    }

    /**
     * Stage every change in the work tree.
     */
    public function stageAll(): ProcessResult
    {
        return $this->run('git add --all', timeout: 30);
    }

    /**
     * Commit the staged changes.
     */
    public function commit(string $message): ProcessResult
    {
        return $this->run(sprintf('git commit -m %s', escapeshellarg($message)), timeout: 30);
    }

    /**
     * Pull the current branch, rebasing local commits on top.
     */
    public function pullRebase(): ProcessResult
    {
        return $this->run('git pull --rebase', timeout: 60);
    }

    /**
     * Push the current branch to its remote.
     */
    public function push(): ProcessResult
    {
        return $this->run('git push', timeout: 60);
    }

    /**
     * Check out an existing branch.
     */
    public function checkout(string $branch): ProcessResult
    {
        return $this->run(sprintf('git checkout %s', escapeshellarg($branch)), timeout: 15);
    }

    /**
     * Create a new branch and switch to it.
     */
    public function createBranch(string $branch): ProcessResult
    {
        return $this->run(sprintf('git checkout -b %s', escapeshellarg($branch)), timeout: 15);
    }

    /**
     * Pull the current branch from its remote.
     */
    public function pull(): ProcessResult
    {
        return $this->run('git pull', timeout: 30);
    }

    /**
     * Fast-forward a local branch from origin without checking it out.
     *
     * Fails when the local branch has diverged from the remote.
     */
    public function fastForwardFromOrigin(string $branch): bool
    {
        return $this->run(sprintf(
            'git fetch origin %s:%s',
            escapeshellarg($branch),
            escapeshellarg($branch),
        ), timeout: 60, quiet: true)->successful();
    }

    /**
     * Open a pull request from the branch into the base branch.
     */
    public function createPullRequest(string $base, string $branch, string $title): ProcessResult
    {
        return $this->run(sprintf(
            'gh pr create --base %s --head %s --title %s --body %s',
            escapeshellarg($base),
            escapeshellarg($branch),
            escapeshellarg($title),
            escapeshellarg(''),
        ), timeout: 30);
    }

    /**
     * Merge the open pull request for a branch.
     */
    public function mergePullRequest(string $branch): ProcessResult
    {
        return $this->run(sprintf('gh pr merge %s --merge', escapeshellarg($branch)), timeout: 30);
    }

    /**
     * Detect the default branch for the repository.
     */
    public function defaultBranch(): string
    {
        // GitHub CLI is authoritative for the default branch
        $ghResult = $this->run('gh repo view --json defaultBranchRef -q .defaultBranchRef.name', timeout: 10, quiet: true);

        if ($ghResult->successful() && trim($ghResult->output()) !== '') {
            return trim($ghResult->output());
        }

        // Fall back to git symbolic-ref (may be stale)
        $result = $this->run('git symbolic-ref refs/remotes/origin/HEAD', quiet: true);

        if ($result->successful()) {
            return str_replace('refs/remotes/origin/', '', trim($result->output()));
        }

        // Final fallback: check branch names
        $branches = trim($this->run('git branch -a')->output());

        return str_contains($branches, 'main') ? 'main' : 'master';
    }

    /**
     * Resolve the ref to compare a branch against, preferring the remote.
     */
    private function resolveDefaultBranchRef(string $defaultBranch): string
    {
        $remoteRef = 'origin/'.$defaultBranch;

        $exists = $this->run(
            sprintf('git rev-parse --verify --quiet %s', escapeshellarg($remoteRef)),
            quiet: true,
        );

        return $exists->successful() ? $remoteRef : $defaultBranch;
    }

    /**
     * Run a git command inside the repository.
     */
    private function run(string $command, int $timeout = 5, bool $quiet = false): ProcessResult
    {
        if ($quiet) {
            $command .= ' '.HerdEnvironment::suppressStderr();
        }

        return Process::path($this->path)->env($this->env)->timeout($timeout)->run($command);
    }
}
