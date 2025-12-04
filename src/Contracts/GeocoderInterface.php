<?php

namespace Multek\LaravelGeoaddress\Contracts;

use Multek\LaravelGeoaddress\Models\Address;

/**
 * Geocoder Interface
 *
 * Contract for geocoding services that convert addresses to geographic coordinates.
 */
interface GeocoderInterface
{
    /**
     * Geocode an address and return coordinates.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function geocode(Address $address): ?array;
}
