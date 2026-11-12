<?php

namespace App\Support\Monitors;

use App\Models\Monitor;
use App\Models\Workspace;
use App\Support\Edition;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MonitorSettings
{
    public function __construct(private readonly HostResolver $resolver) {}

    public static function limitReached(Workspace $workspace): ?string
    {
        $limit = $workspace->monitorLimit();
        if ($workspace->monitors()->count() < $limit) {
            return null;
        }

        if (Edition::selfHosted()) {
            return "This install allows {$limit} monitors per workspace. Remove one, or ask whoever runs this install to raise SERVEROS_SELF_HOSTED_MONITORS.";
        }

        return $limit === 0
            ? 'Choose a plan to add monitors.'
            : "Your plan includes {$limit} monitors. Remove one or upgrade to add more.";
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(array $input, Workspace $workspace): array
    {
        $min = $workspace->minMonitorInterval();
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::in(Monitor::TYPES)],
            'url' => ['nullable', 'required_if:type,http', 'string', 'max:2048', 'url:http,https'],
            'host' => ['nullable', 'required_if:type,tcp', 'string', 'max:255', 'regex:/^[A-Za-z0-9.:\-\[\]]+$/'],
            'port' => ['nullable', 'required_if:type,tcp', 'integer', 'between:1,65535'],
            'interval' => ['required', 'integer', Rule::in(Monitor::INTERVALS)],
            'timeout' => ['required', 'integer', 'between:2,30'],
            'expected_status' => ['nullable', 'integer', 'between:100,599'],
            'keyword' => ['nullable', 'string', 'max:200'],
            'machine_id' => ['nullable', 'integer', Rule::exists('machines', 'id')->where('workspace_id', $workspace->id)],
            'alerts' => ['required', 'boolean'],
        ], [
            'url.url' => 'Enter a full address, starting with http:// or https://.',
            'host.regex' => 'Enter a hostname or an IP address.',
        ])->validate();

        if ($data['interval'] < $min) {
            throw ValidationException::withMessages(['interval' => (Edition::selfHosted() ? 'This install checks at most every ' : 'Your plan checks at most every ').($min >= 60 ? ($min / 60).' minutes' : "{$min} seconds").'.']);
        }

        $http = $data['type'] === 'http';
        $host = $http ? (string) parse_url((string) $data['url'], PHP_URL_HOST) : (string) $data['host'];
        if ($this->resolver->resolve($host) === []) {
            throw ValidationException::withMessages([$http ? 'url' : 'host' => filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false || $this->resolves($host)
                ? 'Private and internal addresses can’t be monitored from the panel.'
                : "Couldn’t find {$host} in DNS."]);
        }

        return [
            'name' => trim($data['name']),
            'type' => $data['type'],
            'url' => $http ? trim((string) $data['url']) : null,
            'host' => $http ? null : trim((string) $data['host']),
            'port' => $http ? null : (int) $data['port'],
            'interval_seconds' => (int) $data['interval'],
            'timeout_seconds' => min((int) $data['timeout'], (int) $data['interval']),
            'expected_status' => $http && isset($data['expected_status']) ? (int) $data['expected_status'] : null,
            'keyword' => $http && isset($data['keyword']) && trim($data['keyword']) !== '' ? trim($data['keyword']) : null,
            'machine_id' => $data['machine_id'] ?? null,
            'alerts' => (bool) $data['alerts'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function update(Monitor $monitor, array $data): bool
    {
        $changedTarget = $data['type'] !== $monitor->type || $data['url'] !== $monitor->url
            || $data['host'] !== $monitor->host || $data['port'] !== $monitor->port;

        $monitor->fill($data);
        if ($changedTarget) {
            $monitor->forceFill(['failures' => 0, 'next_check_at' => now(), 'ssl_checked_at' => null, 'ssl_expires_at' => null, 'ssl_alerted_at' => null]);
        }
        $monitor->save();

        return $changedTarget;
    }

    public static function pause(Monitor $monitor, bool $paused): void
    {
        if ($paused) {
            $monitor->openIncident()->first()?->update(['resolved_at' => now()]);
            $monitor->forceFill(['paused' => true, 'status' => 'pending', 'failures' => 0, 'status_changed_at' => now()])->save();
        } else {
            $monitor->forceFill(['paused' => false, 'next_check_at' => now()])->save();
        }
    }

    private function resolves(string $host): bool
    {
        return (@dns_get_record($host, DNS_A | DNS_AAAA) ?: @gethostbynamel($host)) ? true : false;
    }
}
