<?php

namespace Multek\LaravelGeoaddress\Console\Commands;

use Illuminate\Console\Command;
use Multek\LaravelGeoaddress\Models\Address;
use Multek\LaravelGeoaddress\Services\GeocoderFactory;

/**
 * Fill the postal code of addresses that were geocoded without one.
 *
 * The GeocodeAddress job only fills a missing postal code on the geocode it
 * runs; addresses geocoded before `fill_missing_postal_code` was enabled are
 * never geocoded again. This re-asks the provider for those addresses and
 * stores only the postal code — coordinates are left untouched.
 */
class BackfillPostalCodesCommand extends Command
{
    protected $signature = 'geoaddress:backfill-postal-codes
                            {--provider= : Geocoding provider to ask (defaults to geoaddress.provider)}
                            {--limit= : Maximum number of addresses to process}
                            {--sleep=0 : Milliseconds to wait between provider requests}';

    protected $description = 'Fill missing postal codes of geocoded addresses from the geocoding provider';

    public function handle(GeocoderFactory $factory): int
    {
        $geocoder = $factory->make($this->option('provider') ?: config('geoaddress.provider', 'google'));
        $sleep = (int) $this->option('sleep');

        $addresses = Address::query()
            ->geocodingEnabled()
            ->geocoded()
            ->where(fn ($query) => $query->whereNull('postal_code')->orWhere('postal_code', ''))
            ->orderBy('id')
            ->when($this->option('limit'), fn ($query, $limit) => $query->limit((int) $limit))
            ->get();

        $filled = 0;

        foreach ($addresses as $address) {
            $postalCode = $geocoder->geocode($address)['postal_code'] ?? null;

            if ($postalCode) {
                $address->updateQuietly(['postal_code' => $postalCode]);
                $filled++;
                $this->line("#{$address->id} {$postalCode}");
            } else {
                $this->line("#{$address->id} no precise postal code");
            }

            if ($sleep > 0) {
                usleep($sleep * 1000);
            }
        }

        $this->info("Filled {$filled} of {$addresses->count()} addresses.");

        return self::SUCCESS;
    }
}
