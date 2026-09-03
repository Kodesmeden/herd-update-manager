import { GitBranch, Monitor, Moon, Sun } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { useAppearance } from '@/hooks/use-appearance';
import type { Appearance } from '@/hooks/use-appearance';
import {
    index as fetchDiagnostics,
    run as runDiagnostic,
} from '@/actions/App/Http/Controllers/DiagnosticsController';

export type ViewKey = 'all' | 'dirty' | 'behind' | 'pr' | 'hidden';

type CheckResult = {
    ok: boolean;
    output: string;
    checked_at?: string;
} | null;
type CheckKey =
    | 'git'
    | 'gh'
    | 'gh-auth'
    | 'ssh'
    | 'composer'
    | 'php'
    | 'node'
    | 'npm';

const checks: { key: CheckKey; label: string }[] = [
    { key: 'php', label: 'PHP' },
    { key: 'composer', label: 'Composer' },
    { key: 'node', label: 'Node' },
    { key: 'npm', label: 'NPM' },
    { key: 'git', label: 'Git' },
    { key: 'gh', label: 'GitHub CLI' },
    { key: 'gh-auth', label: 'GitHub Auth' },
    { key: 'ssh', label: 'SSH' },
];

const views: { key: ViewKey; label: string; dot: string }[] = [
    { key: 'all', label: 'All sites', dot: 'bg-muted-foreground/30' },
    { key: 'dirty', label: 'Uncommitted', dot: 'bg-amber-500' },
    { key: 'behind', label: 'Behind main', dot: 'bg-brand' },
    { key: 'pr', label: 'Open PRs', dot: 'bg-green-500' },
    { key: 'hidden', label: 'Hidden', dot: 'bg-muted-foreground/30' },
];

const themes: { key: Appearance; label: string; icon: typeof Sun }[] = [
    { key: 'light', label: 'Light', icon: Sun },
    { key: 'dark', label: 'Dark', icon: Moon },
    { key: 'system', label: 'Auto', icon: Monitor },
];

/**
 * "12 min ago" style age for a cached check result.
 */
function checkedAgo(checkedAt: string | undefined): string | null {
    if (!checkedAt) {
        return null;
    }

    const minutes = Math.round((Date.now() - Date.parse(checkedAt)) / 60000);

    if (minutes < 1) {
        return 'just now';
    }

    if (minutes < 60) {
        return `${minutes} min ago`;
    }

    return `${Math.round(minutes / 60)} h ago`;
}

/**
 * Sidebar value for a check: the bare version where the tool reports one, the
 * account name for the GitHub and SSH checks, and the raw first line otherwise.
 */
function checkValue(key: CheckKey, result: NonNullable<CheckResult>): string {
    const firstLine = result.output.split('\n')[0].trim();

    if (!result.ok) {
        return firstLine;
    }

    if (key === 'gh-auth') {
        return result.output.match(/account (\S+)/)?.[1] ?? firstLine;
    }

    if (key === 'ssh') {
        return result.output.match(/Hi ([^!]+)!/)?.[1] ?? firstLine;
    }

    return firstLine.match(/\d+\.\d+(?:\.\d+)?/)?.[0] ?? firstLine;
}

interface InstallationsRailProps {
    view: ViewKey;
    counts: Record<ViewKey, number>;
    herdPath: string;
    lastFetch: string | null;
    onViewChange: (view: ViewKey) => void;
}

