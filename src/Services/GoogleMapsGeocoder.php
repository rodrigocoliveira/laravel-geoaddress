<?php

namespace Multek\LaravelGeoaddress\Services;

use Illuminate\Support\Facades\Log;
use Multek\LaravelGeoaddress\Contracts\GeocoderInterface;
use Multek\LaravelGeoaddress\Models\Address;
use Spatie\Geocoder\Facades\Geocoder;

/**
 * Google Maps Geocoder Service
 *
 * Converts addresses to geographic coordinates using Google Maps Geocoding API.
 */
class GoogleMapsGeocoder implements GeocoderInterface
{
    /**
     * Geocode an address and return coordinates.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function geocode(Address $address): ?array
    {
        try {
            $result = Geocoder::getCoordinatesForAddress($address->formatted_address);

            if ($result['lat'] !== 0.0 && $result['lng'] !== 0.0) {
                return [
                    'lat' => $result['lat'],
                    'lng' => $result['lng'],
                ];
            }

            Log::warning('Google Maps geocoding returned zero coordinates', [
                'address_id' => $address->id,
                'address' => $address->formatted_address,
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Google Maps geocoding failed', [
                'address_id' => $address->id,
                'address' => $address->formatted_address,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
