<?php

use App\Support\Monitors\HostResolver;

function resolverWithDns(array $answers): HostResolver
{
    return new class($answers) extends HostResolver
    {
        public function __construct(private readonly array $answers) {}

        protected function addresses(string $host): array
        {
            return $this->answers[$host] ?? [];
        }
    };
}

test('addresses inside private, shared, translated and special ranges are refused', function (string $ip) {
    expect((new HostResolver)->allowed($ip))->toBeFalse()
        ->and(resolverWithDns(['sneaky.example.com' => [$ip]])->resolve('sneaky.example.com'))->toBe([]);
})->with([
    'loopback' => '127.0.0.1',
    'private' => '10.1.2.3',
    'metadata' => '169.254.169.254',
    'cgnat start' => '100.64.0.1',
    'tailscale' => '100.100.100.100',
    'cgnat end' => '100.127.255.254',
    'benchmarking' => '198.18.0.1',
    'ietf' => '192.0.0.1',
    'multicast' => '224.0.0.251',
    'broadcast' => '255.255.255.255',
    'unspecified' => '0.0.0.0',
    'v6 loopback' => '::1',
    'v6 unspecified' => '::',
    'unique local' => 'fd12:3456::1',
    'v6 link local' => 'fe80::1',
    'v6 multicast' => 'ff02::1',
    'nat64' => '64:ff9b::a9fe:a9fe',
    'local nat64' => '64:ff9b:1::a9fe:a9fe',
    '6to4' => '2002:a9fe:a9fe::1',
    'teredo' => '2001:0:a9fe:a9fe::1',
    'mapped' => '::ffff:169.254.169.254',
    'mapped long form' => '0:0:0:0:0:ffff:7f00:1',
    'translated' => '::ffff:0:a9fe:a9fe',
    'compatible' => '::a9fe:a9fe',
]);

test('public addresses resolve, IPv4 first', function () {
    expect(resolverWithDns(['shop.example.com' => ['2606:4700::1111', '93.184.215.14']])->resolve('shop.example.com'))->toBe(['93.184.215.14', '2606:4700::1111'])
        ->and((new HostResolver)->resolve('8.8.8.8'))->toBe(['8.8.8.8'])
        ->and((new HostResolver)->resolve('[2606:4700::1111]'))->toBe(['2606:4700::1111']);
});

test('one private answer among public ones refuses the whole name', function () {
    expect(resolverWithDns(['mixed.example.com' => ['93.184.215.14', '100.101.5.7']])->resolve('mixed.example.com'))->toBe([]);
});

test('private targets are only ever allowed on a self-hosted install', function () {
    config()->set('monitors.allow_private', true);
    $dns = resolverWithDns(['minio.lan.example' => ['192.168.1.20']]);

    expect($dns->allowed('10.0.0.5'))->toBeFalse()
        ->and($dns->resolve('minio.lan.example'))->toBe([])
        ->and($dns->resolveOnLan('minio.lan.example'))->toBe([]);

    config()->set('serveros.edition', 'self-hosted');
    expect($dns->allowed('10.0.0.5'))->toBeTrue()
        ->and($dns->resolve('minio.lan.example'))->toBe(['192.168.1.20']);

    config()->set('monitors.allow_private', false);
    expect($dns->resolve('minio.lan.example'))->toBe([])
        ->and($dns->resolveOnLan('minio.lan.example'))->toBe(['192.168.1.20']);
});
