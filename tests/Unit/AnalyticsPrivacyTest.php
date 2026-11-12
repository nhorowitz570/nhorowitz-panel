<?php

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

function runAnalyticsPrivacy(string $script): mixed
{
    $module = json_encode('file://'.dirname(__DIR__, 2).'/resources/js/lib/analyticsPrivacy.ts');
    $process = new Process(['node', '--experimental-strip-types', '--no-warnings', '--input-type=module', '-e', "const p = await import({$module});\n{$script}"]);
    $process->mustRun();

    return json_decode($process->getOutput(), true);
}

beforeEach(function () {
    if ((new ExecutableFinder)->find('node') === null) {
        $this->markTestSkipped('Needs node.');
    }
});

it('keeps reset tokens, emails and invitation tokens out of analytics events', function () {
    $out = runAnalyticsPrivacy(<<<'JS'
        const event = p.scrubEvent({
            event: '$pageview',
            properties: {
                $current_url: 'https://serveros.com/reset-password/abc123secret?email=a%40b.com',
                $pathname: '/reset-password/abc123secret',
                $referrer: 'https://acme.serveros.com/invitations/invtoken99#x',
                $heatmap_data: { 'https://serveros.com/_domain/handoff/hand0ff?x=1': [1] },
            },
            $set_once: { $initial_current_url: 'https://serveros.com/email/verify/7/deadbeef?expires=1&signature=s1g' },
        });
        console.log(JSON.stringify(event));
    JS);

    expect(json_encode($out))->not->toContain('abc123secret')
        ->not->toContain('a%40b.com')
        ->not->toContain('invtoken99')
        ->not->toContain('hand0ff')
        ->not->toContain('deadbeef')
        ->not->toContain('s1g')
        ->and($out['properties']['$current_url'])->toBe('https://serveros.com/reset-password/[redacted]')
        ->and($out['properties']['$pathname'])->toBe('/reset-password/[redacted]')
        ->and($out['properties']['$referrer'])->toBe('https://acme.serveros.com/invitations/[redacted]')
        ->and($out['$set_once']['$initial_current_url'])->toBe('https://serveros.com/email/verify/[redacted]');
});

it('leaves ordinary pages and campaign tags alone', function () {
    $out = runAnalyticsPrivacy(<<<'JS'
        console.log(JSON.stringify([
            p.scrubUrl('https://acme.serveros.com/machines/xyz'),
            p.scrubUrl('https://serveros.com/pricing?utm_source=x&token=y'),
            p.scrubUrl('$direct'),
            p.scrubRequest({ name: 'https://serveros.com/status/acme/unsubscribe/tok3n' }).name,
        ]));
    JS);

    expect($out)->toBe([
        'https://acme.serveros.com/machines/xyz',
        'https://serveros.com/pricing?utm_source=x',
        '$direct',
        'https://serveros.com/status/acme/unsubscribe/[redacted]',
    ]);
});

it('scrubs page URLs nested in web vitals and custom domain status links', function () {
    $out = runAnalyticsPrivacy(<<<'JS'
        const event = p.scrubEvent({
            event: '$web_vitals',
            properties: {
                $current_url: 'https://serveros.com/reset-password/vit4lsecret?email=a%40b.com',
                $web_vitals_LCP_event: {
                    name: 'LCP',
                    $current_url: 'https://serveros.com/reset-password/vit4lsecret?email=a%40b.com',
                    navigationURL: 'https://serveros.com/reset-password/vit4lsecret',
                    attribution: { url: 'https://serveros.com/build/logo.png?v=2' },
                },
            },
        });
        console.log(JSON.stringify([
            event,
            p.scrubUrl('https://status.acme.com/unsubscribe/sub5cr1ber'),
            p.scrubUrl('https://acme.com/status/subscribe/sub5cr1ber'),
            p.scrubRequest({ name: 'https://acme.serveros.com/login', requestBody: '{"password":"hunter2"}', responseBody: 'ok' }),
        ]));
    JS);

    expect(json_encode($out))->not->toContain('vit4lsecret')
        ->not->toContain('a%40b.com')
        ->not->toContain('sub5cr1ber')
        ->not->toContain('hunter2')
        ->and($out[0]['properties']['$web_vitals_LCP_event']['attribution']['url'])->toBe('https://serveros.com/build/logo.png')
        ->and($out[1])->toBe('https://status.acme.com/unsubscribe/[redacted]')
        ->and($out[3])->toBe(['name' => 'https://acme.serveros.com/login']);
});

it('masks panel text in analytics and scrubs every event', function () {
    $source = file_get_contents(dirname(__DIR__, 2).'/resources/js/lib/analytics.ts');

    expect($source)->toContain('before_send: (event) => scrubEvent(event)')
        ->toContain("mask_all_text: source === 'panel'")
        ->toContain("maskTextSelector: source === 'panel' ? '*' : null")
        ->toContain('maskCapturedNetworkRequestFn');
});
