<?php

namespace Multek\LaravelGeoaddress\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Multek\LaravelGeoaddress\Contracts\GeocoderInterface;
use Multek\LaravelGeoaddress\Exceptions\GeocoderNotAvailableException;
use Multek\LaravelGeoaddress\Exceptions\GeocodingFailedException;
use Multek\LaravelGeoaddress\Models\Address;
use Spatie\Geocoder\Exceptions\CouldNotGeocode;
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
     * Besides lat/lng (and the postal code of a precise match) the result
     * carries what Google answered, so the caller can persist it.
     *
     * Errors are not swallowed: a Google error status (REQUEST_DENIED,
     * OVER_QUERY_LIMIT, ...) or a connection failure propagates as spatie's
     * CouldNotGeocode, and no result throws GeocodingFailedException. spatie
     * reports "no results" as a `result_not_found` accuracy with zero
     * coordinates; Google's status for that is ZERO_RESULTS (every other
     * status carries an error_message and is thrown by spatie before).
     *
     * @return array{lat: float, lng: float, postal_code?: string, provider: string, query: string, accuracy: string, partial_match: bool, place_id?: string, formatted_address?: string, postal_code_candidate?: string}|null
     *
     * @throws CouldNotGeocode
     * @throws GeocodingFailedException
     */
    public function geocode(Address $address): ?array
    {
        $apiKey = $this->config('key');

        if (empty($apiKey)) {
            Log::error('Google Maps API key not configured (set GOOGLE_MAPS_API_KEY)');

            return null;
        }

        $query = $address->formatted_address;
        $result = $this->makeGeocoder($apiKey)->getCoordinatesForAddress($query);

        if ($result['accuracy'] === Geocoder::RESULT_NOT_FOUND || ((float) $result['lat'] === 0.0 && (float) $result['lng'] === 0.0)) {
            throw GeocodingFailedException::noResults('google', $query);
        }

        return array_filter([
            'lat' => $result['lat'],
            'lng' => $result['lng'],
            'postal_code' => $this->precisePostalCode($result, $address),
            'provider' => 'google',
            'query' => $query,
            'accuracy' => $result['accuracy'],
            'partial_match' => (bool) ($result['partial_match'] ?? false),
            'place_id' => $result['place_id'] ?? null,
            'formatted_address' => $result['formatted_address'] ?? null,
            'postal_code_candidate' => $this->postalCodeComponent($result),
        ], fn ($value) => $value !== null);
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

        $postalCode = $this->postalCodeComponent($result, ['postal_code']);

        return $postalCode === null ? null : $this->normalizePostalCode($postalCode, $address);
    }

    /**
     * The raw postal code in the result, precise or not — kept for diagnosis
     * even when precisePostalCode() rejects it.
     */
    protected function postalCodeComponent(array $result, array $types = ['postal_code', 'postal_code_prefix']): ?string
    {
        foreach ($result['address_components'] ?? [] as $component) {
            if (array_intersect($types, (array) ($component->types ?? []))) {
                return (string) $component->long_name;
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
