<?php

namespace App\Http\Controllers;

use App\Support\HerdEnvironment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

class DiagnosticsController extends Controller
{
    /**
     * Tool versions rarely change, so results stay usable for an hour.
     */
    private const CACHE_TTL_MINUTES = 60;

    /**
     * @var array<string, array{command: string, firstLine?: bool}>
     */
    private const CHECKS = [
        'php' => ['command' => 'php --version', 'firstLine' => true],
        'composer' => ['command' => 'composer --version'],
        'node' => ['command' => 'node --version'],
        'npm' => ['command' => 'npm --version'],
        'git' => ['command' => 'git --version'],
        'gh' => ['command' => 'gh --version', 'firstLine' => true],
        'gh-auth' => ['command' => 'gh auth status'],
        'ssh' => ['command' => 'ssh -T git@github.com'],
    ];

    /**
     * Cached results for every check, without running any command.
     */
    public function index(): JsonResponse
    {
        $checks = [];

        foreach (array_keys(self::CHECKS) as $check) {
            $checks[$check] = Cache::get($this->cacheKey($check));
        }

        return response()->json(['checks' => $checks]);
    }

    /**
     * Run a single diagnostic check, reusing the cached result unless a refresh is requested.
     */
    public function run(string $check): JsonResponse
    {
        if (! isset(self::CHECKS[$check])) {
            return response()->json(['ok' => false, 'output' => 'Unknown check']);
        }

        if (! request()->boolean('refresh')) {
            $cached = Cache::get($this->cacheKey($check));

            if ($cached !== null) {
                return response()->json($cached);
            }
        }

        $result = $this->execute($check);

        Cache::put($this->cacheKey($check), $result, now()->addMinutes(self::CACHE_TTL_MINUTES));

        return response()->json($result);
    }

    /**
     * @return array{ok: bool, output: string, checked_at: string}
     */
    private function execute(string $check): array
    {
        $result = Process::env(HerdEnvironment::env())
            ->timeout(15)
            ->run(self::CHECKS[$check]['command']);

        $output = trim($result->output().$result->errorOutput());

        if (self::CHECKS[$check]['firstLine'] ?? false) {
            $output = explode("\n", $output)[0];
        }

        return [
            'ok' => $result->successful() || str_contains($output, 'successfully authenticated'),
            'output' => $output,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    private function cacheKey(string $check): string
    {
        return "diagnostics.{$check}";
    }
}
