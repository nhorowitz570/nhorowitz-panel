<?php

namespace App\Console\Commands;

use App\Support\Health\MachineWatcher;
use Illuminate\Console\Command;

class WatchMachines extends Command
{
    protected $signature = 'machines:watch';

    protected $description = 'Alert when a server stops reporting or its disk nearly fills';

    public function handle(MachineWatcher $watcher): int
    {
        $sent = $watcher->run();
        $this->info("Sent {$sent} ".($sent === 1 ? 'alert' : 'alerts').'.');

        return self::SUCCESS;
    }
}
