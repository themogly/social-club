<?php

namespace App\Providers;

use App\Support\ActiveScope;
use App\Support\Help;
use Filament\Schemas\Components\Form;
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
        // A click on the primary button must ALWAYS produce a visible answer (prompt 168).
        //
        // Filament renders `->required()` fields with the native HTML `required` attribute, and the
        // primary button is a plain `type="submit"`. On an empty create form the BROWSER refused the
        // submit before the app was ever asked: 0 Livewire requests, 0 error messages, nothing turned
        // red, nothing scrolled — the screen simply did not change. Measured on Discounts, where the
        // secondary "Crear y crear otro" button (wire:click, so it bypasses native submission) showed
        // three validation errors on the *same* empty form. Same form, two buttons, opposite outcomes.
        //
        // So: stop relying on native constraint validation, panel-wide rather than on one form. It is
        // not merely unhelpful, it is STRUCTURALLY incapable of covering this app's fields — the
        // browser can report on a text input, but not on a Filament Select left on its placeholder
        // (the reported case), a file upload, a repeater or a rich editor. Filament's own server-side
        // validation already covers every field, renders the message next to it and scrolls to the
        // first one; `novalidate` is what lets it run.
        Form::configureUsing(fn (Form $form): Form => $form->extraAttributes(['novalidate' => 'novalidate'], merge: true));

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
