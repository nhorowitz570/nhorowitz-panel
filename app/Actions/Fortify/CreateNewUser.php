<?php

namespace App\Actions\Fortify;

use App\Actions\Workspaces\CreateWorkspace;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Support\Auth\SignUps;
use App\Support\Auth\Turnstile;
use App\Support\Edition;
use App\Support\Extensions\Extensions;
use App\Support\Install\SetupCode;
use App\Support\TeamPush;
use App\Support\WorkspaceSlugs;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public const SIGNUPS_PER_HOUR = 3;

    public const FIRST_OWNER_LOCK = 'self-hosted:first-owner';

    public function __construct(
        private WorkspaceSlugs $workspaceSlugs,
        private CreateWorkspace $createWorkspace,
    ) {}

    /** @param  array<string, string>  $input */
    public function create(array $input): User
    {
        $limit = 'signups:'.request()->ip();
        if (RateLimiter::tooManyAttempts($limit, self::SIGNUPS_PER_HOUR)) {
            throw ValidationException::withMessages([
                'email' => 'Too many accounts have been made from your network in the last hour. Try again later, or email '.config('mail.support.address').'.',
            ]);
        }

        if (isset($input['workspace'])) {
            $input['workspace'] = trim(strtolower(trim($input['workspace'])), '-');
        }

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'workspace' => ['nullable', ...$this->workspaceSlugs->rules()],
        ])->validate();

        if (! SignUps::allows($input['email'])) {
            throw ValidationException::withMessages(['email' => SignUps::CLOSED]);
        }

        $token = $input['cf-turnstile-response'] ?? null;
        if (! app(Turnstile::class)->passes(is_string($token) ? $token : null, request()->ip())) {
            throw ValidationException::withMessages([
                'turnstile' => 'We couldn’t check you’re a person. Try again in a moment.',
            ]);
        }

        RateLimiter::hit($limit, 3600);

        if (Edition::selfHosted() && ! app(SetupCode::class)->pending() && User::query()->doesntExist()) {
            return Cache::lock(self::FIRST_OWNER_LOCK, 30)
                ->block(15, fn (): User => $this->createUser($input, owner: User::query()->doesntExist()));
        }

        return $this->createUser($input, owner: false);
    }

    /** @param  array<string, string>  $input */
    private function createUser(array $input, bool $owner): User
    {
        return DB::transaction(function () use ($input, $owner): User {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);
            $user->forceFill([
                'password_set_at' => now(),
                ...($owner ? ['email_verified_at' => now()] : []),
            ])->save();

            $extensions = app(Extensions::class);
            $extensions->signedUp(request(), $user);

            $this->createWorkspace->create($user, $input['workspace'] ?? null);

            $from = $extensions->signUpSource($user);
            TeamPush::send('New signup', 'A new user signed up'.($from !== null ? " from {$from}" : ''), TeamPush::admin('admin.users'), 'wave');

            return $user;
        });
    }
}
