<?php

namespace Multek\LaravelGeoaddress\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Multek\LaravelGeoaddress\Models\Address;

/**
 * Addressable Trait
 *
 * Provides polymorphic address functionality to any model.
 *
 * Usage:
 * class Customer extends Model {
 *     use Addressable;
 * }
 *
 * // Create delivery address (geocoding enabled by default)
 * $customer->addAddress([
 *     'type' => 'delivery',
 *     'street' => 'Avenida Paulista',
 *     ...
 * ]);
 *
 * // Create billing address (no geocoding)
 * $customer->addAddress([
 *     'type' => 'billing',
 *     'geocoding_enabled' => false,
 *     'street' => 'Rua Fiscal',
 *     ...
 * ]);
 */
trait Addressable
{
    /**
     * Get all addresses for the model.
     */
    public function addresses(): MorphMany
    {
        return $this->morphMany(Address::class, 'addressable');
    }

    /**
     * Get the primary address.
     */
    public function primaryAddress(): ?Address
    {
        return $this->addresses()
            ->where('is_primary', true)
            ->first();
    }

    /**
     * Add a new address.
     *
     * @param  array  $data  Address data. Include 'geocoding_enabled' => false for billing/virtual addresses.
     */
    public function addAddress(array $data): Address
    {
        return $this->addresses()->create($data);
    }

    /**
     * Set an address as primary for this model.
     */
    public function setPrimaryAddress(int $addressId): bool
    {
        $address = $this->addresses()->find($addressId);

        if (! $address) {
            return false;
        }

        // Remove primary from other addresses
        $this->addresses()
            ->where('id', '!=', $addressId)
            ->update(['is_primary' => false]);

        // Set this as primary
        $address->update(['is_primary' => true]);

        return true;
    }

    /**
     * Get formatted full address string from primary address.
     */
    public function getFullAddressAttribute(): ?string
    {
        return $this->primaryAddress()?->formatted_address;
    }

    /**
     * Get all geocoding-enabled addresses.
     */
    public function geocodableAddresses(): MorphMany
    {
        return $this->addresses()->where('geocoding_enabled', true);
    }
}
