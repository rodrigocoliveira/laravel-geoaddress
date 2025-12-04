<?php

namespace Multek\LaravelGeoaddress\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Multek\LaravelGeoaddress\GeoaddressServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Multek\\LaravelGeoaddress\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app): array
    {
        return [
            GeoaddressServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');

        $migration = include __DIR__.'/../database/migrations/2025_01_01_000001_create_addresses_table.php';
        $migration->up();

        // Create a test model table
        $app['db']->connection()->getSchemaBuilder()->create('test_models', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }
}
