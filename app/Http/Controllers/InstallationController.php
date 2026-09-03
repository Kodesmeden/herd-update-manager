<?php

namespace App\Http\Controllers;

use App\Models\Installation;
use App\Support\GitRepository;
use App\Support\HerdEnvironment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;

class InstallationController extends Controller
{
    /**
     * List all Herd installations, syncing with filesystem.
     */
    public function index(): Response
    {
        $this->syncInstallations();

        Installation::query()
            ->where('status', 'completed')
            ->where('last_updated_at', '<', now()->subSeconds(10))
            ->update(['status' => 'idle', 'progress' => 0, 'current_step' => null]);

        $showHidden = (bool) request()->query('show_hidden');

        $query = Installation::query()->orderBy('name');

        if (! $showHidden) {
            $query->where('hidden', false);
        }

        return Inertia::render('welcome', [
            'installations' => $query->get(),
            'showHidden' => $showHidden,
            'hiddenCount' => Installation::query()->where('hidden', true)->count(),
            'herdPath' => $this->herdPathForDisplay(),
        ]);
    }

    /**
     * The Herd directory with the home directory shortened to a tilde.
     */
    private function herdPathForDisplay(): string
    {
        $herdPath = (string) config('herd.path');
        $home = HerdEnvironment::home();

        if ($home !== '' && str_starts_with($herdPath, $home)) {
            return '~'.substr($herdPath, strlen($home));
        }

        return $herdPath;
    }

    /**
     * Fetch latest from remote for all visible installations.
     */
    public function fetchAll(): JsonResponse
    {
        $installations = Installation::query()
            ->where('hidden', false)
            ->get();

        $fetched = 0;

        foreach ($installations as $installation) {
            $repository = new GitRepository($installation->path);

            if (! $repository->isRepository()) {
                continue;
            }

            $repository->fetchAllRemotes();
            $repository->updateRemoteHead();

            // Pull latest changes if working tree is clean and fast-forward is possible
            if (! $repository->hasUncommittedChanges()) {
                $repository->pullFastForwardOnly();
            }

            // Keep the local default branch current too. Pulling only ever touches the
            // active branch, so working on a feature branch would leave it behind.
            $defaultBranch = $repository->defaultBranch();

            if ($repository->currentBranch() !== $defaultBranch) {
                $repository->fastForwardFromOrigin($defaultBranch);
            }

            $fetched++;
        }

        return response()->json(['success' => true, 'fetched' => $fetched]);
    }

    /**
     * Dismiss the status and output for an installation.
     */
    public function dismiss(Installation $installation): RedirectResponse
    {
        $installation->update([
            'status' => 'idle',
            'progress' => 0,
            'current_step' => null,
            'output' => null,
            'last_updated_at' => null,
        ]);

        return back();
    }

    /**
     * Hide an installation from the list.
     */
    public function hide(Installation $installation): RedirectResponse
    {
        $installation->update(['hidden' => true]);

        return back();
    }

    /**
     * Unhide an installation, making it visible again.
     */
    public function unhide(Installation $installation): RedirectResponse
    {
        $installation->update(['hidden' => false]);

        return back();
    }

    /**
     * Run update on a single installation in the background.
     */
    public function update(Installation $installation): RedirectResponse
    {
        $installation->update(['status' => 'running', 'progress' => 0, 'current_step' => null, 'output' => null]);
        $this->startBackgroundCommand($installation, 'app:update-installation');

        return back();
    }

    /**
     * Commit and push a single installation in the background.
     */
    public function push(Installation $installation): RedirectResponse
    {
        $message = $this->commitMessage();
        $installation->update(['status' => 'pushing', 'progress' => 0, 'current_step' => null, 'output' => null]);
        $this->startBackgroundCommand($installation, 'app:push-installation --message='.escapeshellarg($message));

        return back();
    }

    /**
     * Commit and push all visible installations in the background.
     */
    public function pushAll(): RedirectResponse
    {
        $message = $this->commitMessage();

        $installations = Installation::query()
            ->where('hidden', false)
            ->get();

        foreach ($installations as $installation) {
            $installation->update(['status' => 'pushing', 'progress' => 0, 'current_step' => null, 'output' => null]);
            $this->startBackgroundCommand(
                $installation,
                'app:push-installation --message='.escapeshellarg($message),
            );
        }

        return back();
    }

    /**
     * Run update on all visible installations in the background.
     */
    public function updateAll(): RedirectResponse
    {
        $installations = Installation::query()
            ->where('hidden', false)
            ->get();

        foreach ($installations as $installation) {
            $installation->update(['status' => 'running', 'progress' => 0, 'current_step' => null, 'output' => null]);
            $this->startBackgroundCommand($installation, 'app:update-installation');
        }

        return back();
    }

    /**
     * The commit message for a push request, falling back to the default.
     */
    private function commitMessage(): string
    {
        $validated = request()->validate([
            'message' => ['nullable', 'string', 'max:200'],
        ]);

        return trim($validated['message'] ?? '') ?: 'Update packages';
    }

    /**
     * Start an artisan command as a background process for an installation.
     */
    private function startBackgroundCommand(Installation $installation, string $command): void
    {
        $php = HerdEnvironment::phpBin();
        $artisan = base_path('artisan');

        // Raw exec() is used intentionally to detach the process.
        // Laravel's Process facade does not support fire-and-forget background execution.
        exec(HerdEnvironment::backgroundExecCommand($php, $artisan, $command, $installation->id));
    }

    /**
     * Sync filesystem directories with the installations table.
     */
    private function syncInstallations(): void
    {
        $herdPath = (string) config('herd.path');

        // Scanning a missing directory throws, and every installation would
        // otherwise look deleted just because the path was unavailable
        if (! File::isDirectory($herdPath)) {
            return;
        }

        $directories = collect(File::directories($herdPath))
            ->map(fn (string $path) => basename($path))
            ->filter(fn (string $name) => $name !== 'update')
            ->filter(fn (string $name) => File::exists($herdPath.'/'.$name.'/artisan'))
            ->values();

        foreach ($directories as $name) {
            Installation::query()->firstOrCreate(
                ['path' => $herdPath.'/'.$name],
                ['name' => $name],
            );
        }

        Installation::query()
            ->whereNotIn('name', $directories)
            ->delete();
    }
}
