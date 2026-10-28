<?php

namespace Multek\LaravelGeoaddress\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use MatanYadaev\EloquentSpatial\Objects\Point;
use Multek\LaravelGeoaddress\Events\AddressGeocoded;
use Multek\LaravelGeoaddress\Models\Address;
use Multek\LaravelGeoaddress\Services\GeocoderFactory;

/**
 * Geocode Address Job
 *
 * Asynchronously geocodes an address using the configured geocoding provider.
 * If the primary provider fails, automatically tries the fallback provider.
 *
 * Every attempt's outcome is persisted: a successful geocode writes what the
 * provider answered into `metadata['geocoding']`; the final failure writes
 * the real provider error into `geocoding_error` and the same metadata
 * block. Intermediate failures leave the address untouched so the queue
 * actually retries (see handle()).
 *
 * This job is only dispatched when:
 * - geocoding_enabled = true
 * - No coordinates were provided in the request
 * - Address doesn't already have coordinates
 *
 * Usage:
 * GeocodeAddress::dispatch($address->id);
 */
class GeocodeAddress implements ShouldQueue
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
     * Exceptions thrown by providers during this attempt, keyed by provider name.
     *
     * @var array<string, \Throwable>
     */
    protected array $providerErrors = [];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $addressId
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(GeocoderFactory $factory): void
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

        $this->providerErrors = [];

        // Try primary provider
        $primaryProvider = config('geoaddress.provider', 'google');
        $fallbackProvider = config('geoaddress.fallback_provider');

        $provider = $primaryProvider;
        $coordinates = $this->tryGeocode($factory, $primaryProvider, $address);

        // If primary failed and fallback is configured, try fallback
        if (! $coordinates && $fallbackProvider && $fallbackProvider !== $primaryProvider) {
            Log::info("Primary geocoder ({$primaryProvider}) failed for address {$this->addressId}, trying fallback ({$fallbackProvider})");
            $provider = $fallbackProvider;
            $coordinates = $this->tryGeocode($factory, $fallbackProvider, $address);
        }

        if ($coordinates) {
            // Geocoding successful - update without triggering observer
            $address->updateQuietly($this->geocodedAttributes($address, $coordinates, $provider));

            Log::info("Successfully geocoded address {$this->addressId}: {$address->formatted_address}");

            // Dispatch event
            AddressGeocoded::dispatch($address->fresh());

            return;
        }

        // All providers failed
        $reason = $this->describeProviderErrors() ?? 'Unable to geocode address with any provider';

        $exception = new \Exception(
            "Geocoding failed for address {$this->addressId}: {$reason}",
            0,
            $this->providerErrors ? end($this->providerErrors) : null
        );

        // Not the last attempt: rethrow without touching the address so the
        // queue retries after $backoff. Writing geocoding_failed_at here would
        // make the next attempt hit the "recently failed" guard above and
        // return normally — the job would never retry, never reach failed()
        // and never land in failed_jobs.
        if ($this->attempts() < $this->tries) {
            Log::warning("Geocoding attempt {$this->attempts()} of {$this->tries} failed for address {$this->addressId}, will retry: {$reason}");

            throw $exception;
        }

        $address->updateQuietly([
            'geocoding_failed_at' => now(),
            'geocoding_error' => $reason,
            'metadata' => $address->metadataWithGeocoding($provider, null, false, $reason),
        ]);

        Log::warning("Failed to geocode address {$this->addressId} with all providers: {$address->formatted_address}");

        throw $exception;
    }

    /**
     * Attributes to store after a successful geocode.
     *
     * The provider's postal code is only written when the address has none
     * and `geoaddress.fill_missing_postal_code` is on — a postal code the
     * user typed is never overwritten.
     *
     * The provider's answer is kept in `metadata['geocoding']`.
     *
     * @param  array{lat: float, lng: float, postal_code?: string}  $result  see GeocoderInterface for the diagnostic keys
     */
    protected function geocodedAttributes(Address $address, array $result, string $provider): array
    {
        $attributes = [
            'coordinates' => new Point($result['lat'], $result['lng'], 4326),
            'geocoded_at' => now(),
            'geocoding_failed_at' => null,
            'geocoding_error' => null,
        ];

        if (config('geoaddress.fill_missing_postal_code') && blank($address->postal_code) && ! empty($result['postal_code'])) {
            $attributes['postal_code'] = $result['postal_code'];
        }

        $attributes['metadata'] = $address->metadataWithGeocoding($provider, $result, isset($attributes['postal_code']));

        return $attributes;
    }

    /**
     * Try to geocode an address using a specific provider.
     */
    protected function tryGeocode(GeocoderFactory $factory, string $provider, Address $address): ?array
    {
        try {
            $geocoder = $factory->make($provider);

            return $geocoder->geocode($address);
        } catch (\Throwable $e) {
            $this->providerErrors[$provider] = $e;

            Log::warning("Geocoder {$provider} threw exception for address {$this->addressId}: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Summarize exceptions thrown by providers, e.g. "[google] message; [nominatim] message".
     */
    protected function describeProviderErrors(): ?string
    {
        if (! $this->providerErrors) {
            return null;
        }

        return collect($this->providerErrors)
            ->map(fn (\Throwable $e, string $provider) => "[{$provider}] {$e->getMessage()}")
            ->implode('; ');
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Geocoding job failed permanently for address {$this->addressId}: ".$exception->getMessage());
    }
}
