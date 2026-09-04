<?php

namespace App\Support;

class BackgroundCommand
{
    /**
     * Start an artisan command for an installation and return immediately.
     *
     * Raw exec() is used intentionally to detach the process. Laravel's Process
     * facade has no fire-and-forget mode, and the child has to outlive the
     * request that started it.
     *
     * Tests swap this class out, so no test ever forks a real process.
     */
    public function start(string $command, int $installationId): void
    {
        exec(HerdEnvironment::backgroundExecCommand(
            HerdEnvironment::phpBin(),
            base_path('artisan'),
            $command,
            $installationId,
        ));
    }
}
