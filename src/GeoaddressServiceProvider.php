<?php

namespace Multek\LaravelGeoaddress;

use Illuminate\Support\ServiceProvider;
use Multek\LaravelGeoaddress\Contracts\GeocoderInterface;
use Multek\LaravelGeoaddress\Models\Address;
use Multek\LaravelGeoaddress\Observers\AddressObserver;
use Multek\LaravelGeoaddress\Services\GeocoderFactory;

class GeoaddressServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Merge config
        $this->mergeConfigFrom(__DIR__.'/../config/geoaddress.php', 'geoaddress');

        // Register geocoder factory
        $this->app->singleton(GeocoderFactory::class);

        // Bind geocoder interface to configured provider
        $this->app->bind(GeocoderInterface::class, function ($app) {
            return $app->make(GeocoderFactory::class)->make();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__.'/../config/geoaddress.php' => config_path('geoaddress.php'),
        ], 'geoaddress-config');

        // Publish migrations
        $this->publishes([
            __DIR__.'/../database/migrations/' => database_path('migrations'),
        ], 'geoaddress-migrations');

        // Load migrations
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Register observer
        Address::observe(AddressObserver::class);
    }
}
