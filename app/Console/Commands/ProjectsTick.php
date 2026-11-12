<?php

namespace App\Console\Commands;

use App\Models\ProjectEnvironment;
use App\Support\Projects\Environments;
use App\Support\Projects\ProjectData;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('projects:tick')]
#[Description('Start waiting data copies and refresh environments on their schedule')]
class ProjectsTick extends Command
{
    public function handle(ProjectData $data, Environments $environments): int
    {
        $data->tick(CarbonImmutable::now());

        foreach (ProjectEnvironment::query()->whereNotNull('removing_at')->with('project.workspace.owner')->get() as $environment) {
            $owner = $environment->project->workspace->owner;
            if ($owner !== null) {
                $environments->continueRemoving($environment, $owner);
            }
        }

        return self::SUCCESS;
    }
}
