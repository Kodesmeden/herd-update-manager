import { router } from '@inertiajs/react';
import {
    ArrowDownToLine,
    Check,
    ChevronDown,
    ExternalLink,
    Eye,
    EyeOff,
    FileText,
    GitBranch,
    GitMerge,
    GitPullRequest,
    RefreshCw,
    Upload,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import SimpleTooltip from '@/components/ui/simple-tooltip';
import { Skeleton } from '@/components/ui/skeleton';
import { useGitActions } from '@/hooks/use-git-actions';
import type { InstallationMeta } from '@/types/git';
import { show as fetchMeta } from '@/actions/App/Http/Controllers/AppInfoController';
import {
    dismiss,
    hide,
    unhide,
} from '@/actions/App/Http/Controllers/InstallationController';

export interface Installation {
    id: number;
    name: string;
    path: string;
    hidden: boolean;
    status: 'idle' | 'running' | 'pushing' | 'completed' | 'failed';
    progress: number;
    current_step: string | null;
    output: string | null;
    last_updated_at: string | null;
}

interface InstallationRowProps {
    installation: Installation;
    meta: InstallationMeta | null;
    herdPath: string;
    columns: string;
    selected: boolean;
    onSelect: (installationId: number) => void;
    onMeta: (installationId: number, meta: InstallationMeta) => void;
    onUpdate: (installation: Installation) => void;
    onPush: (installation: Installation) => void;
}

/**
 * Short "12.4" style version, or null when the app is not a Laravel project.
 */
function shortLaravelVersion(version: string | undefined): string | null {
    if (!version || version === 'Unknown') {
        return null;
    }

    return version.split('.').slice(0, 2).join('.');
}

export default function InstallationRow({
    installation,
    meta,
    herdPath,
    columns,
    selected,
    onSelect,
    onMeta,
    onUpdate,
    onPush,
}: InstallationRowProps) {
    const [showLog, setShowLog] = useState(false);
    const [metaLoading, setMetaLoading] = useState(meta === null);
    const prevStatus = useRef(installation.status);
    const prevOutput = useRef(installation.output);
    const dropdownRef = useRef<HTMLDivElement>(null);
    const branchInputRef = useRef<HTMLInputElement>(null);

    const loadMeta = useCallback(() => {
        fetch(fetchMeta.url(installation.id))
            .then((res) => res.json())
            .then((data: InstallationMeta) => {
                onMeta(installation.id, data);
                setMetaLoading(false);
            })
            .catch(() => setMetaLoading(false));
    }, [installation.id, onMeta]);

    useEffect(() => {
        loadMeta();
    }, [loadMeta]);

    // Re-fetch meta when status transitions from busy to done, or when output changes
    useEffect(() => {
        const wasBusy =
            prevStatus.current === 'running' ||
            prevStatus.current === 'pushing';
        const nowDone =
            installation.status !== 'running' &&
            installation.status !== 'pushing';

        if (wasBusy && nowDone) {
            loadMeta();
        }

        prevStatus.current = installation.status;
    }, [installation.status, loadMeta]);

    useEffect(() => {
        if (installation.output !== prevOutput.current) {
            prevOutput.current = installation.output;
            loadMeta();
        }
    }, [installation.output, loadMeta]);

    const info = meta?.git ?? null;
    const isBusy =
        installation.status === 'running' || installation.status === 'pushing';
    const displayName = meta?.app_name || installation.name;
    const laravelVersion = shortLaravelVersion(meta?.laravel_version);

    const git = useGitActions({
        installationId: installation.id,
        info,
        onRefresh: loadMeta,
        dropdownRef,
        branchInputRef,
    });

    function handleHide() {
        router.patch(hide.url(installation.id), {}, { preserveScroll: true });
    }

    function handleUnhide() {
        router.patch(unhide.url(installation.id), {}, { preserveScroll: true });
    }

    function handleDismiss() {
        router.patch(
            dismiss.url(installation.id),
            {},
            { preserveScroll: true },
        );
    }

    const isRepo = info !== null && info.is_git_repo;
    const githubUrl =
        isRepo && info.remote_url
            ? info.remote_url
                  .replace(/\.git$/, '')
                  .replace(/^git@github\.com:/, 'https://github.com/')
            : null;

    const syncDisabled =
        isBusy || git.actionLoading || (info?.has_changes ?? false);

    const prBlockedReason = !isRepo
        ? 'Not a git repository'
        : info.is_main_branch
          ? `Already on ${info.default_branch}`
          : !info.ahead_of_default
            ? `No commits ahead of ${info.default_branch}`
            : null;

    const state = (() => {
        if (!info) {
            return {
                label: 'Loading',
                tone: 'text-muted-foreground',
                dot: 'bg-muted-foreground/30',
            };
        }

        if (!info.is_git_repo) {
            return {
                label: 'No git repo',
                tone: 'text-muted-foreground',
                dot: 'bg-muted-foreground/30',
            };
        }

        if (installation.status === 'failed') {
            return {
                label: 'Failed',
                tone: 'text-red-600 dark:text-red-400',
                dot: 'bg-red-500',
            };
        }

        if (installation.status === 'completed') {
            return {
                label: 'Updated',
                tone: 'text-green-700 dark:text-green-400',
                dot: 'bg-green-500',
            };
        }

        if (info.has_changes) {
            return {
                label: 'Uncommitted',
                tone: 'text-amber-700 dark:text-amber-400',
                dot: 'bg-amber-500',
            };
        }

        if (git.pullRequest) {
            return git.pullRequest.state === 'checking'
                ? {
                      label: 'PR checking',
                      tone: 'text-muted-foreground',
                      dot: 'bg-muted-foreground/40',
                  }
                : {
                      label: 'PR ready',
                      tone: 'text-green-700 dark:text-green-400',
                      dot: 'bg-green-500',
                  };
        }

        if (info.behind_default > 0) {
            return {
                label: `${info.behind_default} behind`,
                tone: 'text-brand',
                dot: 'bg-brand',
            };
        }

        return {
            label: 'Clean',
            tone: 'text-muted-foreground',
            dot: 'bg-muted-foreground/30',
        };
    })();

    const dismissable =
        installation.status === 'completed' || installation.status === 'failed';

    return (
        <div
            className={`border-b border-border/60 transition-colors last:border-b-0 ${
                selected ? 'bg-brand/5' : 'hover:bg-muted/40'
            } ${installation.hidden ? 'opacity-60' : ''}`}
        >
            <div className={`${columns} min-h-12 px-3.5`}>
                <Checkbox
                    aria-label={`Select ${displayName}`}
                    checked={selected}
                    onCheckedChange={() => onSelect(installation.id)}
                />

                <div className="min-w-0">
                    <div className="truncate text-[13.5px] font-semibold">
                        {displayName}
                    </div>
                    <div className="truncate font-mono text-[11px] text-muted-foreground/70">
                        {herdPath}/{installation.name}
                    </div>
                </div>

                <span className="font-mono text-[11.5px] text-muted-foreground">
                    {metaLoading && !meta ? (
                        <Skeleton className="h-3.5 w-8" />
                    ) : (
                        (laravelVersion ?? '—')
                    )}
                </span>

                <div
                    className="relative flex min-w-0 items-center gap-2"
                    ref={dropdownRef}
                >
                    {!info && metaLoading && <Skeleton className="h-5 w-28" />}

                    {info && !info.is_git_repo && (
                        <span className="font-mono text-[11.5px] text-muted-foreground/70">
                            no git remote
                        </span>
                    )}

                    {isRepo && !git.showBranchInput && (
                        <SimpleTooltip
                            content={
                                info.has_changes
                                    ? 'Commit changes before switching'
                                    : 'Switch branch'
                            }
                        >
                            <button
                                onClick={git.handleBranchClick}
                                disabled={isBusy || git.actionLoading}
                                className={`inline-flex max-w-[92%] cursor-pointer items-center gap-1.5 rounded-md border px-2 py-0.5 font-mono text-[11.5px] transition-colors disabled:cursor-not-allowed disabled:opacity-50 ${
                                    info.is_main_branch
                                        ? 'border-border bg-muted/60 text-muted-foreground hover:bg-muted'
                                        : 'border-amber-500/30 bg-amber-500/10 text-amber-700 hover:bg-amber-500/15 dark:text-amber-400'
                                }`}
                            >
                                <span className="truncate">{info.branch}</span>
                                <ChevronDown
                                    className={`h-3 w-3 shrink-0 opacity-60 transition-transform ${git.branchDropdownOpen ? 'rotate-180' : ''}`}
                                />
                            </button>
                        </SimpleTooltip>
                    )}

                    {git.showBranchInput && (
                        <div className="flex items-center gap-1">
                            <Input
                                aria-label="New branch name"
                                ref={branchInputRef}
                                value={git.newBranchName}
                                onChange={(e) =>
                                    git.setNewBranchName(e.target.value)
                                }
                                onKeyDown={(e) => {
                                    if (
                                        e.key === 'Enter' &&
                                        git.newBranchName.trim()
                                    ) {
                                        git.handleCreateBranch();
                                    }

                                    if (e.key === 'Escape') {
                                        git.cancelNewBranch();
                                    }
                                }}
                                className="h-7 w-40 font-mono text-xs"
                                placeholder="Branch name..."
                            />
                            <Button
                                variant="ghost"
                                size="icon"
                                className="h-7 w-7"
                                onClick={git.handleCreateBranch}
                                disabled={!git.newBranchName.trim()}
                            >
                                <Check className="h-3.5 w-3.5" />
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="h-7 w-7"
                                onClick={git.cancelNewBranch}
                            >
                                <X className="h-3.5 w-3.5" />
                            </Button>
                        </div>
                    )}

                    {git.branchDropdownOpen && git.branchList && (
                        <div className="absolute top-full left-0 z-50 mt-1 flex min-w-52 flex-col rounded-lg border bg-popover p-1 shadow-md">
                            {git.branchList.map((branch) => (
                                <button
                                    key={branch}
                                    onClick={() =>
                                        git.handleSwitchBranch(branch)
                                    }
                                    className={`cursor-pointer rounded-md px-2.5 py-1.5 text-left font-mono text-[11.5px] transition-colors hover:bg-accent ${
                                        branch === info?.branch
                                            ? 'font-medium text-foreground'
                                            : 'text-muted-foreground'
                                    }`}
                                >
                                    {branch}
                                    {branch === info?.branch && ' (current)'}
                                </button>
                            ))}

                            {isRepo && info.is_main_branch && (
                                <>
                                    <div className="my-1 h-px bg-border" />
                                    <button
                                        onClick={git.handleNewBranchClick}
                                        className="inline-flex cursor-pointer items-center gap-2 rounded-md px-2.5 py-1.5 text-left text-[12.5px] font-medium text-brand transition-colors hover:bg-accent"
                                    >
                                        <GitBranch className="h-3.5 w-3.5" />
                                        New branch
                                    </button>
                                </>
                            )}
                        </div>
                    )}
                </div>

                <div className="min-w-0">
                    {isBusy ? (
                        <div>
                            <div className="h-[3px] w-24 overflow-hidden rounded-full bg-muted">
                                <div
                                    className="h-full rounded-full bg-brand transition-all duration-500 ease-out"
                                    style={{
                                        width: `${installation.progress}%`,
                                    }}
                                />
                            </div>
                            <p className="mt-1 truncate font-mono text-[10.5px] text-muted-foreground">
                                {installation.status === 'pushing'
                                    ? 'pushing…'
                                    : installation.current_step
                                      ? `${installation.current_step}…`
                                      : 'working…'}
                            </p>
                        </div>
                    ) : (
                        <span
                            className={`inline-flex items-center gap-2 text-[12.5px] ${state.tone}`}
                        >
                            <span
                                className={`size-1.5 shrink-0 rounded-full ${state.dot}`}
                            />
                            {state.label}
                            {dismissable && (
                                <SimpleTooltip content="Dismiss">
                                    <button
                                        onClick={handleDismiss}
                                        className="cursor-pointer rounded p-0.5 text-muted-foreground/70 transition-colors hover:text-foreground"
                                    >
                                        <X className="h-3 w-3" />
                                    </button>
                                </SimpleTooltip>
                            )}
                        </span>
                    )}
                </div>

                <span className="truncate pr-3 font-mono text-[11.5px] text-muted-foreground">
                    {info
                        ? info.is_git_repo
                            ? info.last_commit
                            : 'Not a git repository'
                        : ''}
                </span>

                <div className="flex items-center justify-end gap-1.5">
                    <div className="flex w-44 items-center justify-end gap-1">
                        {isRepo && git.pullRequest && (
                            <SimpleTooltip
                                content={
                                    git.pullRequest.reason ??
                                    `Merge this pull request into ${info.default_branch}`
                                }
                            >
                                <span className="inline-flex">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="h-7 px-2.5"
                                        onClick={git.handleMergePr}
                                        disabled={
                                            isBusy ||
                                            git.actionLoading ||
                                            !git.pullRequest.ready
                                        }
                                    >
                                        <GitMerge
                                            className={`h-3.5 w-3.5 ${git.pullRequest.state === 'checking' ? 'animate-pulse' : ''}`}
                                        />
                                        {git.pullRequest.state === 'checking'
                                            ? 'Checking...'
                                            : 'Merge PR'}
                                    </Button>
                                </span>
                            </SimpleTooltip>
                        )}

                        {isRepo && !git.pullRequest && !info.is_main_branch && (
                            <SimpleTooltip
                                content={
                                    prBlockedReason ??
                                    `Create a pull request into ${info.default_branch}`
                                }
                            >
                                <span className="inline-flex">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="h-7 px-2.5"
                                        onClick={git.handleCreatePr}
                                        disabled={
                                            isBusy ||
                                            git.actionLoading ||
                                            prBlockedReason !== null
                                        }
                                    >
                                        <GitPullRequest className="h-3.5 w-3.5" />
                                        Create PR
                                    </Button>
                                </span>
                            </SimpleTooltip>
                        )}

                        {isRepo && !info.is_main_branch && (
                            <SimpleTooltip
                                content={
                                    info.has_changes
                                        ? `Commit or stash changes before updating from ${info.default_branch}`
                                        : info.behind_default > 0
                                          ? `${info.default_branch} is ${info.behind_default} commit${info.behind_default === 1 ? '' : 's'} ahead of ${info.branch}`
                                          : `Merge any new commits from ${info.default_branch} into ${info.branch}`
                                }
                            >
                                <span className="inline-flex">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className={`h-7 px-2 ${info.behind_default > 0 && !syncDisabled ? 'text-brand' : ''}`}
                                        onClick={git.handleSyncWithDefault}
                                        disabled={syncDisabled}
                                    >
                                        <ArrowDownToLine className="h-3.5 w-3.5" />
                                        {info.behind_default > 0
                                            ? `Sync (${info.behind_default})`
                                            : ''}
                                    </Button>
                                </span>
                            </SimpleTooltip>
                        )}
                    </div>

                    <div className="flex items-center gap-0.5">
                        {isRepo && info.has_changes ? (
                            <SimpleTooltip content="Commit and push">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="h-7 w-7"
                                    onClick={() => onPush(installation)}
                                    disabled={isBusy}
                                >
                                    <Upload className="h-3.5 w-3.5" />
                                </Button>
                            </SimpleTooltip>
                        ) : (
                            <span className="h-7 w-7" />
                        )}

                        <SimpleTooltip content="Update packages">
                            <Button
                                variant="ghost"
                                size="icon"
                                className="h-7 w-7"
                                onClick={() => onUpdate(installation)}
                                disabled={isBusy}
                            >
                                <RefreshCw
                                    className={`h-3.5 w-3.5 ${isBusy ? 'animate-spin' : ''}`}
                                />
                            </Button>
                        </SimpleTooltip>

                        {installation.output ? (
                            <SimpleTooltip
                                content={showLog ? 'Hide log' : 'Show log'}
                            >
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className={`h-7 w-7 ${showLog ? 'bg-accent text-foreground' : ''}`}
                                    onClick={() => setShowLog(!showLog)}
                                >
                                    <FileText className="h-3.5 w-3.5" />
                                </Button>
                            </SimpleTooltip>
                        ) : (
                            <span className="h-7 w-7" />
                        )}

                        <SimpleTooltip
                            content={
                                installation.hidden
                                    ? 'Unhide'
                                    : 'Hide from list'
                            }
                        >
                            <Button
                                variant="ghost"
                                size="icon"
                                className="h-7 w-7"
                                onClick={
                                    installation.hidden
                                        ? handleUnhide
                                        : handleHide
                                }
                                disabled={isBusy}
                            >
                                {installation.hidden ? (
                                    <Eye className="h-3.5 w-3.5" />
                                ) : (
                                    <EyeOff className="h-3.5 w-3.5" />
                                )}
                            </Button>
                        </SimpleTooltip>

                        {githubUrl ? (
                            <SimpleTooltip content="Open on GitHub">
                                <a
                                    href={githubUrl}
                                    target="_blank"
                                    rel="noopener"
                                    className="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                                >
                                    <ExternalLink className="h-3.5 w-3.5" />
                                </a>
                            </SimpleTooltip>
                        ) : (
                            <span className="h-7 w-7" />
                        )}
                    </div>
                </div>
            </div>

            {git.message && (
                <p
                    className={`mx-3.5 mb-2.5 ml-[52px] rounded-lg px-3 py-2 text-xs ${
                        git.message.type === 'success'
                            ? 'bg-green-500/10 text-green-700 dark:text-green-400'
                            : git.message.type === 'warning'
                              ? 'bg-amber-500/10 text-amber-700 dark:text-amber-400'
                              : 'bg-red-500/10 text-red-700 dark:text-red-400'
                    }`}
                >
                    {git.message.text}
                    {git.message.url && (
                        <>
                            {' '}
                            <a
                                href={git.message.url}
                                target="_blank"
                                rel="noopener"
                                className="underline hover:no-underline"
                            >
                                View on GitHub
                            </a>
                        </>
                    )}
                </p>
            )}

            {showLog && installation.output && (
                <pre className="mx-3.5 mb-3 ml-[52px] max-h-64 overflow-auto rounded-lg border bg-muted/40 px-3.5 py-3 font-mono text-[11.5px] leading-relaxed whitespace-pre-wrap">
                    {installation.output}
                </pre>
            )}
        </div>
    );
}
