<?php

namespace Multek\LaravelGeoaddress\Services;

use InvalidArgumentException;
use Multek\LaravelGeoaddress\Contracts\GeocoderInterface;

/**
 * Geocoder Factory
 *
 * Creates geocoder instances based on configuration.
 */
class GeocoderFactory
{
    /**
     * Available geocoder providers.
     */
    protected array $providers = [
        'google' => GoogleMapsGeocoder::class,
        'nominatim' => NominatimGeocoder::class,
        'mapbox' => MapboxGeocoder::class,
    ];

    /**
     * Create a geocoder instance for the configured provider.
     */
    public function make(?string $provider = null): GeocoderInterface
    {
        $provider = $provider ?? config('geoaddress.provider', 'google');

        if (! isset($this->providers[$provider])) {
            throw new InvalidArgumentException("Unsupported geocoding provider: {$provider}");
        }

        return app($this->providers[$provider]);
    }

    /**
     * Register a custom geocoder provider.
     */
    public function extend(string $name, string $class): void
    {
        $this->providers[$name] = $class;
    }

    /**
     * Get all available providers.
     */
    public function getProviders(): array
    {
        return array_keys($this->providers);
    }
}
