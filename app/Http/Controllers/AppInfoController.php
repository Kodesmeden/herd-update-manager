<?php

namespace App\Http\Controllers;

use App\Models\Installation;
use App\Support\GitRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;

class AppInfoController extends Controller
{
    /**
     * Get combined app info and git info for an installation.
     */
    public function show(Installation $installation): JsonResponse
    {
        $path = $installation->path;

        return response()->json([
            'app_name' => $this->resolveAppName($path),
            'laravel_version' => $this->resolveLaravelVersion($path),
            'git' => (new GitRepository($path))->info(),
        ]);
    }

    /**
     * Resolve APP_NAME from the installation's .env file.
     */
    private function resolveAppName(string $path): ?string
    {
        $envFile = $path.'/.env';

        if (! File::exists($envFile)) {
            return null;
        }

        $contents = File::get($envFile);

        if (preg_match('/^APP_NAME=(.+)$/m', $contents, $matches)) {
            return trim($matches[1], "\"' ");
        }

        return null;
    }

    /**
     * Resolve the installed Laravel framework version from composer.lock.
     */
    private function resolveLaravelVersion(string $path): string
    {
        $lockFile = $path.'/composer.lock';

        if (! File::exists($lockFile)) {
            return 'Unknown';
        }

        /** @var array{packages: array<int, array{name: string, version: string}>}|null $lock */
        $lock = json_decode(File::get($lockFile), true);

        if (! $lock) {
            return 'Unknown';
        }

        foreach ($lock['packages'] ?? [] as $package) {
            if ($package['name'] === 'laravel/framework') {
                return ltrim($package['version'], 'v');
            }
        }

        return 'Unknown';
    }
}
