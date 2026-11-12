<?php

namespace App\Support\Backups;

use App\Support\Monitors\HostResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class S3Probe
{
    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * @param  array{endpoint: string, region: string, bucket: string, access_key: string, secret_key: string, path_style: bool, prefix: string}  $s
     */
    public function check(array $s): ?string
    {
        $key = trim($s['prefix'].'/.serveros-check', '/');
        $body = 'ServerOS can write here.';
        $host = $s['path_style'] ? $s['endpoint'] : "{$s['bucket']}.{$s['endpoint']}";
        $path = '/'.($s['path_style'] ? $s['bucket'].'/' : '').implode('/', array_map('rawurlencode', explode('/', $key)));
        $headers = $this->sign($s, $host, $path, $body, gmdate('Ymd\THis\Z'));

        $name = (string) parse_url("https://{$host}", PHP_URL_HOST);
        $port = parse_url("https://{$host}", PHP_URL_PORT) ?? 443;
        $addresses = $name === '' ? [] : $this->resolver->resolveOnLan($name);
        if ($addresses === []) {
            return "Couldn't reach {$s['endpoint']}, or it points into a private network.";
        }
        $ip = str_contains($addresses[0], ':') ? "[{$addresses[0]}]" : $addresses[0];

        try {
            $response = Http::withHeaders($headers)
                ->withBody($body, 'text/plain')
                ->timeout(15)
                ->withOptions(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => ["{$name}:{$port}:{$ip}"]]])
                ->put("https://{$host}{$path}");
        } catch (ConnectionException) {
            return "Couldn't reach {$s['endpoint']}. Check the endpoint.";
        }

        if ($response->successful()) {
            return null;
        }

        $code = preg_match('/<Code>([A-Za-z]{1,64})<\/Code>/', $response->body(), $m) === 1 ? $m[1] : null;

        return match ($code) {
            'NoSuchBucket' => "There's no bucket called {$s['bucket']} there.",
            'InvalidAccessKeyId', 'SignatureDoesNotMatch' => 'Those keys were turned down. Check the access key and secret.',
            'AccessDenied' => "The keys can't write to {$s['bucket']}.",
            'AuthorizationHeaderMalformed', 'IllegalLocationConstraintException' => 'The region looks wrong for this bucket.',
            default => "The storage answered HTTP {$response->status()}".($code ? " ({$code})" : '').'.',
        };
    }

    /**
     * @param  array{region: string, access_key: string, secret_key: string}  $s
     * @return array<string, string>
     */
    public function sign(array $s, string $host, string $path, string $body, string $timestamp): array
    {
        $date = substr($timestamp, 0, 8);
        $hash = hash('sha256', $body);
        $canonical = "PUT\n{$path}\n\nhost:{$host}\nx-amz-content-sha256:{$hash}\nx-amz-date:{$timestamp}\n\nhost;x-amz-content-sha256;x-amz-date\n{$hash}";
        $scope = "{$date}/{$s['region']}/s3/aws4_request";
        $toSign = "AWS4-HMAC-SHA256\n{$timestamp}\n{$scope}\n".hash('sha256', $canonical);

        $key = hash_hmac('sha256', $date, 'AWS4'.$s['secret_key'], true);
        $key = hash_hmac('sha256', $s['region'], $key, true);
        $key = hash_hmac('sha256', 's3', $key, true);
        $key = hash_hmac('sha256', 'aws4_request', $key, true);

        return [
            'x-amz-date' => $timestamp,
            'x-amz-content-sha256' => $hash,
            'Authorization' => "AWS4-HMAC-SHA256 Credential={$s['access_key']}/{$scope}, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=".hash_hmac('sha256', $toSign, $key),
        ];
    }
}
