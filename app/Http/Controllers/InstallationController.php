<?php

namespace App\Http\Controllers;

use App\Models\Installation;
use App\Support\HerdEnvironment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
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
        ]);
    }

    /**
     * Fetch latest from remote for all visible installations.
     */
    public function fetchAll(): JsonResponse
    {
        $env = HerdEnvironment::env();

        $installations = Installation::query()
            ->where('hidden', false)
            ->get();

        $fetched = 0;

        foreach ($installations as $installation) {
            $isGit = Process::path($installation->path)->env($env)->timeout(5)
                ->run('git rev-parse --is-inside-work-tree '.HerdEnvironment::suppressStderr());

            if (! $isGit->successful()) {
                continue;
            }

            Process::path($installation->path)->env($env)->timeout(30)
                ->run('git fetch --all --prune');

            Process::path($installation->path)->env($env)->timeout(10)
                ->run('git remote set-head origin --auto');

            // Pull latest changes if working tree is clean and fast-forward is possible
            $hasChanges = trim(Process::path($installation->path)->env($env)->timeout(5)
                ->run('git status --porcelain')->output()) !== '';

            if (! $hasChanges) {
                Process::path($installation->path)->env($env)->timeout(30)
                    ->run('git pull --ff-only');
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
        $message = request()->input('message', 'Update packages');
        $installation->update(['status' => 'pushing', 'progress' => 0, 'current_step' => null, 'output' => null]);
        $this->startBackgroundCommand($installation, 'app:push-installation --message='.escapeshellarg($message));

        return back();
    }

    /**
     * Commit and push all visible installations in the background.
     */
    public function pushAll(): RedirectResponse
    {
        $message = request()->input('message', 'Update packages');

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
        $directories = collect(File::directories(config('herd.path')))
            ->map(fn (string $path) => basename($path))
            ->filter(fn (string $name) => $name !== 'update')
            ->filter(fn (string $name) => File::exists(config('herd.path').'/'.$name.'/artisan'))
            ->values();

        foreach ($directories as $name) {
            Installation::query()->firstOrCreate(
                ['path' => config('herd.path').'/'.$name],
                ['name' => $name],
            );
        }

        Installation::query()
            ->whereNotIn('name', $directories)
            ->delete();
    }
}
