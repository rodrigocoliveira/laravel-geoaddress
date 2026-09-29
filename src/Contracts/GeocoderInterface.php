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
     * Providers may also return the postal code of a precise match; it is
     * only stored when `geoaddress.fill_missing_postal_code` is enabled.
     *
     * @return array{lat: float, lng: float, postal_code?: string}|null
     */
    public function geocode(Address $address): ?array;
}
