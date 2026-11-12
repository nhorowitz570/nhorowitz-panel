<?php

namespace App\Console\Commands;

use App\Models\DaemonJob;
use App\Models\DeploySource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PruneDeploySources extends Command
{
    protected $signature = 'deploy-sources:prune';

    protected $description = 'Delete uploaded projects past their expiry, and uploads that stopped';

    public function handle(): int
    {
        $pruned = 0;

        $live = $this->liveTokens();

        DeploySource::query()->where('expires_at', '<=', now())->each(function (DeploySource $source) use (&$pruned, $live) {
            if (isset($live[$source->token]) && $source->status === 'ready') {
                $source->forceFill(['expires_at' => now()->addHours(DeploySource::KEEP_HOURS)])->save();

                return;
            }

            File::deleteDirectory($source->directory());
            $source->delete();
            $pruned++;
        });

        DeploySource::query()
            ->where('status', 'uploading')
            ->where('updated_at', '<=', now()->subMinutes(DeploySource::STALLED_MINUTES))
            ->each(function (DeploySource $source) use (&$pruned) {
                File::deleteDirectory($source->directory());
                $source->forceFill(['status' => 'failed'])->save();
                $pruned++;
            });

        $this->info("Pruned {$pruned} upload(s).");

        return self::SUCCESS;
    }

    /** @return array<string, true> */
    private function liveTokens(): array
    {
        $tokens = [];
        $seen = [];

        DaemonJob::query()
            ->where('kind', 'deploy')
            ->whereIn('state', ['queued', 'running', 'succeeded'])
            ->orderByDesc('created_at')
            ->lazy()
            ->each(function (DaemonJob $job) use (&$tokens, &$seen) {
                $app = $job->machine_id.'|'.$job->service;
                if ($job->state === 'succeeded') {
                    if (isset($seen[$app])) {
                        return;
                    }
                    $seen[$app] = true;
                }
                if (preg_match('#/api/deploy-sources/([A-Za-z0-9]{48})\.git$#', (string) ($job->command['job']['repo'] ?? ''), $match)) {
                    $tokens[$match[1]] = true;
                }
            });

        return $tokens;
    }
}
