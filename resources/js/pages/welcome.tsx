import { Head, router, usePoll } from '@inertiajs/react';
import { CloudDownload, RefreshCw, Search, Upload } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import InstallationRow from '@/components/installation-row';
import type { Installation } from '@/components/installation-row';
import InstallationsRail from '@/components/installations-rail';
import type { ViewKey } from '@/components/installations-rail';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import SimpleTooltip from '@/components/ui/simple-tooltip';
import { getCsrfToken } from '@/lib/utils';
import type { InstallationMeta } from '@/types/git';
import {
    fetchAll,
    hide,
    push,
    pushAll,
    update,
    updateAll,
} from '@/actions/App/Http/Controllers/InstallationController';

interface Props {
    installations: Installation[];
    showHidden: boolean;
    hiddenCount: number;
    herdPath: string;
}

type PushDialog =
    | { kind: 'one'; installation: Installation }
    | { kind: 'all' }
    | { kind: 'selected'; ids: number[] };

const DEFAULT_COMMIT_MESSAGE = 'Update packages';

const COLUMNS =
    'grid grid-cols-[24px_minmax(210px,1.6fr)_56px_minmax(150px,1.2fr)_116px_minmax(140px,1.8fr)_332px] items-center gap-x-3';

const VIEW_TITLES: Record<ViewKey, string> = {
    all: 'All sites',
    dirty: 'Uncommitted changes',
    behind: 'Behind main',
    pr: 'Open pull requests',
    hidden: 'Hidden',
};

