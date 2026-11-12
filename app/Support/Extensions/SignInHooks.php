<?php

namespace App\Support\Extensions;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;

abstract class SignInHooks
{
    /** @return array<string, string> */
    public function signInRoutes(): array
    {
        return [];
    }

    public function refuseSocialSignIn(Request $request, ?User $user, string $email): ?string
    {
        return null;
    }

    public function signedIn(Request $request, User $user, string $method): void {}

    public function challenged(Request $request): void {}

    public function signingOut(Request $request): ?string
    {
        return null;
    }

    public function meetsTwoFactor(Workspace $workspace, User $user, Request $request): bool
    {
        return false;
    }

    /** @return array<string, mixed> */
    public function handoff(Request $request): array
    {
        return [];
    }

    /** @param  array<string, mixed>  $carried */
    public function receiveHandoff(Request $request, array $carried, User $user): void {}
}
