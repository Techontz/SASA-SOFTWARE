<?php

namespace App\Providers;

use App\Domain\Ai\AiProvider;
use App\Domain\Ai\ClaudeAiProvider;
use App\Domain\Ai\NullAiProvider;
use App\Domain\Billing\Gateways\PaymentGateway;
use App\Domain\Billing\Gateways\SandboxGateway;
use App\Domain\Billing\Gateways\SnippeGateway;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Voice\NullVoiceProvider;
use App\Domain\Voice\VoiceProvider;
use App\Models\Attachment;
use App\Models\Commitment;
use App\Models\Concern;
use App\Models\Engagement;
use App\Models\EngagementPlan;
use App\Models\EshsAnomaly;
use App\Models\Grievance;
use App\Models\GrievanceFollowUp;
use App\Models\Incident;
use App\Models\Pap;
use App\Models\Stakeholder;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);

        // Providers are swappable: the application is never written around one
        // vendor's SDK.
        $this->app->bind(AiProvider::class, function () {
            return match (config('sasa.ai.provider')) {
                'anthropic', 'claude' => new ClaudeAiProvider,
                default => new NullAiProvider,
            };
        });

        $this->app->bind(PaymentGateway::class, function () {
            return match (config('sasa.billing.driver')) {
                'snippe' => new SnippeGateway,
                default => new SandboxGateway,
            };
        });

        $this->app->bind(VoiceProvider::class, function () {
            return match (config('sasa.voice.provider')) {
                default => new NullVoiceProvider,
            };
        });
    }

    public function boot(): void
    {
        // Stable morph aliases so a class rename never orphans an audit row.
        Relation::enforceMorphMap([
            'stakeholder' => Stakeholder::class,
            'engagement' => Engagement::class,
            'engagement_plan' => EngagementPlan::class,
            'concern' => Concern::class,
            'commitment' => Commitment::class,
            'grievance' => Grievance::class,
            'grievance_follow_up' => GrievanceFollowUp::class,
            'pap' => Pap::class,
            'eshs_anomaly' => EshsAnomaly::class,
            'incident' => Incident::class,
            'attachment' => Attachment::class,
            'user' => User::class,
        ]);

        Password::defaults(fn () => Password::min(config('sasa.auth.password_min_length', 10))
            ->letters()
            ->mixedCase()
            ->numbers()
            ->uncompromised(false));

        // A system administrator carries every ability everywhere.
        Gate::before(fn ($user) => $user->is_system_admin ? true : null);

        $this->configureRateLimiting();

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }

    /**
     * Generous enough for a dashboard that loads several panels at once,
     * tight enough that a scripted export loop is noticed.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => $request->user()
            ? Limit::perMinute(240)->by('user:'.$request->user()->id)
            : Limit::perMinute(40)->by('ip:'.$request->ip()));

        // Offline devices arrive with a backlog; the batch itself is capped.
        /*
         * Sign-in. Per-account brute force is handled properly by the lockout
         * in AuthController — five FAILED attempts locks the account for
         * fifteen minutes. A middleware limit here would count successful
         * sign-ins too, which punishes a shared field device for no security
         * gain, so this is only a coarse per-IP backstop, loose enough for an
         * office where twenty people share one address.
         */
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(40)
            ->by('auth-ip:'.$request->ip()));

        RateLimiter::for('sync', fn (Request $request) => Limit::perMinute(60)
            ->by('sync:'.($request->user()?->id ?? $request->ip())));

        // The public subscribe journey: writes are tight, the status polling
        // (every two or three seconds while a phone prompt is open) is not.
        RateLimiter::for('billing', fn (Request $request) => Limit::perMinute(30)
            ->by('billing:'.$request->ip()));

        RateLimiter::for('billing-poll', fn (Request $request) => Limit::perMinute(120)
            ->by('billing-poll:'.$request->ip()));

        RateLimiter::for('billing-webhook', fn (Request $request) => Limit::perMinute(240)
            ->by('billing-webhook:'.$request->ip()));

        // The website's "Register interest" and "Book a demo" forms.
        RateLimiter::for('enquiries', fn (Request $request) => Limit::perMinute(10)
            ->by('enquiries:'.$request->ip()));

        RateLimiter::for('reports', fn (Request $request) => Limit::perMinute(10)
            ->by('reports:'.($request->user()?->id ?? $request->ip())));
    }
}
