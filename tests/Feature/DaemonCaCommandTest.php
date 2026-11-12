<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/serveros-ca-cmd-'.bin2hex(random_bytes(5));
    mkdir($this->dir, 0755);
    config()->set('daemon.ca_cert', $this->dir.'/ca.crt');
    config()->set('daemon.ca_key', $this->dir.'/ca.key');
});

afterEach(fn () => File::deleteDirectory($this->dir));

test('the CA key and the gateway key are only readable by their owner, and the umask is left alone', function () {
    $umask = umask(0022);

    try {
        $this->artisan('daemon:ca', ['action' => 'init'])->assertSuccessful();
        $this->artisan('daemon:ca', ['action' => 'issue-server', '--host' => 'gateway.serveros.test', '--out' => $this->dir.'/gateway'])->assertSuccessful();

        expect(fileperms($this->dir.'/ca.key') & 0777)->toBe(0600)
            ->and(fileperms($this->dir.'/gateway/gateway.key') & 0777)->toBe(0600)
            ->and(umask())->toBe(0022);
    } finally {
        umask($umask);
    }
});

test('a key is never written through a link planted where it goes', function () {
    symlink($this->dir.'/stolen.key', $this->dir.'/ca.key');

    $this->artisan('daemon:ca', ['action' => 'init'])->assertFailed();

    expect(file_exists($this->dir.'/stolen.key'))->toBeFalse()
        ->and(file_exists($this->dir.'/ca.crt'))->toBeFalse();
});
