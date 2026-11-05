<?php

namespace App\Console\Commands;

use App\Ai\Agents\CommitMessageAgent;
use App\Models\Installation;
use App\Support\GitRepository;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:push-installation {id} {--message=Update} {--generate-message : Let Claude write the message, falling back to --message}')]
#[Description('Commit and push changes for a Herd installation')]
class PushInstallation extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $installation = Installation::findOrFail($this->argument('id'));
        $repository = new GitRepository($installation->path);
        $output = '';

        $installation->update([
            'status' => 'pushing',
            'progress' => 0,
            'current_step' => 'Git add & commit',
            'output' => null,
        ]);

        // Stage all changes
        $addResult = $repository->stageAll();
        $output .= $addResult->output().$addResult->errorOutput();

        if (! $addResult->successful()) {
            return $this->markFailed($installation, $output);
        }

        // Check if there is anything to commit
        if ($repository->hasUncommittedChanges()) {
            $commitResult = $repository->commit($this->commitMessage($installation, $repository));
            $output .= $commitResult->output().$commitResult->errorOutput();

            if (! $commitResult->successful()) {
                return $this->markFailed($installation, $output);
            }
        }

        // A branch created locally has no upstream yet. There is nothing to pull,
        // and git refuses to rebase without tracking information.
        $hasUpstream = $repository->hasUpstream();

        if ($hasUpstream) {
            // Pull latest changes before pushing (after commit so rebase works)
            $installation->update(['current_step' => 'Git pull']);
            $pullResult = $repository->pullRebase();
            $output .= $pullResult->output().$pullResult->errorOutput();

            if (! $pullResult->successful()) {
                return $this->markFailed($installation, $output);
            }
        }

        // Push
        $installation->update(['current_step' => 'Git push']);

        $pushResult = $hasUpstream
            ? $repository->push()
            : $repository->pushSetUpstream($repository->currentBranch());
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
     * The message for the commit, written by Claude when asked and possible.
     */
    private function commitMessage(Installation $installation, GitRepository $repository): string
    {
        if ($this->option('generate-message')) {
            $installation->update(['current_step' => 'Writing commit message']);

            $generated = (new CommitMessageAgent)->describe($repository);

            $installation->update(['current_step' => 'Git add & commit']);

            if ($generated !== null) {
                return $generated;
            }
        }

        return $this->option('message');
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
