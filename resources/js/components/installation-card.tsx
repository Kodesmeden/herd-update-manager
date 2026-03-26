import { router } from '@inertiajs/react';
import { Eye, EyeOff, FileText, RefreshCw, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { show as fetchMeta } from '@/actions/App/Http/Controllers/AppInfoController';
import {
    dismiss,
    hide,
    unhide,
} from '@/actions/App/Http/Controllers/InstallationController';
import AppInfoBadge from '@/components/app-info-badge';
import GitPanel from '@/components/git-panel';
import type { GitInfoData } from '@/components/git-panel';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import SimpleTooltip from '@/components/ui/simple-tooltip';

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

interface Meta {
    app_name: string | null;
    laravel_version: string;
    git: GitInfoData;
}

interface InstallationCardProps {
    installation: Installation;
    onUpdate: (installation: Installation) => void;
    onPushQuick: (installation: Installation) => void;
    onPushWithMessage: (installation: Installation) => void;
}

export default function InstallationCard({
    installation,
    onUpdate,
    onPushQuick,
    onPushWithMessage,
}: InstallationCardProps) {
    const [showLog, setShowLog] = useState(false);
    const [meta, setMeta] = useState<Meta | null>(null);
    const [metaLoading, setMetaLoading] = useState(true);
    const prevStatus = useRef(installation.status);

    const loadMeta = () => {
        fetch(fetchMeta.url(installation.id))
            .then((res) => res.json())
            .then((data: Meta) => {
                setMeta(data);
                setMetaLoading(false);
            })
            .catch(() => setMetaLoading(false));
    };

    useEffect(() => {
        loadMeta();
    }, [installation.id]);

    // Re-fetch meta when status transitions from busy to idle/completed/failed
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
    }, [installation.status]);

    const prevOutput = useRef(installation.output);
    useEffect(() => {
        if (installation.output !== prevOutput.current) {
            prevOutput.current = installation.output;
            loadMeta();
        }
    }, [installation.output]);

    const isBusy =
        installation.status === 'running' || installation.status === 'pushing';
    const displayName = meta?.app_name || installation.name;

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

    function statusBadge(status: Installation['status']) {
        switch (status) {
            case 'running':
                return (
                    <Badge className="animate-pulse bg-blue-600 text-white">
                        Updating...
                    </Badge>
                );
            case 'pushing':
                return (
                    <Badge className="animate-pulse bg-blue-600 text-white">
                        Pushing...
                    </Badge>
                );
            case 'completed':
                return (
                    <SimpleTooltip content="Dismiss">
                        <button
                            onClick={handleDismiss}
                            className="group inline-flex cursor-pointer items-center gap-1"
                        >
                            <Badge className="bg-green-600 text-white">
                                OK
                            </Badge>
                            <X className="h-3 w-3 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />
                        </button>
                    </SimpleTooltip>
                );
            case 'failed':
                return (
                    <SimpleTooltip content="Dismiss">
                        <button
                            onClick={handleDismiss}
                            className="group inline-flex cursor-pointer items-center gap-1"
                        >
                            <Badge variant="destructive">Failed</Badge>
                            <X className="h-3 w-3 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />
                        </button>
                    </SimpleTooltip>
                );
            default:
                return null;
        }
    }

    const borderClass =
        installation.status === 'running' || installation.status === 'pushing'
            ? 'border-blue-500/50'
            : installation.status === 'failed'
              ? 'border-red-500/50'
              : installation.status === 'completed'
                ? 'border-green-500/50'
                : 'border-border/50';

    return (
        <div
            className={`rounded-xl border bg-card shadow-sm transition-all hover:shadow-md ${borderClass} ${
                installation.hidden ? 'opacity-50' : ''
            }`}
        >
            <div className="flex items-center justify-between gap-4 px-4 py-3">
                <div className="flex min-w-0 items-center gap-3">
                    <span className="truncate font-semibold">
                        {displayName}
                    </span>
                    {meta?.app_name && (
                        <span className="text-xs text-muted-foreground">
                            {installation.name}
                        </span>
                    )}
                    <AppInfoBadge
                        laravelVersion={meta?.laravel_version}
                        loading={metaLoading}
                    />
                    {statusBadge(installation.status)}
                </div>
                <div className="flex shrink-0 items-center gap-1">
                    {installation.hidden ? (
                        <SimpleTooltip content="Unhide">
                            <Button
                                variant="ghost"
                                size="icon"
                                className="h-7 w-7"
                                onClick={handleUnhide}
                            >
                                <Eye className="h-4 w-4" />
                            </Button>
                        </SimpleTooltip>
                    ) : (
                        <>
                            {installation.output && (
                                <SimpleTooltip
                                    content={showLog ? 'Hide log' : 'Show log'}
                                >
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="h-7 w-7"
                                        onClick={() => setShowLog(!showLog)}
                                    >
                                        <FileText className="h-4 w-4" />
                                    </Button>
                                </SimpleTooltip>
                            )}
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => onUpdate(installation)}
                                disabled={isBusy}
                            >
                                <RefreshCw
                                    className={`h-3.5 w-3.5 ${isBusy ? 'animate-spin' : ''}`}
                                />
                                Update Packages
                            </Button>
                            <SimpleTooltip content="Hide from list">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="h-7 w-7 text-muted-foreground"
                                    onClick={handleHide}
                                    disabled={isBusy}
                                >
                                    <EyeOff className="h-4 w-4" />
                                </Button>
                            </SimpleTooltip>
                        </>
                    )}
                </div>
            </div>

            {isBusy && (
                <div className="mx-4 mb-2">
                    <div className="h-1.5 overflow-hidden rounded-full bg-amber-800/20">
                        <div
                            className="h-full rounded-full bg-amber-700 transition-all duration-500 ease-out"
                            style={{ width: `${installation.progress}%` }}
                        />
                    </div>
                    {installation.current_step && (
                        <p className="mt-1 text-xs text-muted-foreground">
                            {installation.current_step}...
                        </p>
                    )}
                </div>
            )}

            {!installation.hidden && (
                <GitPanel
                    installationId={installation.id}
                    gitInfo={meta?.git ?? null}
                    loading={metaLoading}
                    isBusy={isBusy}
                    onPushQuick={() => onPushQuick(installation)}
                    onPushWithMessage={() => onPushWithMessage(installation)}
                    onRefresh={loadMeta}
                />
            )}

            {showLog && installation.output && (
                <div className="border-t bg-muted/50 px-4 py-3">
                    <pre className="max-h-64 overflow-auto text-xs whitespace-pre-wrap">
                        {installation.output}
                    </pre>
                </div>
            )}
        </div>
    );
}
