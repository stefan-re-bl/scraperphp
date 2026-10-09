<?php

namespace App\Providers;

use App\Services\PreferencesService;
use Illuminate\Foundation\DevCommands;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        DevCommands::artisan('queue:work --queue=sales,default --tries=3 --timeout=900', 'queue');
        Paginator::defaultView('vendor.pagination.ventas');
        View::share('preferences', PreferencesService::DEFAULTS);
        Blade::directive('dateDisplay', fn ($expression) => '<?php echo e(('.$expression.')?->copy()->timezone($preferences["timezone"])->format($preferences["date_format"]) ?? "—"); ?>');

    }
}
