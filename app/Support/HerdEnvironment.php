<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

class HerdEnvironment
{
    /**
     * Build the full PATH for Herd binaries.
     */
    public static function path(): string
    {
        return implode(':', [
            self::herdBinPath(),
            self::nodeBinPath(),
            '/opt/homebrew/bin',
            '/opt/homebrew/sbin',
            '/usr/local/bin',
            '/usr/bin',
            '/bin',
        ]);
    }

    /**
     * Build the environment variables array for running processes.
     *
     * @return array<string, string>
     */
    public static function env(): array
    {
        return array_filter([
            'PATH' => self::path(),
            'SSH_AUTH_SOCK' => getenv('SSH_AUTH_SOCK') ?: null,
            'HOME' => getenv('HOME'),
        ]);
    }

    /**
     * Get the Herd PHP binary path.
     */
    public static function phpBin(): string
    {
        return self::herdBinPath().'/php';
    }

    /**
     * Get the Herd bin directory path.
     */
    private static function herdBinPath(): string
    {
        return getenv('HOME').'/Library/Application Support/Herd/bin';
    }

    /**
     * Resolve the active Herd Node bin path.
     */
    private static function nodeBinPath(): string
    {
        $nvmBase = getenv('HOME').'/Library/Application Support/Herd/config/nvm/versions/node';

        $nodeVersion = collect(File::directories($nvmBase))
            ->map(fn (string $path) => basename($path))
            ->sort(SORT_NATURAL)
            ->reverse()
            ->first() ?? 'v22';

        return $nvmBase.'/'.$nodeVersion.'/bin';
    }
}
