<?php

use App\Models\Machine;
use App\Models\ServiceSample;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

function sendServiceReading(Machine $machine, int $ts): void
{
    test()->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', ['envelopes' => [['v' => 1, 'kind' => 'telemetry', 'payload' => ['samples' => [
        ['ts' => $ts, 'service' => 'docker:abc123def456', 'cpu_percent' => 3.2, 'mem_used' => 1000, 'process_count' => 2],
    ]]]]], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();
}

function sampleAt(Machine $machine, int $ts, string $service = 'docker:abc123def456'): void
{
    ServiceSample::query()->create(['machine_id' => $machine->id, 'service' => $service, 'ts' => $ts, 'cpu' => 1, 'mem_used' => 100]);
}

test('service readings are kept at most once per 15 seconds per machine', function () {
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Carbon::setTestNow('2026-10-10 12:00:00');
    $machine = Machine::factory()->create();
    $machine->forceFill(['uid' => (string) Str::uuid()])->save();

    sendServiceReading($machine, now()->getTimestamp());
    sendServiceReading($machine, now()->getTimestamp() + 5);
    expect(ServiceSample::query()->count())->toBe(1)
        ->and($machine->fresh()->service_stats)->toHaveKey('docker:abc123def456');

    $this->travel(ServiceSample::EVERY_SECONDS)->seconds();
    sendServiceReading($machine, now()->getTimestamp());
    expect(ServiceSample::query()->count())->toBe(2);
});

test('pruning drops day-old readings from every machine and thins older ones to a minute', function () {
    Carbon::setTestNow('2026-10-10 12:00:00');
    $now = now()->getTimestamp();
    $quiet = Machine::factory()->create();
    $live = Machine::factory()->create();

    sampleAt($quiet, $now - 4 * 86400);
    $minute = intdiv($now - 7200, 60) * 60;
    foreach ([0, 15, 30, 45] as $offset) {
        sampleAt($live, $minute + $offset);
        sampleAt($live, $minute + $offset, 'systemd:nginx.service');
    }
    foreach ([60, 45, 30] as $ago) {
        sampleAt($live, $now - $ago);
    }

    $this->artisan('logs:prune')->assertSuccessful();

    expect(ServiceSample::query()->where('machine_id', $quiet->id)->count())->toBe(0)
        ->and(ServiceSample::query()->where('ts', '<', $now - ServiceSample::FULL_SECONDS)->pluck('ts', 'service')->all())
        ->toBe(['docker:abc123def456' => $minute, 'systemd:nginx.service' => $minute])
        ->and(ServiceSample::query()->where('ts', '>=', $now - ServiceSample::FULL_SECONDS)->count())->toBe(3);
});
