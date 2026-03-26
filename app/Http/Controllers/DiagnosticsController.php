<?php

namespace App\Http\Controllers;

use App\Support\HerdEnvironment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Process;

class DiagnosticsController extends Controller
{
    /**
     * Run a single diagnostic check and return the result.
     */
    public function run(string $check): JsonResponse
    {
        $checks = [
            'git' => ['command' => 'git --version'],
            'gh' => ['command' => 'gh --version', 'firstLine' => true],
            'gh-auth' => ['command' => 'gh auth status'],
            'ssh' => ['command' => 'ssh -T git@github.com'],
            'composer' => ['command' => 'composer --version'],
            'php' => ['command' => 'php --version', 'firstLine' => true],
            'node' => ['command' => 'node --version'],
            'npm' => ['command' => 'npm --version'],
        ];

        if (! isset($checks[$check])) {
            return response()->json(['ok' => false, 'output' => 'Unknown check']);
        }

        $result = Process::env(HerdEnvironment::env())
            ->timeout(15)
            ->run($checks[$check]['command']);

        $output = trim($result->output().$result->errorOutput());

        if ($checks[$check]['firstLine'] ?? false) {
            $output = explode("\n", $output)[0];
        }

        return response()->json([
            'ok' => $result->successful() || str_contains($output, 'successfully authenticated'),
            'output' => $output,
        ]);
    }
}
