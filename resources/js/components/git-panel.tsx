import {
    ArrowUpFromLine,
    Check,
    ChevronDown,
    ExternalLink,
    GitBranch,
    GitMerge,
    GitPullRequest,
    Upload,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import SimpleTooltip from '@/components/ui/simple-tooltip';
import { Skeleton } from '@/components/ui/skeleton';
import { getCsrfToken } from '@/lib/utils';
import {
    branches as fetchBranches,
    createBranch,
    createPr,
    mergePr,
    switchBranch,
} from '@/actions/App/Http/Controllers/GitController';

export interface GitInfoData {
    is_git_repo: boolean;
    branch: string;
    remote_url: string;
    has_changes: boolean;
    last_commit: string;
    is_main_branch: boolean;
    default_branch: string;
    has_open_pr: boolean;
    ahead_of_default: boolean;
}

interface GitPanelProps {
    installationId: number;
    gitInfo: GitInfoData | null;
    loading: boolean;
    isBusy: boolean;
    onPushQuick: () => void;
    onPushWithMessage: () => void;
    onRefresh: () => void;
}

export default function GitPanel({
    installationId,
    gitInfo: info,
    loading,
    isBusy,
    onPushQuick,
    onPushWithMessage,
    onRefresh,
}: GitPanelProps) {
    const [actionLoading, setActionLoading] = useState(false);
    const [message, setMessage] = useState<{
        type: 'success' | 'error';
        text: string;
        url?: string;
    } | null>(null);
    const [branchList, setBranchList] = useState<string[] | null>(null);
    const [branchDropdownOpen, setBranchDropdownOpen] = useState(false);
    const [showBranchInput, setShowBranchInput] = useState(false);
    const [newBranchName, setNewBranchName] = useState('');
    const dropdownRef = useRef<HTMLDivElement>(null);
    const branchInputRef = useRef<HTMLInputElement>(null);

    // Close dropdown on outside click
    useEffect(() => {
        if (!branchDropdownOpen) {
            return;
        }

        const handler = (e: MouseEvent) => {
            if (
                dropdownRef.current &&
                !dropdownRef.current.contains(e.target as Node)
            ) {
                setBranchDropdownOpen(false);
            }
        };
        document.addEventListener('mousedown', handler);

        return () => document.removeEventListener('mousedown', handler);
    }, [branchDropdownOpen]);

    const handleBranchClick = async () => {
        if (info?.has_changes) {
            setMessage({
                type: 'error',
                text: 'Commit or stash changes before switching branch',
            });

            return;
        }

        if (branchDropdownOpen) {
            setBranchDropdownOpen(false);

            return;
        }

        if (!branchList) {
            const res = await fetch(fetchBranches.url(installationId));
            const data = await res.json();
            setBranchList(data.branches || []);
        }

        setBranchDropdownOpen(true);
    };

    const handleSwitchBranch = async (branch: string) => {
        setBranchDropdownOpen(false);

        if (branch === info?.branch) {
            return;
        }

        setActionLoading(true);
        setMessage(null);

        try {
            const res = await fetch(switchBranch.url(installationId), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({ branch }),
            });
            const data = await res.json();

            if (data.success) {
                setMessage({
                    type: 'success',
                    text: `Switched to "${data.branch}"`,
                });
                onRefresh();
            } else {
                setMessage({
                    type: 'error',
                    text: data.error || 'Failed to switch branch',
                });
            }
        } catch {
            setMessage({ type: 'error', text: 'Request failed' });
        }

        setActionLoading(false);
    };

    const handleNewBranchClick = async () => {
        if (!branchList) {
            const res = await fetch(fetchBranches.url(installationId));
            const data = await res.json();
            setBranchList(data.branches || []);
            const branches: string[] = data.branches || [];
            setNewBranchName(
                branches.includes('develop')
                    ? `updates/${new Date().toISOString().slice(0, 10)}`
                    : 'develop',
            );
        } else {
            setNewBranchName(
                branchList.includes('develop')
                    ? `updates/${new Date().toISOString().slice(0, 10)}`
                    : 'develop',
            );
        }

        setShowBranchInput(true);
        setTimeout(() => branchInputRef.current?.select(), 0);
    };

    const handleCreateBranch = async () => {
        const name = newBranchName.trim();

        if (!name) {
            return;
        }

        setActionLoading(true);
        setMessage(null);
        setShowBranchInput(false);

        try {
            const res = await fetch(createBranch.url(installationId), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({ branch: name }),
            });
            const data = await res.json();

            if (data.success) {
                setMessage({
                    type: 'success',
                    text: `Switched to "${data.branch}"`,
                });
                setBranchList(null);
                setNewBranchName('');
                onRefresh();
            } else {
                setMessage({
                    type: 'error',
                    text: data.error || 'Failed to create branch',
                });
            }
        } catch {
            setMessage({ type: 'error', text: 'Request failed' });
        }

        setActionLoading(false);
    };

    const handleCreatePr = async () => {
        setActionLoading(true);
        setMessage(null);

        try {
            const res = await fetch(createPr.url(installationId), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
            });
            const data = await res.json();

            if (data.success) {
                setMessage({
                    type: 'success',
                    text: 'PR created',
                    url: data.pr_url,
                });
                onRefresh();
            } else {
                setMessage({
                    type: 'error',
                    text: data.error || 'Failed to create PR',
                    url: data.pr_url,
                });
            }
        } catch {
            setMessage({ type: 'error', text: 'Request failed' });
        }

        setActionLoading(false);
    };

    const handleMergePr = async () => {
        setActionLoading(true);
        setMessage(null);

        try {
            const res = await fetch(mergePr.url(installationId), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
            });
            const data = await res.json();

            if (data.success && data.merged) {
                setMessage({
                    type: 'success',
                    text: 'PR merged',
                    url: data.pr_url,
                });
                onRefresh();
            } else if (data.has_conflicts) {
                setMessage({
                    type: 'error',
                    text: 'Merge conflicts - resolve on GitHub',
                    url: data.pr_url,
                });
            } else {
                setMessage({
                    type: 'error',
                    text: data.error || 'Failed to merge',
                    url: data.pr_url,
                });
            }
        } catch {
            setMessage({ type: 'error', text: 'Request failed' });
        }

        setActionLoading(false);
    };

    if (loading) {
        return (
            <div className="flex items-center gap-3 border-t px-4 py-2.5">
                <Skeleton className="h-5 w-16" />
                <Skeleton className="h-5 w-40" />
            </div>
        );
    }

    if (!info || !info.is_git_repo) {
        return (
            <div className="border-t px-4 py-2.5 text-xs text-muted-foreground">
                Not a git repository
            </div>
        );
    }

    const githubUrl = info.remote_url
        ? info.remote_url
              .replace(/\.git$/, '')
              .replace(/^git@github\.com:/, 'https://github.com/')
        : null;

    return (
        <div className="border-t px-4 py-2.5">
            <div className="flex flex-wrap items-center gap-2">
                <div className="relative" ref={dropdownRef}>
                    <SimpleTooltip
                        content={
                            info.has_changes
                                ? 'Commit changes before switching'
                                : 'Switch branch'
                        }
                    >
                        <button
                            onClick={handleBranchClick}
                            disabled={isBusy || actionLoading}
                            className="inline-flex cursor-pointer items-center gap-1 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <Badge
                                className={
                                    info.is_main_branch
                                        ? 'bg-green-600 text-white'
                                        : 'bg-amber-600 text-white'
                                }
                            >
                                {info.branch}
                            </Badge>
                            <ChevronDown
                                className={`h-3 w-3 text-muted-foreground transition-transform ${branchDropdownOpen ? 'rotate-180' : ''}`}
                            />
                        </button>
                    </SimpleTooltip>

                    {branchDropdownOpen && branchList && (
                        <div className="absolute top-full left-0 z-50 mt-1 min-w-40 rounded-lg border bg-popover shadow-md">
                            {branchList.map((branch) => (
                                <button
                                    key={branch}
                                    onClick={() => handleSwitchBranch(branch)}
                                    className={`block w-full cursor-pointer px-3 py-1.5 text-left text-sm transition-colors hover:bg-accent ${
                                        branch === info.branch
                                            ? 'font-medium text-foreground'
                                            : 'text-muted-foreground'
                                    } first:rounded-t-lg last:rounded-b-lg`}
                                >
                                    {branch}
                                    {branch === info.branch && ' (current)'}
                                </button>
                            ))}
                        </div>
                    )}
                </div>

                {info.has_changes && (
                    <SimpleTooltip content="Uncommitted changes">
                        <span className="size-2 shrink-0 rounded-full bg-amber-500" />
                    </SimpleTooltip>
                )}

                <span className="max-w-64 truncate text-xs text-muted-foreground">
                    {info.last_commit}
                </span>

                <div className="ml-auto flex items-center gap-1">
                    {info.has_changes && (
                        <>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={onPushQuick}
                                disabled={isBusy}
                            >
                                <ArrowUpFromLine className="h-3.5 w-3.5" />
                                Quick Push
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={onPushWithMessage}
                                disabled={isBusy}
                            >
                                <Upload className="h-3.5 w-3.5" />
                                Commit & Push
                            </Button>
                        </>
                    )}

                    {info.is_main_branch ? (
                        showBranchInput ? (
                            <div className="flex items-center gap-1">
                                <Input
                                    aria-label="New branch name"
                                    ref={branchInputRef}
                                    value={newBranchName}
                                    onChange={(e) =>
                                        setNewBranchName(e.target.value)
                                    }
                                    onKeyDown={(e) => {
                                        if (
                                            e.key === 'Enter' &&
                                            newBranchName.trim()
                                        ) {
                                            handleCreateBranch();
                                        }

                                        if (e.key === 'Escape') {
                                            setShowBranchInput(false);
                                            setNewBranchName('');
                                        }
                                    }}
                                    className="h-7 w-44 text-xs"
                                    placeholder="Branch name..."
                                />
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="h-7 w-7"
                                    onClick={handleCreateBranch}
                                    disabled={!newBranchName.trim()}
                                >
                                    <Check className="h-3.5 w-3.5" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="h-7 w-7"
                                    onClick={() => {
                                        setShowBranchInput(false);
                                        setNewBranchName('');
                                    }}
                                >
                                    <X className="h-3.5 w-3.5" />
                                </Button>
                            </div>
                        ) : (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={handleNewBranchClick}
                                disabled={isBusy || actionLoading}
                            >
                                <GitBranch className="h-3.5 w-3.5" />
                                New branch
                            </Button>
                        )
                    ) : (
                        <>
                            {!info.has_open_pr && info.ahead_of_default && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={handleCreatePr}
                                    disabled={isBusy || actionLoading}
                                >
                                    <GitPullRequest className="h-3.5 w-3.5" />
                                    Create PR
                                </Button>
                            )}
                            {info.has_open_pr && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={handleMergePr}
                                    disabled={isBusy || actionLoading}
                                >
                                    <GitMerge className="h-3.5 w-3.5" />
                                    Merge PR
                                </Button>
                            )}
                        </>
                    )}

                    {githubUrl && (
                        <SimpleTooltip content="Open on GitHub">
                            <a
                                href={githubUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                            >
                                <ExternalLink className="h-3.5 w-3.5" />
                            </a>
                        </SimpleTooltip>
                    )}
                </div>
            </div>

            {message && (
                <p
                    className={`mt-1 text-xs ${message.type === 'success' ? 'text-green-600' : 'text-red-600'}`}
                >
                    {message.text}
                    {message.url && (
                        <>
                            {' '}
                            <a
                                href={message.url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="underline hover:no-underline"
                            >
                                View on GitHub
                            </a>
                        </>
                    )}
                </p>
            )}
        </div>
    );
}
