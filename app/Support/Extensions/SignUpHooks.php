<?php

namespace App\Support\Extensions;

use App\Models\User;
use Illuminate\Http\Request;

abstract class SignUpHooks
{
    public function signedUp(Request $request, User $user): void {}

    public function source(User $user): ?string
    {
        return null;
    }
}
