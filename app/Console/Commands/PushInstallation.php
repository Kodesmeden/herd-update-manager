<?php

namespace App\Console\Commands;

use App\Models\Installation;
use App\Support\HerdEnvironment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

#[Signature('app:push-installation {id} {--message=Update packages}')]
#[Description('Commit and push changes for a Herd installation')]
class PushInstallation extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $installation = Installation::findOrFail($this->argument('id'));
        $env = HerdEnvironment::env();
        $path = $installation->path;
        $output = '';

        $installation->update([
            'status' => 'pushing',
            'progress' => 0,
            'current_step' => 'Git add & commit',
            'output' => null,
        ]);

        // Pull latest changes before pushing
        $installation->update(['current_step' => 'Git pull']);
        $pullResult = Process::path($path)->env($env)->timeout(60)->run('git pull --rebase');
        $output .= $pullResult->output().$pullResult->errorOutput();

        if (! $pullResult->successful()) {
            return $this->markFailed($installation, $output);
        }

        // Stage all changes
        $addResult = Process::path($path)->env($env)->timeout(30)->run('git add --all');
        $output .= $addResult->output().$addResult->errorOutput();

        if (! $addResult->successful()) {
            return $this->markFailed($installation, $output);
        }

        // Check if there is anything to commit
        $statusResult = Process::path($path)->env($env)->timeout(10)->run('git status --porcelain');
        $hasChanges = trim($statusResult->output()) !== '';

        if ($hasChanges) {
            $commitResult = Process::path($path)->env($env)->timeout(30)
                ->run(sprintf('git commit -m %s', escapeshellarg($this->option('message'))));
            $output .= $commitResult->output().$commitResult->errorOutput();

            if (! $commitResult->successful()) {
                return $this->markFailed($installation, $output);
            }
        }

        // Push (there may be commits even without local changes)
        $installation->update(['current_step' => 'Git push']);

        $pushResult = Process::path($path)->env($env)->timeout(60)->run('git push');
        $output .= $pushResult->output().$pushResult->errorOutput();

        if (! $pushResult->successful()) {
            // If push says "Everything up-to-date", treat as success
            if (str_contains($output, 'Everything up-to-date')) {
                return $this->markSucceeded($installation, $output);
            }

            return $this->markFailed($installation, $output);
        }

        return $this->markSucceeded($installation, $output);
    }

    /**
     * Mark the installation as completed.
     */
    private function markSucceeded(Installation $installation, string $output): int
    {
        $installation->update([
            'status' => 'completed',
            'progress' => 100,
            'current_step' => null,
            'output' => $output,
            'last_updated_at' => now(),
        ]);

        return self::SUCCESS;
    }

    /**
     * Mark the installation as failed.
     */
    private function markFailed(Installation $installation, string $output): int
    {
        $installation->update([
            'status' => 'failed',
            'progress' => 100,
            'current_step' => null,
            'output' => $output,
            'last_updated_at' => now(),
        ]);

        return self::FAILURE;
    }
}
