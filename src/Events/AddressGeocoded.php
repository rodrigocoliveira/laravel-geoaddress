<?php

namespace Multek\LaravelGeoaddress\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Multek\LaravelGeoaddress\Models\Address;

/**
 * Address Geocoded Event
 *
 * Fired when an address has been successfully geocoded (via API or manual coordinates).
 * Can be used to trigger additional actions or notify users.
 */
class AddressGeocoded
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Address $address
    ) {}
}
