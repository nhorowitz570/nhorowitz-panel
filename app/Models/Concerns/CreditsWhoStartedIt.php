<?php

namespace App\Models\Concerns;

use App\Support\Audit\AuditContext;

trait CreditsWhoStartedIt
{
    public function startedByName(): ?string
    {
        if ($this->impersonation_session_id !== null) {
            $word = __(AuditContext::SUPPORT);

            return is_string($word) ? $word : AuditContext::SUPPORT;
        }

        return $this->user?->name;
    }
}
