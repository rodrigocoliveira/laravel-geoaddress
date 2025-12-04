<?php

namespace Multek\LaravelGeoaddress\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Multek\LaravelGeoaddress\Contracts\GeocoderInterface;
use Multek\LaravelGeoaddress\Models\Address;

/**
 * Mapbox Geocoder Service
 *
 * Geocoding using Mapbox Geocoding API.
 *
 * @see https://docs.mapbox.com/api/search/geocoding/
 */
class MapboxGeocoder implements GeocoderInterface
{
    protected string $accessToken;

    protected string $baseUrl = 'https://api.mapbox.com/geocoding/v5/mapbox.places';

    public function __construct()
    {
        $this->accessToken = config('geoaddress.mapbox.access_token', '');
    }

    /**
     * Geocode an address and return coordinates.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function geocode(Address $address): ?array
    {
        if (empty($this->accessToken)) {
            Log::error('Mapbox access token not configured');

            return null;
        }

        try {
            $query = urlencode($address->formatted_address);

            $response = Http::timeout(config('geoaddress.timeout', 10))
                ->get("{$this->baseUrl}/{$query}.json", [
                    'access_token' => $this->accessToken,
                    'limit' => 1,
                ]);

            if ($response->failed()) {
                Log::warning('Mapbox geocoding request failed', [
                    'address_id' => $address->id,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $data = $response->json();

            if (empty($data['features'])) {
                Log::warning('Mapbox geocoding returned no results', [
                    'address_id' => $address->id,
                    'address' => $address->formatted_address,
                ]);

                return null;
            }

            $coordinates = $data['features'][0]['center'];

            return [
                'lat' => (float) $coordinates[1],
                'lng' => (float) $coordinates[0],
            ];
        } catch (\Exception $e) {
            Log::error('Mapbox geocoding failed', [
                'address_id' => $address->id,
                'address' => $address->formatted_address,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
