<?php

namespace Multek\LaravelGeoaddress\Exceptions;

use RuntimeException;

/**
 * Thrown when a provider answered but found nothing for the address.
 *
 * Carries the provider status and the query that was sent, so the real
 * cause ends up in `addresses.geocoding_error` instead of a generic
 * "unable to geocode".
 */
class GeocodingFailedException extends RuntimeException
{
    public static function noResults(string $provider, string $query, string $status = 'ZERO_RESULTS'): self
    {
        return new self("The [{$provider}] geocoding provider returned {$status} for \"{$query}\"");
    }
}
