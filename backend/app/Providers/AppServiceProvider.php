<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Core\Models\AuditLog;
use App\Domain\Core\Models\User;
use App\Domain\Core\Services\SettingService;
use App\Policies\AuditLogPolicy;
use App\Policies\UserPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SettingService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configureAuthorization();

        // Nama morph yang stabil: auditable_type tidak berubah saat namespace model dirapikan.
        Relation::enforceMorphMap([
            'user' => User::class,
        ]);

        // Membantu menangkap N+1 query sejak fase pengembangan dan test.
        Model::preventLazyLoading(! $this->app->isProduction());
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(
            (int) config('app.api_rate_limit', 120)
        )->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('login', fn (Request $request): array => [
            Limit::perMinute((int) config('app.login_rate_limit', 5))
                ->by(Str::lower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by((string) $request->ip()),
        ]);
    }

    private function configureAuthorization(): void
    {
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);

        // Dokumentasi API sengaja terbuka untuk demo portofolio. Matikan lewat
        // API_DOCS_PUBLIC=false bila instance dipakai dengan data nyata.
        Gate::define('viewApiDocs', static fn (?User $user = null): bool => (bool) config('app.docs_public', true));
    }
}
