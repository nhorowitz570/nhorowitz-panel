<?php

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

it('keeps file editor saves consistent when editing or navigating during a write', function () {
    if ((new ExecutableFinder)->find('node') === null) {
        $this->markTestSkipped('Needs node.');
    }

    $process = new Process(['node', '--no-warnings', '--test', dirname(__DIR__).'/js/file-browser-save.test.mjs']);
    $process->mustRun();

    expect($process->isSuccessful())->toBeTrue();
});
