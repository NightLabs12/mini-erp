<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\Journal;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Core\Models\AuditLog;
use App\Domain\Core\Models\User;
use App\Domain\Core\Services\SettingService;
use App\Domain\Master\Models\Customer;
use App\Domain\Master\Models\Item;
use App\Domain\Master\Models\ItemCategory;
use App\Domain\Master\Models\ItemUnitConversion;
use App\Domain\Master\Models\SellingPrice;
use App\Domain\Master\Models\Supplier;
use App\Domain\Master\Models\Unit;
use App\Domain\Master\Models\Warehouse;
use App\Policies\AccountingPeriodPolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\ItemCategoryPolicy;
use App\Policies\ItemPolicy;
use App\Policies\JournalPolicy;
use App\Policies\SellingPricePolicy;
use App\Policies\SupplierPolicy;
use App\Policies\UnitPolicy;
use App\Policies\UserPolicy;
use App\Policies\WarehousePolicy;
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
            'unit' => Unit::class,
            'item_category' => ItemCategory::class,
            'item' => Item::class,
            'item_unit_conversion' => ItemUnitConversion::class,
            'warehouse' => Warehouse::class,
            'supplier' => Supplier::class,
            'customer' => Customer::class,
            'selling_price' => SellingPrice::class,
            'account' => Account::class,
            'accounting_period' => AccountingPeriod::class,
            'journal' => Journal::class,
            'journal_line' => JournalLine::class,
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
        Gate::policy(Unit::class, UnitPolicy::class);
        Gate::policy(ItemCategory::class, ItemCategoryPolicy::class);
        Gate::policy(Item::class, ItemPolicy::class);
        Gate::policy(Warehouse::class, WarehousePolicy::class);
        Gate::policy(Supplier::class, SupplierPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(SellingPrice::class, SellingPricePolicy::class);
        Gate::policy(Journal::class, JournalPolicy::class);
        Gate::policy(AccountingPeriod::class, AccountingPeriodPolicy::class);

        // Dokumentasi API sengaja terbuka untuk demo portofolio. Matikan lewat
        // API_DOCS_PUBLIC=false bila instance dipakai dengan data nyata.
        Gate::define('viewApiDocs', static fn (?User $user = null): bool => (bool) config('app.docs_public', true));
    }
}
