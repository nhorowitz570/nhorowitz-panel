<?php

namespace App\Providers;

use App\Console\Dev\DevDaemon;
use App\Console\Dev\DevNode;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use App\Listeners\RecordAccountEvents;
use App\Models\User;
use App\Support\Admin\AdminPages;
use App\Support\Admin\Impersonation;
use App\Support\CachedSsrGateway;
use App\Support\Domains\CustomDomains;
use App\Support\Edition;
use App\Support\Entitlements\Entitlements;
use App\Support\Entitlements\PlanEntitlements;
use App\Support\Entitlements\SelfHostedEntitlements;
use App\Support\Extensions\Billing;
use App\Support\Extensions\DocsSource;
use App\Support\Extensions\Extensions;
use App\Support\Extensions\NoBilling;
use App\Support\Extensions\NoDocs;
use App\Support\Extensions\NoPrices;
use App\Support\Extensions\NoTrialCards;
use App\Support\Extensions\Prices;
use App\Support\Extensions\TrialCards;
use App\Support\GitHub\StoredGitHubApp;
use App\Support\Licensing\CommunityLicense;
use App\Support\Mail\BrandMail;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Ssr\Gateway;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $config = $this->app->make('config');
        if (blank($config->get('mail.support.address'))) {
            $config->set('mail.support.address', $config->get('mail.from.address'));
        }

        $this->app->singleton(CachedSsrGateway::class);
        $this->app->alias(CachedSsrGateway::class, Gateway::class);

        $this->app->singleton(Entitlements::class, fn () => Edition::selfHosted()
            ? new SelfHostedEntitlements
            : new PlanEntitlements);

        $this->app->singleton(Extensions::class);
        $this->app->scoped(StoredGitHubApp::class);
        $this->app->singletonIf(TrialCards::class, NoTrialCards::class);
        $this->app->singletonIf(Billing::class, NoBilling::class);
        $this->app->singletonIf(DocsSource::class, NoDocs::class);
        $this->app->singletonIf(Prices::class, NoPrices::class);
    }

    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAccountEmails();

        URL::formatHostUsing(fn (string $root, mixed $route = null) => CustomDomains::mapRoot($root, $route));

        AdminPages::register($this->app->make(Extensions::class));

        CommunityLicense::register($this->app->make(Extensions::class));

        if ($this->app->isLocal() && $this->app->runningInConsole()) {
            $this->commands([DevDaemon::class, DevNode::class]);
        }

        Event::listen(Login::class, function (Login $event): void {
            if (RecordAccountEvents::signInMethod(request()->route()?->getName()) === null) {
                return;
            }
            $locale = request()->cookie(SetLocale::COOKIE);
            if ($event->user instanceof User && is_string($locale) && $locale !== $event->user->locale
                && array_key_exists($locale, config('app.locales'))) {
                $event->user->forceFill(['locale' => $locale])->save();
            }
        });

        Event::subscribe(RecordAccountEvents::class);

        Inertia::disableSsr(fn (): bool => ! config('inertia.ssr.enabled')
            || ! app(HandleInertiaRequests::class)->shouldServerRender(request()));

        Event::listen([Logout::class, CurrentDeviceLogout::class], function (): void {
            if (($request = request())->hasSession()) {
                Impersonation::end($request, 'signed_out');
            }
        });

        Gate::define('viewAdmin', fn (User $user): bool => $user->is_admin && $user->email_verified_at !== null && $user->two_factor_confirmed_at !== null);
        Gate::define('viewPulse', fn (User $user): bool => Gate::forUser($user)->allows('viewAdmin'));

        RateLimiter::for('docs-assistant', fn (Request $request) => [
            Limit::perMinute(6)->by('docs-assistant|'.$request->ip()),
            Limit::perDay(60)->by('docs-assistant-day|'.$request->ip()),
        ]);
    }

    protected function configureAccountEmails(): void
    {
        VerifyEmail::createUrlUsing(function (User $notifiable): string {
            $url = clone app('url');
            $url->forceRootUrl((string) config('app.url'));

            return $url->temporarySignedRoute('verification.verify', now()->addMinutes((int) config('auth.verification.expire', 60)), [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]);
        });

        VerifyEmail::toMailUsing(fn (object $notifiable, string $url) => BrandMail::apply((new MailMessage)
            ->subject('Confirm your email for ServerOS')
            ->line('One click and your workspace is ready.')
            ->action('Confirm email', $url), [
                'image' => 'verify',
                'heading' => 'Confirm your email',
                'paragraphs' => ['One click and your workspace is ready. Then connect a server you already run, and ServerOS finds everything on it.'],
                'button' => ['label' => 'Confirm email', 'url' => $url],
                'note' => 'The link works for '.self::minutes((int) config('auth.verification.expire', 60)).'. If you didn’t sign up for ServerOS, ignore this email and nothing else will arrive.',
            ]));

        ResetPassword::toMailUsing(function (object $notifiable, string $token) {
            $email = method_exists($notifiable, 'getEmailForPasswordReset') ? $notifiable->getEmailForPasswordReset() : '';
            $url = rtrim((string) config('app.url'), '/').route('password.reset', ['token' => $token, 'email' => $email], false);
            $expires = self::minutes((int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60));

            return BrandMail::apply((new MailMessage)
                ->subject('Reset your ServerOS password')
                ->line('Someone asked to reset the password for your ServerOS account.')
                ->action('Choose a new password', $url), [
                    'image' => 'reset',
                    'heading' => 'Reset your password',
                    'paragraphs' => ['Someone asked to reset the password for your ServerOS account. If it was you, choose a new one and you’re back in.'],
                    'button' => ['label' => 'Choose a new password', 'url' => $url],
                    'note' => "The link works for {$expires}. If you didn’t ask for this, ignore this email and your password stays as it is.",
                ]);
        });
    }

    private static function minutes(int $minutes): string
    {
        return match (true) {
            $minutes === 60 => 'an hour',
            $minutes % 60 === 0 => ($minutes / 60).' hours',
            default => $minutes.' minutes',
        };
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
