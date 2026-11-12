<?php

namespace Tests;

use App\Support\Audit\AuditCoverage;
use App\Support\Auth\MailExchangers;
use App\Support\Auth\TxtRecords;
use App\Support\Logs\LogStore;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Laravel\Fortify\Features;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        LogStore::flush();

        $install = sys_get_temp_dir().'/serveros-install-'.getmypid().'-'.bin2hex(random_bytes(6));
        config(['serveros.install.path' => $install]);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($install));

        $this->app->instance(MailExchangers::class, new class extends MailExchangers
        {
            /** @var array<string, list<string>> */
            public array $hosts = [];

            public function for(string $domain): array
            {
                return $this->hosts[$domain] ?? [];
            }
        });

        $this->app->instance(TxtRecords::class, new class extends TxtRecords
        {
            /** @var array<string, list<string>> */
            public array $records = [];

            /** @var list<string> */
            public array $failing = [];

            public function for(string $host): ?array
            {
                return in_array($host, $this->failing, true) ? null : ($this->records[$host] ?? []);
            }
        });

        Event::listen(RequestHandled::class, function (RequestHandled $event): void {
            if (($problem = AuditCoverage::missedRecording($event->request, $event->response)) !== null) {
                throw new RuntimeException($problem);
            }
            if (($missed = AuditCoverage::missedToolRecordings($event->request)) !== []) {
                throw new RuntimeException(implode(' ', $missed));
            }
        });
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
