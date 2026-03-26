import { Stethoscope, X } from 'lucide-react';
import { useCallback, useState } from 'react';
import { run as runDiagnostic } from '@/actions/App/Http/Controllers/DiagnosticsController';
import { Button } from '@/components/ui/button';

type CheckResult = { ok: boolean; output: string } | null;
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

interface DiagnosticsPanelProps {
    visible: boolean;
    onToggle: () => void;
}

export function DiagnosticsTrigger({
    onClick,
    running,
}: {
    onClick: () => void;
    running: boolean;
}) {
    return (
        <Button
            variant="outline"
            size="sm"
            onClick={onClick}
            disabled={running}
        >
            <Stethoscope className="h-4 w-4" />
            <span className="hidden sm:inline">
                {running ? 'Testing...' : 'Diagnostics'}
            </span>
        </Button>
    );
}

export default function DiagnosticsPanel({
    visible,
    onToggle,
}: DiagnosticsPanelProps) {
    const [diagnostics, setDiagnostics] = useState<
        Record<CheckKey, CheckResult>
    >({} as Record<CheckKey, CheckResult>);
    const [running, setRunning] = useState(false);
    const [hasRun, setHasRun] = useState(false);

    const runDiagnostics = useCallback(async () => {
        setRunning(true);
        setHasRun(true);
        const results: Record<string, CheckResult> = {};

        for (const check of checks) {
            try {
                const response = await fetch(runDiagnostic.url(check.key));
                results[check.key] = await response.json();
            } catch {
                results[check.key] = { ok: false, output: 'Request failed' };
            }

            setDiagnostics({ ...results } as Record<CheckKey, CheckResult>);
        }

        setRunning(false);
    }, []);

    if (!visible) {
        return null;
    }

    return (
        <div className="rounded-xl border border-border/50 bg-card p-4 shadow-sm">
            <div className="mb-3 flex items-center justify-between">
                <h2 className="text-sm font-semibold">Diagnostics</h2>
                <div className="flex items-center gap-2">
                    {!hasRun && (
                        <Button
                            size="sm"
                            onClick={runDiagnostics}
                            disabled={running}
                        >
                            <Stethoscope className="h-3.5 w-3.5" />
                            Run checks
                        </Button>
                    )}
                    {hasRun && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={runDiagnostics}
                            disabled={running}
                        >
                            {running ? 'Testing...' : 'Re-run'}
                        </Button>
                    )}
                    <Button
                        variant="ghost"
                        size="icon"
                        className="h-7 w-7"
                        onClick={onToggle}
                    >
                        <X className="h-4 w-4" />
                    </Button>
                </div>
            </div>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                {checks.map((check) => {
                    const result = diagnostics[check.key];

                    return (
                        <div
                            key={check.key}
                            className="rounded-lg border px-3 py-2"
                        >
                            <div className="flex items-center gap-2">
                                <div
                                    className={`size-2 rounded-full ${
                                        !result
                                            ? 'bg-muted-foreground/30'
                                            : result.ok
                                              ? 'bg-green-500'
                                              : 'bg-red-500'
                                    }`}
                                />
                                <span className="text-sm font-medium">
                                    {check.label}
                                </span>
                            </div>
                            {result && (
                                <p
                                    className="mt-1 truncate text-xs text-muted-foreground"
                                    title={result.output}
                                >
                                    {result.output.split('\n')[0]}
                                </p>
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
