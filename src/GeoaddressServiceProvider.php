<?php

namespace Multek\LaravelGeoaddress;

use Illuminate\Support\ServiceProvider;
use Multek\LaravelGeoaddress\Console\Commands\InstallCommand;
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
        $this->mergeConfigFrom(__DIR__.'/../config/geoaddress.php', 'geoaddress');

        $this->app->singleton(GeocoderFactory::class);

        $this->app->bind(GeocoderInterface::class, function ($app) {
            return $app->make(GeocoderFactory::class)->make();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->publishConfig();
        $this->publishMigrations();
        $this->registerCommands();
        $this->registerObserver();
    }

    protected function publishConfig(): void
    {
        $this->publishes([
            __DIR__.'/../config/geoaddress.php' => config_path('geoaddress.php'),
        ], 'geoaddress-config');
    }

    protected function publishMigrations(): void
    {
        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'geoaddress-migrations');
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
            ]);
        }
    }

    protected function registerObserver(): void
    {
        Address::observe(AddressObserver::class);
    }
}
