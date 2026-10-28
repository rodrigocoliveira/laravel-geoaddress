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
     * The remaining keys describe what the provider answered and are
     * persisted as-is in `addresses.metadata['geocoding']` for diagnosis
     * (`postal_code_candidate` is the raw postal code of the result, even
     * when the precision filter rejected it). They are all optional.
     *
     * Throw when the provider answered with an error or found nothing — the
     * exception message is what ends up in `addresses.geocoding_error`.
     *
     * @return array{lat: float, lng: float, postal_code?: string, provider?: string, query?: string, accuracy?: string, partial_match?: bool, place_id?: string, formatted_address?: string, postal_code_candidate?: string}|null
     */
    public function geocode(Address $address): ?array;
}
