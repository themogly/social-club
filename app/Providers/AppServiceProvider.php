<?php

namespace App\Providers;

use App\Support\ActiveScope;
use App\Support\Help;
use Filament\Tables\Table;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One active scope per request (current organisation + active location).
        $this->app->singleton(ActiveScope::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Empty states that teach (prompt 92): ONE global default gives every resource table a heading +
        // description saying what the records are and what to do — from the Help registry, keyed by model.
        // A resource's own emptyStateDescription (set later in its table()) still overrides this.
        Table::configureUsing(function (Table $table): void {
            $model = $table->getModel();
            $copy = $model !== null ? Help::emptyStateFor($model) : null;
            if ($copy !== null) {
                $table
                    ->emptyStateHeading(__($copy['heading']))
                    ->emptyStateDescription(__($copy['description']));
            }
        });
    }
}
