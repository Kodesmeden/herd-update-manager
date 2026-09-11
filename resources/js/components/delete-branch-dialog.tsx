import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import type { BranchDeletionPreview } from '@/types/git';

interface DeleteBranchDialogProps {
    branch: string | null;
    preview: BranchDeletionPreview | null;
    deleting: boolean;
    onConfirm: () => void;
    onCancel: () => void;
}

/**
 * Where the branch will be deleted from, in words.
 */
function deletionScope(preview: BranchDeletionPreview): string {
    if (preview.local && preview.remote) {
        return 'locally and on origin';
    }

    return preview.local ? 'locally' : 'on origin';
}

/**
 * Confirms a branch deletion and warns about anything it would lose.
 */
export default function DeleteBranchDialog({
    branch,
    preview,
    deleting,
    onConfirm,
    onCancel,
}: DeleteBranchDialogProps) {
    return (
        <Dialog
            open={branch !== null}
            onOpenChange={(open) => !open && onCancel()}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete branch</DialogTitle>
                    <DialogDescription>
                        <span className="font-mono text-foreground">
                            {branch}
                        </span>{' '}
                        {preview
                            ? `will be deleted ${deletionScope(preview)}.`
                            : 'is being checked...'}
                    </DialogDescription>
                </DialogHeader>

                {preview ? (
                    <div className="flex flex-col gap-2 text-sm">
                        {preview.unique_commits > 0 ? (
                            <p className="rounded-lg bg-red-500/10 px-3 py-2 text-red-700 dark:text-red-400">
                                {preview.unique_commits === 1
                                    ? '1 commit only exists on this branch and will be lost.'
                                    : `${preview.unique_commits} commits only exist on this branch and will be lost.`}
                            </p>
                        ) : (
                            <p className="text-muted-foreground">
                                Every commit on this branch also exists on
                                another branch.
                            </p>
                        )}

                        {preview.remote && preview.pull_request_url && (
                            <p className="rounded-lg bg-amber-500/10 px-3 py-2 text-amber-700 dark:text-amber-400">
                                The open pull request for this branch will be
                                closed.{' '}
                                <a
                                    href={preview.pull_request_url}
                                    target="_blank"
                                    rel="noopener"
                                    className="underline hover:no-underline"
                                >
                                    View on GitHub
                                </a>
                            </p>
                        )}
                    </div>
                ) : (
                    <div className="flex flex-col gap-2">
                        <Skeleton className="h-4 w-3/4" />
                        <Skeleton className="h-4 w-1/2" />
                    </div>
                )}

                <DialogFooter>
                    <Button variant="outline" onClick={onCancel}>
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        onClick={onConfirm}
                        disabled={!preview || deleting}
                    >
                        {deleting ? 'Deleting...' : 'Delete'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
