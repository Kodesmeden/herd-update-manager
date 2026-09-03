import { useEffect, useState } from 'react';
import type { RefObject } from 'react';
import { getCsrfToken } from '@/lib/utils';
import type { GitInfoData, PullRequestStatus } from '@/types/git';
import {
    branches as fetchBranches,
    createBranch,
    createPr,
    mergePr,
    prStatus as fetchPrStatus,
    switchBranch,
    syncWithDefault,
} from '@/actions/App/Http/Controllers/GitController';

// GitHub needs a moment to work out whether a branch can merge, so give it 30 seconds
const MAX_PR_STATUS_POLLS = 15;

export interface GitActionMessage {
    type: 'success' | 'warning' | 'error';
    text: string;
    url?: string;
}

interface UseGitActionsOptions {
    installationId: number;
    info: GitInfoData | null;
    onRefresh: () => void;
    dropdownRef: RefObject<HTMLDivElement | null>;
    branchInputRef: RefObject<HTMLInputElement | null>;
}

/**
 * Git state and actions for a single installation: branch switching, syncing
 * with the default branch and pull request handling.
 */
export function useGitActions({
    installationId,
    info,
    onRefresh,
    dropdownRef,
    branchInputRef,
}: UseGitActionsOptions) {
    const [actionLoading, setActionLoading] = useState(false);
    const [message, setMessage] = useState<GitActionMessage | null>(null);
    // undefined means "no override", so a polled null can still clear a closed pull request
    const [polledPullRequest, setPolledPullRequest] = useState<
        PullRequestStatus | null | undefined
    >(undefined);
    const [branchList, setBranchList] = useState<string[] | null>(null);
    const [branchDropdownOpen, setBranchDropdownOpen] = useState(false);
    const [showBranchInput, setShowBranchInput] = useState(false);
    const [newBranchName, setNewBranchName] = useState('');

    const pullRequest =
        polledPullRequest !== undefined
            ? polledPullRequest
            : (info?.pull_request ?? null);

    // GitHub computes mergeability lazily, so keep asking while it reports "checking"
    useEffect(() => {
        if (pullRequest?.state !== 'checking') {
            return;
        }

        let polls = 0;

        const timer = setInterval(async () => {
            polls += 1;

            if (polls > MAX_PR_STATUS_POLLS) {
                clearInterval(timer);

                return;
            }

            try {
                const res = await fetch(fetchPrStatus.url(installationId));
                const data = await res.json();
                setPolledPullRequest(data.pull_request ?? null);
            } catch {
                clearInterval(timer);
            }
        }, 2000);

        return () => clearInterval(timer);
    }, [pullRequest?.state, installationId]);

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
    }, [branchDropdownOpen, dropdownRef]);

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
        setBranchDropdownOpen(false);

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

    const cancelNewBranch = () => {
        setShowBranchInput(false);
        setNewBranchName('');
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

    const handleSyncWithDefault = async () => {
        if (!info) {
            return;
        }

        setActionLoading(true);
        setMessage(null);

        try {
            const res = await fetch(syncWithDefault.url(installationId), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
            });
            const data = await res.json();

            if (data.success) {
                setMessage({ type: 'success', text: data.message });
                onRefresh();
            } else {
                setMessage({
                    type: 'error',
                    text: data.error || 'Failed to update branch',
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
                setPolledPullRequest(undefined);
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
        if (!info) {
            return;
        }

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
                    type: data.warning ? 'warning' : 'success',
                    text: data.warning
                        ? `PR merged. ${data.warning}`
                        : `PR merged. Local ${info.default_branch} updated.`,
                    url: data.pr_url,
                });
                setPolledPullRequest(undefined);
                onRefresh();
            } else {
                setMessage({
                    type: 'error',
                    text: data.error || 'Failed to merge',
                    url: data.pr_url,
                });

                // The dashboard state was stale, so pull the real one back in
                setPolledPullRequest(undefined);
                onRefresh();
            }
        } catch {
            setMessage({ type: 'error', text: 'Request failed' });
        }

        setActionLoading(false);
    };

    return {
        actionLoading,
        message,
        dismissMessage: () => setMessage(null),
        pullRequest,
        branchList,
        branchDropdownOpen,
        showBranchInput,
        newBranchName,
        setNewBranchName,
        handleBranchClick,
        handleSwitchBranch,
        handleNewBranchClick,
        cancelNewBranch,
        handleCreateBranch,
        handleSyncWithDefault,
        handleCreatePr,
        handleMergePr,
    };
}
