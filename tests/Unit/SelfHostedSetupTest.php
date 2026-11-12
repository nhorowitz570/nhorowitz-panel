<?php

use Symfony\Component\Yaml\Yaml;

function selfHostedFile(string $path): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/'.$path);
}

test('the gateway can’t read Caddy’s data, where its private keys are', function () {
    $compose = Yaml::parse(selfHostedFile('docker/self-hosted/compose.yaml'));

    expect(collect($compose['services']['gateway']['volumes'])->filter(fn (string $volume) => str_starts_with($volume, 'caddy-data:')))->toBeEmpty()
        ->and(selfHostedFile('docs/self-hosting.md'))->not->toContain('GATEWAY_PANEL_CA=/data/');
});

test('Redis asks for the password the install sets, and the panel sends it', function () {
    $compose = Yaml::parse(selfHostedFile('docker/self-hosted/compose.yaml'));

    expect(implode(' ', $compose['services']['redis']['command']))->toContain('--requirepass')
        ->and($compose['x-panel']['environment'])->toHaveKey('REDIS_PASSWORD')
        ->and(selfHostedFile('docker/self-hosted/install.sh'))->toContain('REDIS_PASSWORD=%s')
        ->and(selfHostedFile('docker/self-hosted/serveros-panel'))->toContain('set_env REDIS_PASSWORD');
});

test('the gateway is built from the daemon commit the panel is tested with', function () {
    expect(selfHostedFile('docker/self-hosted/Dockerfile'))->toMatch('/ARG DAEMON_COMMIT=[0-9a-f]{40}\n/')
        ->toContain('rev-parse HEAD)" = "${DAEMON_COMMIT}"');
});

test('the plain-server steps keep the code out of the web server user’s hands', function () {
    $docs = selfHostedFile('docs/self-hosting.md');

    expect($docs)->not->toContain("build it, as the web server's user")
        ->not->toContain("then as the web server's user in the panel's folder")
        ->toContain('chown -R www-data:www-data storage bootstrap/cache .env')
        ->toContain('composer install --no-dev --optimize-autoloader --no-scripts')
        ->toContain('sudo -u www-data php artisan package:discover');
});
