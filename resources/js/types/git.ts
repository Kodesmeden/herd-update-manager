export interface PullRequestStatus {
    state:
        | 'clean'
        | 'unstable'
        | 'checking'
        | 'conflicting'
        | 'draft'
        | 'behind'
        | 'blocked';
    ready: boolean;
    reason: string | null;
    url: string | null;
}

export interface GitInfoData {
    is_git_repo: boolean;
    branch: string;
    remote_url: string;
    has_changes: boolean;
    last_commit: string;
    is_main_branch: boolean;
    default_branch: string;
    pull_request: PullRequestStatus | null;
    ahead_of_default: boolean;
    behind_default: number;
}

export interface InstallationMeta {
    app_name: string | null;
    laravel_version: string;
    git: GitInfoData;
}
