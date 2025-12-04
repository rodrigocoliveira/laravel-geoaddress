<?php

namespace Multek\LaravelGeoaddress\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use MatanYadaev\EloquentSpatial\Objects\Point;
use Multek\LaravelGeoaddress\Contracts\GeocoderInterface;
use Multek\LaravelGeoaddress\Events\AddressGeocoded;
use Multek\LaravelGeoaddress\Models\Address;

/**
 * Geocode Address Job
 *
 * Asynchronously geocodes an address using the configured geocoding provider.
 * Implements ShouldBeUnique to prevent duplicate jobs for the same address.
 *
 * This job is only dispatched when:
 * - geocoding_enabled = true
 * - No coordinates were provided in the request
 * - Address doesn't already have coordinates
 *
 * Usage:
 * GeocodeAddress::dispatch($address->id);
 */
class GeocodeAddress implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 60;

    /**
     * Delete the job if its models no longer exist.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $addressId
    ) {
        $this->onQueue(config('geoaddress.queue.name', 'default'));
        $this->onConnection(config('geoaddress.queue.connection', config('queue.default')));
    }

    /**
     * Get the unique ID for the job.
     *
     * Prevents multiple jobs for the same address from being queued.
     */
    public function uniqueId(): string
    {
        return 'geocode-address-'.$this->addressId;
    }

    /**
     * How long the unique lock should be held (seconds).
     */
    public int $uniqueFor = 3600; // 1 hour

    /**
     * Execute the job.
     */
    public function handle(GeocoderInterface $geocoder): void
    {
        // Fetch fresh address from database
        $address = Address::find($this->addressId);

        // Skip if address deleted
        if (! $address) {
            Log::info("Geocoding skipped for address {$this->addressId}: not found");

            return;
        }

        // Skip if geocoding is disabled for this address
        if (! $address->geocoding_enabled) {
            Log::info("Geocoding skipped for address {$this->addressId}: geocoding_enabled is false");

            return;
        }

        // Skip if already has coordinates (someone provided them)
        if ($address->coordinates) {
            Log::info("Geocoding skipped for address {$this->addressId}: already has coordinates");

            return;
        }

        // Skip if previous geocoding failed recently (within last 24 hours)
        if ($address->geocoding_failed_at && $address->geocoding_failed_at->gt(now()->subDay())) {
            Log::info("Geocoding skipped for address {$this->addressId}: recently failed");

            return;
        }

        // Perform geocoding
        $coordinates = $geocoder->geocode($address);

        if ($coordinates) {
            // Geocoding successful - update without triggering observer
            $address->updateQuietly([
                'coordinates' => new Point($coordinates['lat'], $coordinates['lng'], 4326),
                'geocoded_at' => now(),
                'geocoding_failed_at' => null,
                'geocoding_error' => null,
            ]);

            Log::info("Successfully geocoded address {$this->addressId}: {$address->formatted_address}");

            // Dispatch event
            AddressGeocoded::dispatch($address->fresh());
        } else {
            // Geocoding failed
            $address->updateQuietly([
                'geocoding_failed_at' => now(),
                'geocoding_error' => 'Unable to geocode address',
            ]);

            Log::warning("Failed to geocode address {$this->addressId}: {$address->formatted_address}");

            // Throw exception to trigger retry
            throw new \Exception("Geocoding failed for address {$this->addressId}");
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Geocoding job failed permanently for address {$this->addressId}: ".$exception->getMessage());
    }
}
