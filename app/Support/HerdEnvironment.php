<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

class HerdEnvironment
{
    /**
     * Check if running on Windows.
     */
    public static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    /**
     * Get the current user's home directory.
     */
    public static function home(): string
    {
        if (self::isWindows()) {
            return getenv('USERPROFILE') ?: getenv('HOME') ?: '';
        }

        return getenv('HOME') ?: '';
    }

    /**
     * Build the full PATH for Herd binaries.
     */
    public static function path(): string
    {
        $paths = [self::herdBinPath(), self::nodeBinPath()];

        if (! self::isWindows()) {
            array_push(
                $paths,
                '/opt/homebrew/bin',
                '/opt/homebrew/sbin',
                '/usr/local/bin',
                '/usr/bin',
                '/bin',
            );
        }

        return implode(PATH_SEPARATOR, array_filter($paths));
    }

    /**
     * Build the environment variables array for running processes.
     *
     * @return array<string, string>
     */
    public static function env(): array
    {
        $env = [
            'PATH' => self::path(),
            'HOME' => self::home(),
        ];

        if (! self::isWindows()) {
            $sshSock = getenv('SSH_AUTH_SOCK');

            if ($sshSock) {
                $env['SSH_AUTH_SOCK'] = $sshSock;
            }
        }

        return array_filter($env);
    }

    /**
     * Get the Herd PHP binary path.
     */
    public static function phpBin(): string
    {
        return self::herdBinPath().DIRECTORY_SEPARATOR.'php';
    }

    /**
     * Get the platform null device for suppressing output.
     */
    public static function nullDevice(): string
    {
        return self::isWindows() ? 'nul' : '/dev/null';
    }

    /**
     * Build a shell fragment that suppresses stderr.
     */
    public static function suppressStderr(): string
    {
        return '2>'.self::nullDevice();
    }

    /**
     * Build a background exec command string for fire-and-forget execution.
     */
    public static function backgroundExecCommand(string $php, string $artisan, string $command, int $id): string
    {
        if (self::isWindows()) {
            return sprintf('start /b "" "%s" "%s" %s %d', $php, $artisan, $command, $id);
        }

        return sprintf('"%s" "%s" %s %d > /dev/null 2>&1 &', $php, $artisan, $command, $id);
    }

    /**
     * Get the default Herd sites path for the current platform.
     */
    public static function defaultSitesPath(): string
    {
        if (self::isWindows()) {
            return self::home().'\\Herd';
        }

        return '/Users/'.get_current_user().'/Herd';
    }

    /**
     * Get the Herd application config base directory.
     */
    private static function herdConfigBase(): string
    {
        if (self::isWindows()) {
            return self::home().DIRECTORY_SEPARATOR.'AppData'.DIRECTORY_SEPARATOR.'Local'.DIRECTORY_SEPARATOR.'Herd';
        }

        return self::home().'/Library/Application Support/Herd';
    }

    /**
     * Get the Herd bin directory path.
     */
    private static function herdBinPath(): string
    {
        return self::herdConfigBase().DIRECTORY_SEPARATOR.'bin';
    }

    /**
     * Resolve the active Herd Node bin path.
     */
    private static function nodeBinPath(): string
    {
        $nvmBase = self::herdConfigBase().DIRECTORY_SEPARATOR.'config'
            .DIRECTORY_SEPARATOR.'nvm'.DIRECTORY_SEPARATOR.'versions'
            .DIRECTORY_SEPARATOR.'node';

        if (! File::isDirectory($nvmBase)) {
            return self::isWindows() ? '' : '/usr/local/bin';
        }

        $nodeVersion = collect(File::directories($nvmBase))
            ->map(fn (string $path) => basename($path))
            ->sort(SORT_NATURAL)
            ->reverse()
            ->first() ?? 'v22';

        return $nvmBase.DIRECTORY_SEPARATOR.$nodeVersion.DIRECTORY_SEPARATOR.'bin';
    }
}
