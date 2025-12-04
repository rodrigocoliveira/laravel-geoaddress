<?php

namespace Multek\LaravelGeoaddress\Observers;

use Multek\LaravelGeoaddress\Events\AddressGeocoded;
use Multek\LaravelGeoaddress\Jobs\GeocodeAddress;
use Multek\LaravelGeoaddress\Models\Address;

/**
 * Address Observer
 *
 * Implements the two-layer geocoding control system:
 *
 * Layer 1 - Address Type (geocoding_enabled field):
 *   - false: NEVER store coordinates (billing, PO Box, virtual addresses)
 *   - true: Address should have coordinates (delivery, home, work)
 *
 * Layer 2 - Smart Detection (per request):
 *   - Coords provided: "Trust me" - use them, skip API call
 *   - No coords + address changed: Dispatch geocoding job
 *   - No coords + address unchanged: Keep existing coords
 */
class AddressObserver
{
    /**
     * Handle the Address "created" event.
     */
    public function created(Address $address): void
    {
        // If geocoding is disabled, coordinates are already null (handled in model boot)
        if (! $address->geocoding_enabled) {
            return;
        }

        // If coordinates were provided in the request ("trust me" signal)
        if ($address->coordinatesProvidedInRequest && $address->coordinates !== null) {
            // Mark as geocoded (coords came from trusted source)
            $address->updateQuietly(['geocoded_at' => now()]);

            // Dispatch event
            AddressGeocoded::dispatch($address->fresh());

            return;
        }

        // No coordinates provided - dispatch geocoding job
        if ($address->needsGeocoding()) {
            GeocodeAddress::dispatch($address->id);
        }
    }

    /**
     * Handle the Address "updating" event.
     * Runs BEFORE the update is persisted.
     */
    public function updating(Address $address): void
    {
        // If geocoding is disabled, ensure coords stay null
        // (This is also handled in model boot, but double-check here)
        if (! $address->geocoding_enabled) {
            $address->coordinates = null;
            $address->geocoded_at = null;
            $address->geocoding_failed_at = null;
            $address->geocoding_error = null;

            return;
        }

        // Check if address fields changed AND no new coordinates provided
        if ($address->addressFieldsChanged() && ! $address->coordinatesProvidedInRequest) {
            // Address changed but no new coords - need to re-geocode
            // Clear old geocoding data
            $address->coordinates = null;
            $address->geocoded_at = null;
            $address->geocoding_failed_at = null;
            $address->geocoding_error = null;
        }
    }

    /**
     * Handle the Address "updated" event.
     */
    public function updated(Address $address): void
    {
        // If geocoding is disabled, nothing to do
        if (! $address->geocoding_enabled) {
            return;
        }

        // If coordinates were provided in this update ("trust me" signal)
        if ($address->coordinatesProvidedInRequest && $address->coordinates !== null) {
            // Update geocoded_at timestamp if not already set
            if ($address->geocoded_at === null) {
                $address->updateQuietly(['geocoded_at' => now()]);
            }

            // Dispatch event
            AddressGeocoded::dispatch($address->fresh());

            return;
        }

        // If address fields changed and now needs geocoding
        if ($address->wasChanged(Address::ADDRESS_FIELDS) && $address->needsGeocoding()) {
            GeocodeAddress::dispatch($address->id);
        }
    }

    /**
     * Handle the Address "saved" event.
     */
    public function saved(Address $address): void
    {
        // Ensure only one primary address per addressable
        if ($address->is_primary && $address->addressable) {
            $address->addressable->addresses()
                ->where('id', '!=', $address->id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
        }

        // Reset the request flag after save completes
        $address->coordinatesProvidedInRequest = false;
    }
}