export default function InstallationsRail({
    view,
    counts,
    herdPath,
    lastFetch,
    onViewChange,
}: InstallationsRailProps) {
    const { appearance, updateAppearance } = useAppearance();
    const [diagnostics, setDiagnostics] = useState<
        Record<CheckKey, CheckResult>
    >({} as Record<CheckKey, CheckResult>);
    const [runningCheck, setRunningCheck] = useState<CheckKey | null>(null);

    const runChecks = useCallback(
        async (keys: CheckKey[], refresh: boolean) => {
            for (const key of keys) {
                setRunningCheck(key);

                try {
                    const url = refresh
                        ? `${runDiagnostic.url(key)}?refresh=1`
                        : runDiagnostic.url(key);
                    const response = await fetch(url);
                    const result: CheckResult = await response.json();
                    setDiagnostics((prev) => ({ ...prev, [key]: result }));
                } catch {
                    setDiagnostics((prev) => ({
                        ...prev,
                        [key]: { ok: false, output: 'Request failed' },
                    }));
                }
            }

            setRunningCheck(null);
        },
        [],
    );

    // Show whatever the server still has cached, then quietly fill in the rest
    useEffect(() => {
        let cancelled = false;

        fetch(fetchDiagnostics.url())
            .then((response) => response.json())
            .then((data: { checks: Record<CheckKey, CheckResult> }) => {
                if (cancelled) {
                    return;
                }

                setDiagnostics(data.checks);

                const missing = checks
                    .map((check) => check.key)
                    .filter((key) => !data.checks[key]);

                void runChecks(missing, false);
            })
            .catch(() => {
                // silently fail, the Run checks button still works
            });

        return () => {
            cancelled = true;
        };
    }, [runChecks]);

    const running = runningCheck !== null;
    const hasResults = Object.values(diagnostics).some(
        (result) => result !== null,
    );

    return (
        <aside className="flex w-60 shrink-0 flex-col border-r border-border bg-sidebar px-3.5 py-4">
            <div className="flex items-center gap-2.5 px-2 pb-4">
                <div className="flex size-7 items-center justify-center rounded-md bg-brand text-brand-foreground">
                    <GitBranch className="h-4 w-4" />
                </div>
                <div>
                    <div className="text-[13.5px] font-semibold">
                        Herd Update
                    </div>
                    <div className="font-mono text-[10.5px] text-muted-foreground">
                        {herdPath}
                    </div>
                </div>
            </div>

            <div className="px-2 pb-2 font-mono text-[10px] font-semibold tracking-[0.14em] text-muted-foreground uppercase">
                Views
            </div>
            <nav className="flex flex-col gap-0.5">
                {views.map((item) => {
                    const active = item.key === view;
                    const count = counts[item.key];

                    return (
                        <button
                            key={item.key}
                            onClick={() => onViewChange(item.key)}
                            className={`flex h-8 cursor-pointer items-center gap-2.5 rounded-md px-2.5 text-left text-[13.5px] transition-colors ${
                                active
                                    ? 'bg-brand/10 font-semibold text-foreground'
                                    : 'text-muted-foreground hover:bg-accent hover:text-foreground'
                            }`}
                        >
                            <span
                                className={`size-1.5 shrink-0 rounded-full ${item.dot}`}
                            />
                            <span className="flex-1">{item.label}</span>
                            <span
                                className={`font-mono text-[11.5px] ${active ? 'text-brand' : 'text-muted-foreground/70'}`}
                            >
                                {count}
                            </span>
                        </button>
                    );
                })}
            </nav>

            <div className="flex items-center justify-between px-2 pt-7 pb-2">
                <span className="font-mono text-[10px] font-semibold tracking-[0.14em] text-muted-foreground uppercase">
                    Environment
                </span>
                <button
                    onClick={() =>
                        runChecks(
                            checks.map((check) => check.key),
                            true,
                        )
                    }
                    disabled={running}
                    className="cursor-pointer rounded px-1.5 py-0.5 text-[11.5px] font-medium text-brand transition-colors hover:bg-accent disabled:opacity-50"
                >
                    {running
                        ? 'Testing…'
                        : hasResults
                          ? 'Re-run'
                          : 'Run checks'}
                </button>
            </div>
            <div className="flex flex-col">
                {checks.map((check) => {
                    const result = diagnostics[check.key];
                    const isRunning = runningCheck === check.key;
                    const age = checkedAgo(result?.checked_at);

                    return (
                        <div
                            key={check.key}
                            className="flex h-[26px] items-center gap-2.5 px-2.5"
                            title={
                                [result?.output, age && `checked ${age}`]
                                    .filter(Boolean)
                                    .join(' · ') || undefined
                            }
                        >
                            <span
                                className={`size-1.5 shrink-0 rounded-full ${
                                    isRunning
                                        ? 'animate-pulse bg-brand'
                                        : !result
                                          ? 'bg-muted-foreground/25'
                                          : result.ok
                                            ? 'bg-green-500'
                                            : 'bg-red-500'
                                }`}
                            />
                            <span className="flex-1 text-[12.5px] text-muted-foreground">
                                {check.label}
                            </span>
                            <span className="max-w-24 truncate font-mono text-[11px] text-muted-foreground/70">
                                {isRunning
                                    ? 'checking…'
                                    : result
                                      ? checkValue(check.key, result)
                                      : 'not checked'}
                            </span>
                        </div>
                    );
                })}
            </div>

            <div className="mt-auto border-t border-border pt-4">
                <div className="flex gap-0.5 rounded-lg bg-muted p-0.5">
                    {themes.map((theme) => {
                        const active = appearance === theme.key;
                        const Icon = theme.icon;

                        return (
                            <button
                                key={theme.key}
                                onClick={() => updateAppearance(theme.key)}
                                className={`flex h-7 flex-1 cursor-pointer items-center justify-center gap-1.5 rounded-md text-[12px] transition-colors ${
                                    active
                                        ? 'bg-card font-semibold text-foreground shadow-xs'
                                        : 'text-muted-foreground hover:text-foreground'
                                }`}
                            >
                                <Icon className="h-3.5 w-3.5" />
                                {theme.label}
                            </button>
                        );
                    })}
                </div>
                <div className="flex items-center justify-between px-2.5 pt-3 font-mono text-[11px] text-muted-foreground/70">
                    <span>Last fetch</span>
                    <span>{lastFetch ?? '–'}</span>
                </div>
            </div>
        </aside>
    );
}