export default function Welcome({
    installations,
    showHidden,
    hiddenCount,
    herdPath,
}: Props) {
    const [metaMap, setMetaMap] = useState<Record<number, InstallationMeta>>(
        {},
    );
    const [view, setView] = useState<ViewKey>(showHidden ? 'hidden' : 'all');
    const [query, setQuery] = useState('');
    const [selected, setSelected] = useState<number[]>([]);
    const [pushDialog, setPushDialog] = useState<PushDialog | null>(null);
    const [commitMessage, setCommitMessage] = useState(DEFAULT_COMMIT_MESSAGE);
    const [fetching, setFetching] = useState(false);
    const [lastFetch, setLastFetch] = useState<string | null>(null);

    const handleMeta = useCallback(
        (installationId: number, meta: InstallationMeta) => {
            setMetaMap((prev) => ({ ...prev, [installationId]: meta }));
        },
        [],
    );

    const visible = installations.filter((i) => !i.hidden);

    const counts: Record<ViewKey, number> = {
        all: visible.length,
        dirty: visible.filter((i) => metaMap[i.id]?.git?.has_changes).length,
        behind: visible.filter(
            (i) => (metaMap[i.id]?.git?.behind_default ?? 0) > 0,
        ).length,
        pr: visible.filter((i) => metaMap[i.id]?.git?.pull_request).length,
        hidden: hiddenCount,
    };

    const rows = useMemo(() => {
        const needle = query.trim().toLowerCase();

        return installations.filter((installation) => {
            const info = metaMap[installation.id]?.git;

            if (
                view === 'hidden' ? !installation.hidden : installation.hidden
            ) {
                return false;
            }

            if (view === 'dirty' && !info?.has_changes) {
                return false;
            }

            if (view === 'behind' && !(info && info.behind_default > 0)) {
                return false;
            }

            if (view === 'pr' && !info?.pull_request) {
                return false;
            }

            if (!needle) {
                return true;
            }

            const haystack = [
                installation.name,
                metaMap[installation.id]?.app_name ?? '',
                info?.branch ?? '',
            ]
                .join(' ')
                .toLowerCase();

            return haystack.includes(needle);
        });
    }, [installations, metaMap, view, query]);

    const selectedInView = selected.filter((id) =>
        rows.some((row) => row.id === id),
    );
    const allSelected =
        rows.length > 0 && selectedInView.length === rows.length;

    const isBusy = installations.some(
        (i) => i.status === 'running' || i.status === 'pushing',
    );
    const hasRecentStatus = installations.some((i) => i.status !== 'idle');
    const anyHasChanges = visible.some((i) => metaMap[i.id]?.git?.has_changes);

    const { stop, start } = usePoll(2000, {}, { autoStart: false });

    if (hasRecentStatus) {
        start();
    } else {
        stop();
    }

    function handleViewChange(next: ViewKey) {
        setSelected([]);
        setView(next);

        if (next === 'hidden' && !showHidden) {
            router.get(
                '/',
                { show_hidden: 1 },
                { preserveScroll: true, preserveState: true },
            );
        }

        if (next !== 'hidden' && showHidden) {
            router.get('/', {}, { preserveScroll: true, preserveState: true });
        }
    }

    function toggleSelect(installationId: number) {
        setSelected((prev) =>
            prev.includes(installationId)
                ? prev.filter((id) => id !== installationId)
                : [...prev, installationId],
        );
    }

    function toggleSelectAll() {
        setSelected(allSelected ? [] : rows.map((row) => row.id));
    }

    async function handleFetchAll() {
        setFetching(true);

        try {
            await fetch(fetchAll.url(), {
                method: 'POST',
                headers: { 'X-XSRF-TOKEN': getCsrfToken() },
            });
            setLastFetch(
                new Date().toLocaleTimeString([], {
                    hour: '2-digit',
                    minute: '2-digit',
                }),
            );
            router.reload();
        } catch {
            // silently fail
        }

        setFetching(false);
    }

    /**
     * Run a per-installation POST for each id, then pull the list back in.
     */
    async function postForEach(
        ids: number[],
        url: (id: number) => string,
        body?: Record<string, string>,
    ) {
        for (const id of ids) {
            try {
                await fetch(url(id), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-XSRF-TOKEN': getCsrfToken(),
                    },
                    body: body ? JSON.stringify(body) : undefined,
                });
            } catch {
                // silently fail, the reload below shows the real state
            }
        }

        setSelected([]);
        router.reload();
    }

    function handleUpdate(installation: Installation) {
        router.post(update.url(installation.id), {}, { preserveScroll: true });
    }

    function handleUpdateAll() {
        router.post(updateAll.url(), {}, { preserveScroll: true });
    }

    function handleUpdateSelected() {
        postForEach(selectedInView, (id) => update.url(id));
    }

    async function handleHideSelected() {
        for (const id of selectedInView) {
            try {
                await fetch(hide.url(id), {
                    method: 'PATCH',
                    headers: { 'X-XSRF-TOKEN': getCsrfToken() },
                });
            } catch {
                // silently fail, the reload below shows the real state
            }
        }

        setSelected([]);
        router.reload();
    }

    function openPushDialog(dialog: PushDialog) {
        setCommitMessage(DEFAULT_COMMIT_MESSAGE);
        setPushDialog(dialog);
    }

    function handleConfirmPush() {
        const message = commitMessage.trim();

        if (!pushDialog || !message) {
            return;
        }

        if (pushDialog.kind === 'one') {
            router.post(
                push.url(pushDialog.installation.id),
                { message },
                { preserveScroll: true },
            );
        } else if (pushDialog.kind === 'all') {
            router.post(pushAll.url(), { message }, { preserveScroll: true });
        } else {
            postForEach(pushDialog.ids, (id) => push.url(id), { message });
        }

        setPushDialog(null);
        setCommitMessage(DEFAULT_COMMIT_MESSAGE);
    }

    const dialogTitle =
        pushDialog?.kind === 'one'
            ? `Push ${pushDialog.installation.name}`
            : pushDialog?.kind === 'selected'
              ? `Push ${pushDialog.ids.length} installations`
              : 'Push all installations';

    return (
        <>
            <Head title="Herd Update Manager" />

            <div className="flex min-h-screen">
                <InstallationsRail
                    view={view}
                    counts={counts}
                    herdPath={herdPath}
                    lastFetch={lastFetch}
                    onViewChange={handleViewChange}
                />

                <main className="flex min-w-[1180px] flex-1 flex-col">
                    <header className="flex h-[58px] items-center gap-3.5 border-b border-border bg-card px-5">
                        <h1 className="text-[15px] font-semibold">
                            {VIEW_TITLES[view]}
                        </h1>
                        <span className="font-mono text-xs text-muted-foreground">
                            {rows.length} of {counts.all}
                        </span>

                        <div className="ml-3 flex h-8 w-72 items-center gap-2 rounded-lg border border-input bg-background px-2.5">
                            <Search className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                            <input
                                aria-label="Filter sites"
                                placeholder="Filter sites"
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                                className="min-w-0 flex-1 bg-transparent text-[13px] outline-none placeholder:text-muted-foreground"
                            />
                        </div>

                        <div className="ml-auto flex items-center gap-2">
                            <SimpleTooltip content="Fetch latest from all remotes">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={handleFetchAll}
                                    disabled={fetching}
                                >
                                    <CloudDownload
                                        className={`h-4 w-4 ${fetching ? 'animate-pulse' : ''}`}
                                    />
                                    {fetching ? 'Fetching...' : 'Fetch all'}
                                </Button>
                            </SimpleTooltip>

                            {anyHasChanges && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        openPushDialog({ kind: 'all' })
                                    }
                                    disabled={isBusy}
                                >
                                    <Upload className="h-4 w-4" />
                                    Push all
                                </Button>
                            )}

                            <Button
                                size="sm"
                                onClick={handleUpdateAll}
                                disabled={isBusy || counts.all === 0}
                                className="bg-brand text-brand-foreground hover:bg-brand/90"
                            >
                                <RefreshCw
                                    className={`h-4 w-4 ${isBusy ? 'animate-spin' : ''}`}
                                />
                                Update all ({counts.all})
                            </Button>
                        </div>
                    </header>

                    {selectedInView.length > 0 && (
                        <div className="flex h-11 items-center gap-3 border-b border-border bg-brand/5 px-5">
                            <span className="text-[13px] font-semibold text-brand">
                                {selectedInView.length} selected
                            </span>
                            <Button
                                variant="outline"
                                size="sm"
                                className="h-7"
                                onClick={handleUpdateSelected}
                                disabled={isBusy}
                            >
                                Update packages
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                className="h-7"
                                onClick={() =>
                                    openPushDialog({
                                        kind: 'selected',
                                        ids: selectedInView,
                                    })
                                }
                                disabled={isBusy}
                            >
                                Push
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                className="h-7"
                                onClick={handleHideSelected}
                                disabled={isBusy}
                            >
                                Hide
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                className="ml-auto h-7"
                                onClick={() => setSelected([])}
                            >
                                Clear
                            </Button>
                        </div>
                    )}

                    <div className="px-5 py-4">
                        <div className="rounded-xl border border-border bg-card shadow-xs">
                            <div
                                className={`${COLUMNS} h-9 rounded-t-xl border-b border-border bg-muted/40 px-3.5 font-mono text-[10px] font-semibold tracking-[0.11em] text-muted-foreground uppercase`}
                            >
                                <Checkbox
                                    aria-label="Select all"
                                    checked={allSelected}
                                    onCheckedChange={toggleSelectAll}
                                />
                                <span>Project</span>
                                <span>Laravel</span>
                                <span>Branch</span>
                                <span>State</span>
                                <span>Last commit</span>
                                <span className="text-right">Actions</span>
                            </div>

                            {rows.map((installation) => (
                                <InstallationRow
                                    key={installation.id}
                                    installation={installation}
                                    meta={metaMap[installation.id] ?? null}
                                    herdPath={herdPath}
                                    columns={COLUMNS}
                                    selected={selected.includes(
                                        installation.id,
                                    )}
                                    onSelect={toggleSelect}
                                    onMeta={handleMeta}
                                    onUpdate={handleUpdate}
                                    onPush={(inst) =>
                                        openPushDialog({
                                            kind: 'one',
                                            installation: inst,
                                        })
                                    }
                                />
                            ))}

                            {rows.length === 0 && (
                                <p className="py-12 text-center text-[13px] text-muted-foreground">
                                    {query.trim()
                                        ? `No sites match “${query.trim()}”.`
                                        : 'Nothing in this view right now.'}
                                </p>
                            )}

                            <div className="flex items-center justify-between rounded-b-xl px-4 py-2.5 font-mono text-[11px] text-muted-foreground/70">
                                <span>
                                    {counts.dirty} uncommitted · {counts.behind}{' '}
                                    behind main · {counts.pr} open PR
                                </span>
                                <span>
                                    {hasRecentStatus
                                        ? 'Refreshing every 2s while a job runs'
                                        : 'Idle'}
                                </span>
                            </div>
                        </div>
                    </div>
                </main>
            </div>

            <Dialog
                open={pushDialog !== null}
                onOpenChange={(open) => !open && setPushDialog(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{dialogTitle}</DialogTitle>
                    </DialogHeader>
                    <Input
                        aria-label="Commit message"
                        placeholder="Commit message..."
                        value={commitMessage}
                        onChange={(e) => setCommitMessage(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' && commitMessage.trim()) {
                                handleConfirmPush();
                            }
                        }}
                        autoFocus
                    />
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setPushDialog(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={handleConfirmPush}
                            disabled={!commitMessage.trim()}
                        >
                            Push
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
