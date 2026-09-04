<?php

namespace Tests;

use App\Support\BackgroundCommand;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Process;

abstract class TestCase extends BaseTestCase
{
    /**
     * Records background commands instead of forking real detached processes.
     */
    protected BackgroundCommand $backgroundCommand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backgroundCommand = new class extends BackgroundCommand
        {
            /** @var array<int, array{command: string, installation_id: int}> */
            public array $recorded = [];

            public function start(string $command, int $installationId): void
            {
                $this->recorded[] = ['command' => $command, 'installation_id' => $installationId];
            }
        };

        $this->swap(BackgroundCommand::class, $this->backgroundCommand);

        // Every command this app runs touches a real repository on disk, so a
        // test that forgets to fake one must fail rather than run it
        Process::preventStrayProcesses();
    }

    /**
     * The background commands started during the test, in order.
     *
     * @return array<int, array{command: string, installation_id: int}>
     */
    protected function backgroundCommands(): array
    {
        return $this->backgroundCommand->recorded;
    }
}
