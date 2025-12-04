<?php

declare(strict_types=1);

namespace Multek\LaravelGeoaddress\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InstallCommand extends Command
{
    protected $signature = 'geoaddress:install
                            {--force : Overwrite existing files}';

    protected $description = 'Install the Laravel Geoaddress package';

    public function handle(): int
    {
        $this->info('Installing Laravel Geoaddress package...');
        $this->newLine();

        // Check PostGIS for PostgreSQL
        if ($this->isPostgres()) {
            $this->checkPostGIS();
        }

        // Publish config
        $this->publishConfig();

        // Publish migrations
        $this->publishMigrations();

        // Run migrations prompt
        $this->runMigrations();

        $this->newLine();
        $this->info('Laravel Geoaddress package installed successfully!');
        $this->newLine();
        $this->displayNextSteps();

        return self::SUCCESS;
    }

    protected function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    protected function checkPostGIS(): void
    {
        $hasPostGIS = false;

        try {
            $result = DB::select("SELECT 1 FROM pg_extension WHERE extname = 'postgis'");
            $hasPostGIS = ! empty($result);
        } catch (\Exception $e) {
            // Ignore errors
        }

        if (! $hasPostGIS) {
            $this->components->warn('PostGIS extension is not enabled.');
            $this->newLine();

            if ($this->confirm('Would you like to enable PostGIS now?', true)) {
                $this->components->task('Enabling PostGIS extension', function () {
                    try {
                        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');

                        return true;
                    } catch (\Exception $e) {
                        $this->newLine();
                        $this->error('Failed to enable PostGIS: '.$e->getMessage());
                        $this->line('You may need to run this manually with superuser privileges:');
                        $this->line('  <comment>CREATE EXTENSION IF NOT EXISTS postgis;</comment>');

                        return false;
                    }
                });
            } else {
                $this->components->info('Skipping PostGIS. You can enable it later with:');
                $this->line('  <comment>CREATE EXTENSION IF NOT EXISTS postgis;</comment>');
                $this->newLine();
            }
        } else {
            $this->components->info('PostGIS extension is already enabled.');
        }
    }

    protected function publishConfig(): void
    {
        $this->components->task('Publishing configuration', function () {
            $params = [
                '--provider' => 'Multek\LaravelGeoaddress\GeoaddressServiceProvider',
                '--tag' => 'geoaddress-config',
            ];

            if ($this->option('force')) {
                $params['--force'] = true;
            }

            $this->callSilently('vendor:publish', $params);
        });
    }

    protected function publishMigrations(): void
    {
        $this->components->task('Publishing migrations', function () {
            $params = [
                '--provider' => 'Multek\LaravelGeoaddress\GeoaddressServiceProvider',
                '--tag' => 'geoaddress-migrations',
            ];

            if ($this->option('force')) {
                $params['--force'] = true;
            }

            $this->callSilently('vendor:publish', $params);
        });
    }

    protected function runMigrations(): void
    {
        if ($this->confirm('Would you like to run the migrations now?', true)) {
            $this->components->task('Running migrations', function () {
                $this->callSilently('migrate');
            });
        }
    }

    protected function displayNextSteps(): void
    {
        $this->components->info('Next steps:');
        $this->newLine();

        $this->line('  1. Add the <comment>Addressable</comment> trait to any model:');
        $this->newLine();
        $this->line('     <comment>use Multek\LaravelGeoaddress\Traits\Addressable;</comment>');
        $this->newLine();
        $this->line('     <comment>class Customer extends Model</comment>');
        $this->line('     <comment>{</comment>');
        $this->line('         <comment>use Addressable;</comment>');
        $this->line('     <comment>}</comment>');
        $this->newLine();

        $this->line('  2. Configure your geocoding provider in <comment>.env</comment>:');
        $this->newLine();
        $this->line('     <comment>GEOADDRESS_PROVIDER=google</comment>');
        $this->line('     <comment>GOOGLE_MAPS_API_KEY=your_api_key</comment>');
        $this->newLine();

        $this->line('  3. Create addresses for your models:');
        $this->newLine();
        $this->line('     <comment>$customer->addAddress([</comment>');
        $this->line("         <comment>'type' => 'delivery',</comment>");
        $this->line("         <comment>'street' => 'Avenida Paulista',</comment>");
        $this->line("         <comment>'number' => '1578',</comment>");
        $this->line("         <comment>'city' => 'Sao Paulo',</comment>");
        $this->line("         <comment>'state' => 'SP',</comment>");
        $this->line("         <comment>'country_code' => 'BR',</comment>");
        $this->line('     <comment>]);</comment>');
        $this->newLine();

        $this->components->info('Documentation: https://github.com/multek/laravel-geoaddress');
    }
}
