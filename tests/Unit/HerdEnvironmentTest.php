<?php

use App\Support\HerdEnvironment;

it('builds a detached background command on unix', function () {
    $command = HerdEnvironment::backgroundExecCommand(
        '/herd/bin/php',
        '/site/artisan',
        'app:update-installation',
        7,
    );

    expect($command)
        ->toBe('"/herd/bin/php" "/site/artisan" app:update-installation 7 > /dev/null 2>&1 &');
})->skipOnWindows();

it('quotes paths so a space in the Herd directory cannot split the command', function () {
    $command = HerdEnvironment::backgroundExecCommand(
        '/Library/Application Support/Herd/bin/php',
        '/Herd/my site/artisan',
        'app:push-installation',
        3,
    );

    expect($command)
        ->toContain('"/Library/Application Support/Herd/bin/php"')
        ->toContain('"/Herd/my site/artisan"');
});
