<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Symfony\Component\HttpFoundation\Response;

class PasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public function __construct(private string $status) {}

    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        if (! in_array($this->status, [Password::RESET_LINK_SENT, Password::INVALID_USER, Password::RESET_THROTTLED], true)) {
            if ($request->wantsJson()) {
                throw ValidationException::withMessages(['email' => [trans($this->status)]]);
            }

            return back()->withInput($request->only('email'))->withErrors(['email' => trans($this->status)]);
        }

        $message = __('If that email has an account, we’ve sent it a link to reset the password.');

        return $request->wantsJson()
            ? new JsonResponse(['message' => $message], 200)
            : back()->with('status', $message);
    }
}
