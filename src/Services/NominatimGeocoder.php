<?php

namespace Multek\LaravelGeoaddress\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Multek\LaravelGeoaddress\Contracts\GeocoderInterface;
use Multek\LaravelGeoaddress\Models\Address;

/**
 * Nominatim Geocoder Service
 *
 * Free geocoding using OpenStreetMap's Nominatim API.
 * Rate limited to 1 request/second. For production use, consider hosting your own instance.
 *
 * @see https://nominatim.org/release-docs/latest/api/Search/
 */
class NominatimGeocoder implements GeocoderInterface
{
    protected string $baseUrl;

    protected string $userAgent;

    public function __construct()
    {
        $this->baseUrl = config('geoaddress.nominatim.url', 'https://nominatim.openstreetmap.org');
        $this->userAgent = config('geoaddress.nominatim.user_agent', config('app.name', 'Laravel'));
    }

    /**
     * Geocode an address and return coordinates.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function geocode(Address $address): ?array
    {
        try {
            $response = Http::timeout(config('geoaddress.timeout', 10))
                ->withHeaders([
                    'User-Agent' => $this->userAgent,
                ])
                ->get($this->baseUrl.'/search', [
                    'q' => $address->formatted_address,
                    'format' => 'json',
                    'limit' => 1,
                ]);

            if ($response->failed()) {
                Log::warning('Nominatim geocoding request failed', [
                    'address_id' => $address->id,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $data = $response->json();

            if (empty($data)) {
                Log::warning('Nominatim geocoding returned no results', [
                    'address_id' => $address->id,
                    'address' => $address->formatted_address,
                ]);

                return null;
            }

            $result = $data[0];

            return [
                'lat' => (float) $result['lat'],
                'lng' => (float) $result['lon'],
            ];
        } catch (\Exception $e) {
            Log::error('Nominatim geocoding failed', [
                'address_id' => $address->id,
                'address' => $address->formatted_address,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
