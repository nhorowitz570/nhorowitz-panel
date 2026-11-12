<?php

namespace App\Http\Controllers\Auth;

use App\Http\Responses\LogoutResponse as SignOutResponse;
use App\Support\Extensions\Extensions;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LogoutResponse;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController as FortifyAuthenticatedSessionController;

class AuthenticatedSessionController extends FortifyAuthenticatedSessionController
{
    public function destroy(Request $request): LogoutResponse
    {
        foreach (app(Extensions::class)->signInHooks() as $hooks) {
            $url = $hooks->signingOut($request);
            if ($url !== null) {
                $request->attributes->set(SignOutResponse::REDIRECT, $url);
            }
        }

        if ($this->guard instanceof SessionGuard) {
            $this->guard->logoutCurrentDevice();
        } else {
            $this->guard->logout();
        }

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return app(LogoutResponse::class);
    }
}
