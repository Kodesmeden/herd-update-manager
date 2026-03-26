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
            'git' => ['command' => 'git --version', 'label' => 'Git'],
            'gh' => ['command' => 'gh --version | head -1', 'label' => 'GitHub CLI'],
            'gh-auth' => ['command' => 'gh auth status 2>&1', 'label' => 'GitHub Auth'],
            'ssh' => ['command' => 'ssh -T git@github.com 2>&1; true', 'label' => 'SSH'],
            'composer' => ['command' => 'composer --version', 'label' => 'Composer'],
            'php' => ['command' => 'php --version | head -1', 'label' => 'PHP'],
            'node' => ['command' => 'node --version', 'label' => 'Node'],
            'npm' => ['command' => 'npm --version', 'label' => 'NPM'],
        ];

        if (! isset($checks[$check])) {
            return response()->json(['ok' => false, 'output' => 'Unknown check']);
        }

        $result = Process::env(HerdEnvironment::env())
            ->timeout(15)
            ->run($checks[$check]['command']);

        $output = trim($result->output().$result->errorOutput());

        return response()->json([
            'ok' => $result->successful() || str_contains($output, 'successfully authenticated'),
            'output' => $output,
        ]);
    }
}
