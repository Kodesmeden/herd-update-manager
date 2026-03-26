import { Head, router, usePoll } from '@inertiajs/react';
import {
    CloudDownload,
    Monitor,
    Moon,
    RefreshCw,
    Stethoscope,
    Sun,
    Upload,
} from 'lucide-react';
import { useCallback, useState } from 'react';
import DiagnosticsPanel from '@/components/diagnostics-panel';
import InstallationCard from '@/components/installation-card';
import type { Installation } from '@/components/installation-card';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import SimpleTooltip from '@/components/ui/simple-tooltip';
import { useAppearance } from '@/hooks/use-appearance';
import { getCsrfToken } from '@/lib/utils';
import {
    fetchAll,
    push,
    pushAll,
    update,
    updateAll,
} from '@/actions/App/Http/Controllers/InstallationController';

interface Props {
    installations: Installation[];
    showHidden: boolean;
}

export default function Welcome({ installations, showHidden }: Props) {
    const { appearance, updateAppearance } = useAppearance();
    const [pushModal, setPushModal] = useState<Installation | null>(null);
    const [commitMessage, setCommitMessage] = useState('');
    const [showDiagnostics, setShowDiagnostics] = useState(false);
    const [fetching, setFetching] = useState(false);
    const [showPushAllDialog, setShowPushAllDialog] = useState(false);
    const [pushAllMessage, setPushAllMessage] = useState('Update packages');
    const [changesMap, setChangesMap] = useState<Record<number, boolean>>({});

    const handleHasChanges = useCallback(
        (installationId: number, hasChanges: boolean) => {
            setChangesMap((prev) => ({ ...prev, [installationId]: hasChanges }));
        },
        [],
    );

    async function handleFetchAll() {
        setFetching(true);

        try {
            await fetch(fetchAll.url(), {
                method: 'POST',
                headers: { 'X-XSRF-TOKEN': getCsrfToken() },
            });
            router.reload();
        } catch {
            // silently fail
        }

        setFetching(false);
    }

    function cycleAppearance() {
        const next =
            appearance === 'light'
                ? 'dark'
                : appearance === 'dark'
                  ? 'system'
                  : 'light';
        updateAppearance(next);
    }

    const AppearanceIcon =
        appearance === 'light' ? Sun : appearance === 'dark' ? Moon : Monitor;

    const isBusy = installations.some(
        (i) => i.status === 'running' || i.status === 'pushing',
    );
    const hasRecentStatus = installations.some((i) => i.status !== 'idle');
    const visibleCount = installations.filter((i) => !i.hidden).length;
    const anyHasChanges = installations.some(
        (i) => !i.hidden && changesMap[i.id],
    );

    const { stop, start } = usePoll(2000, {}, { autoStart: false });

    if (hasRecentStatus) {
        start();
    } else {
        stop();
    }

    function handleUpdate(installation: Installation) {
        router.post(update.url(installation.id), {}, { preserveScroll: true });
    }

    function handleUpdateAll() {
        router.post(updateAll.url(), {}, { preserveScroll: true });
    }

    function handlePushQuick(installation: Installation) {
        router.post(push.url(installation.id), {}, { preserveScroll: true });
    }

    function handlePushWithMessage() {
        if (!pushModal) {
            return;
        }

        router.post(
            push.url(pushModal.id),
            { message: commitMessage },
            { preserveScroll: true },
        );
        setPushModal(null);
        setCommitMessage('');
    }

    function handlePushAll() {
        if (!pushAllMessage.trim()) {
            return;
        }

        router.post(
            pushAll.url(),
            { message: pushAllMessage },
            { preserveScroll: true },
        );
        setShowPushAllDialog(false);
        setPushAllMessage('Update packages');
    }

    function toggleShowHidden() {
        router.get('/', showHidden ? {} : { show_hidden: 1 }, {
            preserveScroll: true,
        });
    }

    return (
        <>
            <Head title="Herd Update Manager" />

            <header className="border-b border-border/50 bg-card">
                <div className="mx-auto flex max-w-5xl items-center justify-between px-4 py-4">
                    <h1 className="text-xl font-bold">Herd Update Manager</h1>
                    <div className="flex items-center gap-2">
                        <SimpleTooltip content={`Theme: ${appearance}`}>
                            <button
                                onClick={cycleAppearance}
                                className="cursor-pointer rounded-md p-1.5 text-muted-foreground transition-colors hover:text-foreground"
                            >
                                <AppearanceIcon className="h-4 w-4" />
                            </button>
                        </SimpleTooltip>
                        <button
                            onClick={toggleShowHidden}
                            className={`cursor-pointer rounded-md px-2.5 py-1.5 text-sm transition-colors ${
                                showHidden
                                    ? 'bg-accent text-accent-foreground'
                                    : 'text-muted-foreground hover:text-foreground'
                            }`}
                        >
                            {showHidden ? 'Showing hidden' : 'Show hidden'}
                        </button>
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
                                <span className="hidden sm:inline">
                                    {fetching ? 'Fetching...' : 'Git Fetch'}
                                </span>
                            </Button>
                        </SimpleTooltip>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setShowDiagnostics(!showDiagnostics)}
                        >
                            <Stethoscope className="h-4 w-4" />
                            <span className="hidden sm:inline">
                                Diagnostics
                            </span>
                        </Button>
                        {anyHasChanges && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setShowPushAllDialog(true)}
                                disabled={isBusy}
                            >
                                <Upload className="h-4 w-4" />
                                <span className="hidden sm:inline">
                                    Push All
                                </span>
                            </Button>
                        )}
                        <Button
                            size="sm"
                            onClick={handleUpdateAll}
                            disabled={isBusy || visibleCount === 0}
                            className="bg-amber-600 text-white hover:bg-amber-700"
                        >
                            <RefreshCw
                                className={`h-4 w-4 ${isBusy ? 'animate-spin' : ''}`}
                            />
                            Update All ({visibleCount})
                        </Button>
                    </div>
                </div>
            </header>

            <main className="mx-auto max-w-5xl px-4 py-6">
                {showDiagnostics && (
                    <div className="mb-6">
                        <DiagnosticsPanel
                            visible={showDiagnostics}
                            onToggle={() => setShowDiagnostics(false)}
                        />
                    </div>
                )}

                <div className="flex flex-col gap-3">
                    {installations.map((installation) => (
                        <InstallationCard
                            key={installation.id}
                            installation={installation}
                            onUpdate={handleUpdate}
                            onPushQuick={handlePushQuick}
                            onPushWithMessage={(inst) => {
                                setPushModal(inst);
                                setCommitMessage('');
                            }}
                            onHasChanges={handleHasChanges}
                        />
                    ))}
                </div>

                {installations.length === 0 && (
                    <p className="py-12 text-center text-muted-foreground">
                        No installations found in the Herd directory.
                    </p>
                )}
            </main>

            <Dialog
                open={showPushAllDialog}
                onOpenChange={(open) => !open && setShowPushAllDialog(false)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Push All Installations</DialogTitle>
                    </DialogHeader>
                    <Input
                        aria-label="Commit message"
                        placeholder="Commit message..."
                        value={pushAllMessage}
                        onChange={(e) => setPushAllMessage(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' && pushAllMessage.trim()) {
                                handlePushAll();
                            }
                        }}
                        autoFocus
                    />
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setShowPushAllDialog(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={handlePushAll}
                            disabled={!pushAllMessage.trim()}
                        >
                            Push All
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={pushModal !== null}
                onOpenChange={(open) => !open && setPushModal(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Commit & Push - {pushModal?.name}
                        </DialogTitle>
                    </DialogHeader>
                    <Input
                        aria-label="Commit message"
                        placeholder="Commit message..."
                        value={commitMessage}
                        onChange={(e) => setCommitMessage(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' && commitMessage.trim()) {
                                handlePushWithMessage();
                            }
                        }}
                        autoFocus
                    />
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setPushModal(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={handlePushWithMessage}
                            disabled={!commitMessage.trim()}
                        >
                            Commit & Push
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
