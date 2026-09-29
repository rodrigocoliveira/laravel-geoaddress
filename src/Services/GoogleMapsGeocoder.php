<?php

namespace Multek\LaravelGeoaddress\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Multek\LaravelGeoaddress\Contracts\GeocoderInterface;
use Multek\LaravelGeoaddress\Exceptions\GeocoderNotAvailableException;
use Multek\LaravelGeoaddress\Models\Address;
use Spatie\Geocoder\Geocoder;

/**
 * Google Maps Geocoder Service
 *
 * Converts addresses to geographic coordinates using Google Maps Geocoding API.
 * Requires the spatie/geocoder package.
 */
class GoogleMapsGeocoder implements GeocoderInterface
{
    public function __construct()
    {
        if (! $this->dependencyInstalled()) {
            throw GeocoderNotAvailableException::missingPackage('google', 'spatie/geocoder');
        }
    }

    /**
     * Geocode an address and return coordinates.
     *
     * @return array{lat: float, lng: float, postal_code?: string}|null
     */
    public function geocode(Address $address): ?array
    {
        $apiKey = $this->config('key');

        if (empty($apiKey)) {
            Log::error('Google Maps API key not configured (set GOOGLE_MAPS_API_KEY)');

            return null;
        }

        try {
            $result = $this->makeGeocoder($apiKey)->getCoordinatesForAddress($address->formatted_address);

            if ($result['lat'] !== 0.0 && $result['lng'] !== 0.0) {
                return array_filter([
                    'lat' => $result['lat'],
                    'lng' => $result['lng'],
                    'postal_code' => $this->precisePostalCode($result, $address),
                ], fn ($value) => $value !== null);
            }

            Log::warning('Google Maps geocoding returned zero coordinates', [
                'address_id' => $address->id,
                'address' => $address->formatted_address,
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::error('Google Maps geocoding failed', [
                'address_id' => $address->id,
                'address' => $address->formatted_address,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The result's postal code, only when Google matched the street itself.
     *
     * An approximate or partial match (street not found, so Google answers
     * with the city or neighbourhood) carries a generic or neighbouring
     * postal code, and Google reports an incomplete one as
     * `postal_code_prefix` — neither is safe to store on the address.
     */
    protected function precisePostalCode(array $result, Address $address): ?string
    {
        if (! in_array($result['accuracy'] ?? null, ['ROOFTOP', 'RANGE_INTERPOLATED'], true) || ! empty($result['partial_match'])) {
            return null;
        }

        foreach ($result['address_components'] ?? [] as $component) {
            if (in_array('postal_code', (array) ($component->types ?? []), true)) {
                return $this->normalizePostalCode((string) $component->long_name, $address);
            }
        }

        return null;
    }

    /**
     * Brazilian CEPs must have all 8 digits and are stored as 00000-000.
     */
    protected function normalizePostalCode(string $postalCode, Address $address): ?string
    {
        if (strtoupper($address->country_code ?? 'BR') !== 'BR') {
            return trim($postalCode) ?: null;
        }

        $digits = preg_replace('/\D/', '', $postalCode);

        return strlen($digits) === 8 ? substr($digits, 0, 5).'-'.substr($digits, 5) : null;
    }

    /**
     * Build a spatie Geocoder configured from geoaddress.google.*.
     */
    protected function makeGeocoder(string $apiKey): Geocoder
    {
        $geocoder = (new Geocoder($this->httpClient()))->setApiKey($apiKey);

        if ($language = $this->config('language')) {
            $geocoder->setLanguage($language);
        }

        if ($region = $this->config('region')) {
            $geocoder->setRegion($region);
        }

        if ($country = $this->config('country')) {
            $geocoder->setCountry($country);
        }

        return $geocoder;
    }

    protected function httpClient(): Client
    {
        return new Client(['timeout' => config('geoaddress.timeout', 10)]);
    }

    /**
     * Read a geoaddress.google.* value, falling back to spatie's own
     * config/geocoder.php for apps that configured it there.
     */
    protected function config(string $key): ?string
    {
        return config("geoaddress.google.{$key}") ?: config("geocoder.{$key}") ?: null;
    }

    protected function dependencyInstalled(): bool
    {
        return class_exists(Geocoder::class);
    }
}
