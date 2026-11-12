<?php

namespace App\Console\Commands;

use App\Models\AppInstall;
use App\Models\DaemonJob;
use App\Support\ServiceTemplates\ServiceTemplateCatalog;
use Illuminate\Console\Command;

class BackfillAppInstalls extends Command
{
    protected $signature = 'apps:backfill-installs {--dry-run : Count what it would add, add nothing}';

    protected $description = 'Record which app older installer deploys were, for the admin Stats';

    public function handle(ServiceTemplateCatalog $catalog): int
    {
        $byCommit = [];
        $byImages = [];
        $byDockerfile = [];
        foreach ($catalog->all() as $template) {
            if ($template->isApp()) {
                if ($template->dockerfile !== null) {
                    $byDockerfile[$template->dockerfile][] = $template->slug;
                }
            } else {
                $byCommit[sha1($template->slug."\n".json_encode($template->files))] = $template->slug;
                $byImages[self::images($template->files['compose.yaml'] ?? '')][] = $template->slug;
            }
        }

        $known = AppInstall::query()->whereNotNull('daemon_job_id')->pluck('daemon_job_id')->flip();
        $seen = [];
        $added = 0;

        DaemonJob::query()->where('kind', 'deploy')->with('machine:id,workspace_id')->orderBy('created_at')->orderBy('id')
            ->lazy(200)
            ->each(function (DaemonJob $job) use ($byCommit, $byImages, $byDockerfile, $known, &$seen, &$added): void {
                $payload = $job->command['job'] ?? null;
                if (! is_array($payload)) {
                    return;
                }

                if (($payload['compose_file'] ?? null) !== null) {
                    $images = self::images((string) (((array) ($payload['files'] ?? []))['compose.yaml'] ?? ''));
                    $slug = $byCommit[$payload['commit'] ?? '']
                        ?? ($images !== '' && count($byImages[$images] ?? []) === 1 ? $byImages[$images][0] : null);
                } else {
                    $slug = count($byDockerfile[$payload['dockerfile'] ?? ''] ?? []) === 1 ? $byDockerfile[$payload['dockerfile']][0] : null;
                }
                if ($slug === null) {
                    return;
                }

                $key = $job->machine_id.'/'.$job->service;
                if (isset($seen[$key])) {
                    return;
                }
                $seen[$key] = true;

                if ($known->has($job->id)) {
                    return;
                }

                if (! $this->option('dry-run')) {
                    AppInstall::query()->create([
                        'workspace_id' => $job->machine->workspace_id,
                        'machine_id' => $job->machine_id,
                        'user_id' => $job->user_id,
                        'daemon_job_id' => $job->id,
                        'template' => $slug,
                        'source' => 'backfill',
                        'created_at' => $job->created_at,
                    ]);
                }
                $added++;
            });

        $this->info(($this->option('dry-run') ? 'Would add ' : 'Added ').$added.' installs.');

        return self::SUCCESS;
    }

    private static function images(string $compose): string
    {
        preg_match_all('/^\s*image:\s*["\']?([^\s"\'@]+)/m', $compose, $matches);
        $names = array_map(fn (string $image): string => preg_replace('/:[^\/:]+$/', '', $image) ?? $image, $matches[1]);
        $names = array_values(array_unique($names));
        sort($names);

        return implode(',', $names);
    }
}
