import { Skeleton } from '@/components/ui/skeleton';

interface AppInfoBadgeProps {
    laravelVersion?: string;
    loading: boolean;
}

export default function AppInfoBadge({
    laravelVersion,
    loading,
}: AppInfoBadgeProps) {
    if (loading) {
        return (
            <div className="flex items-center gap-1.5">
                <Skeleton className="h-5 w-8" />
            </div>
        );
    }

    if (!laravelVersion || laravelVersion === 'Unknown') {
        return null;
    }

    return (
        <span className="rounded bg-muted px-1.5 py-0.5 text-xs text-muted-foreground">
            L{laravelVersion.split('.')[0]}
        </span>
    );
}
