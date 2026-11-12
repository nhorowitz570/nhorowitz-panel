<?php

namespace App\Support\GitHub;

use App\Models\GitHubAppSetting;
use Throwable;

/**
 * The saved GitHub App, read once per request or queue job (it's bound as a
 * scoped instance), so a change applies to the next one without a restart.
 */
final class StoredGitHubApp
{
    private bool $loaded = false;

    private ?GitHubAppSetting $setting = null;

    public function get(): ?GitHubAppSetting
    {
        if (! $this->loaded) {
            $this->loaded = true;
            try {
                $this->setting = GitHubAppSetting::query()->latest('id')->first();
            } catch (Throwable) {
                $this->setting = null;
            }
        }

        return $this->setting;
    }

    public function forget(): void
    {
        $this->loaded = false;
        $this->setting = null;
    }
}
