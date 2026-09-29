<?php

namespace Multek\LaravelGeoaddress\Exceptions;

use RuntimeException;

/**
 * Thrown when a geocoding provider cannot be used because a required
 * dependency is missing or it is not configured correctly.
 */
class GeocoderNotAvailableException extends RuntimeException
{
    public static function missingPackage(string $provider, string $package): self
    {
        return new self(
            "The [{$provider}] geocoding provider requires the {$package} package. "
            ."Install it with `composer require {$package}` or choose another provider via GEOADDRESS_PROVIDER."
        );
    }
}
