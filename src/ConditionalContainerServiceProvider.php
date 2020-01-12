<?php

namespace DigitalCreative\ConditionalContainer;

use Illuminate\Support\ServiceProvider;
use Laravel\Nova\Events\ServingNova;
use Laravel\Nova\Nova;

require_once 'Logipar.phar';

class ConditionalContainerServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Nova::serving(static function (ServingNova $event) {
            Nova::script('conditional-container', __DIR__ . '/../dist/js/field.js');
        });
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }
}
