<?php

namespace App\Console\Commands;

use App\Models\Installation;
use App\Support\HerdEnvironment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

#[Signature('app:update-installation {id}')]
#[Description('Run update commands for a Herd installation')]
class UpdateInstallation extends Command
{
    /**
     * @return array<int, array{command: string, label: string, progress: int, env?: array<string, string>}>
     */
    private function steps(string $path): array
    {
        $artisan = $path.DIRECTORY_SEPARATOR.'artisan';

        return [
            ['command' => 'composer update', 'label' => 'Composer update', 'progress' => 15],
            ['command' => 'npm update', 'label' => 'NPM update', 'progress' => 40, 'env' => ['PUPPETEER_SKIP_DOWNLOAD' => 'true']],
            ['command' => 'npm run build', 'label' => 'Build assets', 'progress' => 70],
            ['command' => "php {$artisan} view:clear", 'label' => 'Clear views', 'progress' => 85],
            ['command' => "php {$artisan} config:clear", 'label' => 'Clear config', 'progress' => 92],
            ['command' => "php {$artisan} route:clear", 'label' => 'Clear routes', 'progress' => 100],
        ];
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $installation = Installation::findOrFail($this->argument('id'));

        $installation->update([
            'status' => 'running',
            'progress' => 0,
            'current_step' => null,
            'output' => null,
        ]);

        $output = '';
        $env = ['PATH' => HerdEnvironment::path()];

        foreach ($this->steps($installation->path) as $step) {
            $installation->update([
                'current_step' => $step['label'],
            ]);

            $result = Process::path($installation->path)
                ->env(array_merge($env, $step['env'] ?? []))
                ->timeout(600)
                ->run($step['command']);

            $output .= $result->output().$result->errorOutput();

            if (! $result->successful()) {
                $installation->update([
                    'status' => 'failed',
                    'progress' => $step['progress'],
                    'current_step' => $step['label'],
                    'output' => $output,
                    'last_updated_at' => now(),
                ]);

                return self::FAILURE;
            }

            $installation->update([
                'progress' => $step['progress'],
            ]);
        }

        $installation->update([
            'status' => 'completed',
            'progress' => 100,
            'current_step' => null,
            'output' => $output,
            'last_updated_at' => now(),
        ]);

        return self::SUCCESS;
    }
}
