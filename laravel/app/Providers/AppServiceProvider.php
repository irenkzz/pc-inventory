<?php

namespace App\Providers;

use App\Support\InventoryFormat;
use App\Support\InventoryTime;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
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
        Paginator::defaultView('pagination.admin');
        Paginator::defaultSimpleView('pagination.admin');

        Blade::directive('inventoryTime', function (string $expression): string {
            return "<?php echo e(\\" . InventoryTime::class . "::format({$expression})); ?>";
        });

        Blade::directive('inventoryNumber', function (string $expression): string {
            return "<?php echo e(\\" . InventoryFormat::class . "::number({$expression})); ?>";
        });

        Blade::directive('inventoryDecimal', function (string $expression): string {
            return "<?php echo e(\\" . InventoryFormat::class . "::decimal({$expression})); ?>";
        });
    }
}
