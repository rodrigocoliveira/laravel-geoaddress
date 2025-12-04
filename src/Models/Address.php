<?php

namespace Multek\LaravelGeoaddress\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use MatanYadaev\EloquentSpatial\Objects\Point;
use MatanYadaev\EloquentSpatial\Traits\HasSpatial;
use Multek\LaravelGeoaddress\Database\Factories\AddressFactory;

/**
 * Address Model
 *
 * Polymorphic address storage for any model.
 * Supports automatic queued geocoding via configurable providers.
 *
 * Geocoding behavior:
 * - geocoding_enabled = false: Coordinates are ALWAYS null (billing, PO Box, etc.)
 * - geocoding_enabled = true (default): Coordinates will be set either:
 *   - From provided lat/lng ("trust me" signal)
 *   - From geocoding API (automatic when no coords provided)
 *
 * @property int $id
 * @property string $addressable_type
 * @property int $addressable_id
 * @property string|null $type
 * @property string|null $nickname
 * @property bool $is_primary
 * @property bool $geocoding_enabled
 * @property string $street
 * @property string|null $number
 * @property string|null $complement
 * @property string|null $neighbourhood
 * @property string $city
 * @property string $state
 * @property string|null $postal_code
 * @property string $country_code
 * @property string|null $reference_point
 * @property string|null $customer_name
 * @property string|null $customer_phone
 * @property string|null $customer_country_code_phone
 * @property string|null $customer_document
 * @property string|null $notes
 * @property array|null $metadata
 * @property Point|null $coordinates
 * @property \Carbon\Carbon|null $geocoded_at
 * @property \Carbon\Carbon|null $geocoding_failed_at
 * @property string|null $geocoding_error
 */
class Address extends Model
{
    use HasFactory, HasSpatial;

    /**
     * Track if coordinates were provided in the current request.
     * Used by observer to detect "trust me" signal.
     */
    public bool $coordinatesProvidedInRequest = false;

    /**
     * The model's default values for attributes.
     */
    protected $attributes = [
        'geocoding_enabled' => true,
        'is_primary' => false,
        'country_code' => 'BR',
    ];

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'addressable_type',
        'addressable_id',
        'type',
        'nickname',
        'is_primary',
        'geocoding_enabled',
        'street',
        'number',
        'complement',
        'neighbourhood',
        'city',
        'state',
        'postal_code',
        'country_code',
        'reference_point',
        'customer_name',
        'customer_phone',
        'customer_country_code_phone',
        'customer_document',
        'notes',
        'metadata',
        'latitude',
        'longitude',
        // Geocoding result fields (set by GeocodeAddress job)
        'coordinates',
        'geocoded_at',
        'geocoding_failed_at',
        'geocoding_error',
    ];

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::saving(function (Address $address) {
            // Check if latitude and longitude were provided
            $latProvided = isset($address->attributes['latitude']);
            $lngProvided = isset($address->attributes['longitude']);

            if ($latProvided && $lngProvided) {
                // Mark that coordinates were provided (for observer)
                $address->coordinatesProvidedInRequest = true;

                $lat = $address->attributes['latitude'];
                $lng = $address->attributes['longitude'];

                // Remove lat/lng from attributes as they're not actual columns
                unset($address->attributes['latitude']);
                unset($address->attributes['longitude']);

                // If geocoding is disabled, don't store coordinates
                if (! $address->geocoding_enabled) {
                    return;
                }

                // Create Point from lat/lng using the proper setter (respects cast)
                $address->coordinates = new Point($lat, $lng, 4326);
            }

            // If geocoding is disabled, ensure coordinates are always null
            if (! $address->geocoding_enabled) {
                $address->coordinates = null;
                $address->geocoded_at = null;
                $address->geocoding_failed_at = null;
                $address->geocoding_error = null;
            }
        });
    }

    /**
     * The attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'geocoding_enabled' => 'boolean',
            'coordinates' => Point::class,
            'geocoded_at' => 'datetime',
            'geocoding_failed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): AddressFactory
    {
        return AddressFactory::new();
    }

    /**
     * Get the owning addressable model.
     */
    public function addressable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Address fields that affect geocoding.
     * When these change, coordinates should be re-calculated.
     */
    public const ADDRESS_FIELDS = [
        'street',
        'number',
        'complement',
        'neighbourhood',
        'city',
        'state',
        'postal_code',
        'country_code',
    ];

    /**
     * Check if address fields have changed (for geocoding trigger).
     */
    public function addressFieldsChanged(): bool
    {
        foreach (self::ADDRESS_FIELDS as $field) {
            if ($this->isDirty($field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if address needs geocoding.
     * Only true if: geocoding enabled + no coordinates + not recently failed
     */
    public function needsGeocoding(): bool
    {
        if (! $this->geocoding_enabled) {
            return false;
        }

        return $this->coordinates === null && $this->geocoding_failed_at === null;
    }

    /**
     * Get formatted address as string (Brazilian format).
     */
    public function getFormattedAddressAttribute(): string
    {
        $parts = [];

        // Street and number
        if ($this->street) {
            $streetPart = $this->street;
            if ($this->number) {
                $streetPart .= ', '.$this->number;
            }
            $parts[] = $streetPart;
        }

        // Complement
        if ($this->complement) {
            $parts[] = $this->complement;
        }

        // Neighbourhood
        if ($this->neighbourhood) {
            $parts[] = $this->neighbourhood;
        }

        // City and state
        if ($this->city) {
            $cityPart = $this->city;
            if ($this->state) {
                $cityPart .= ' - '.$this->state;
            }
            $parts[] = $cityPart;
        }

        // Postal code
        if ($this->postal_code) {
            $parts[] = 'CEP '.$this->postal_code;
        }

        // Country
        if ($this->country_code) {
            $parts[] = $this->country_code;
        }

        return implode(', ', array_filter($parts));
    }

    /**
     * Get latitude from Point.
     */
    public function getLatitudeAttribute(): ?float
    {
        return $this->coordinates?->latitude;
    }

    /**
     * Get longitude from Point.
     */
    public function getLongitudeAttribute(): ?float
    {
        return $this->coordinates?->longitude;
    }

    /**
     * Scope to get only primary addresses.
     */
    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }

    /**
     * Scope to get only geocoded addresses.
     */
    public function scopeGeocoded($query)
    {
        return $query->whereNotNull('geocoded_at');
    }

    /**
     * Scope to get addresses with failed geocoding.
     */
    public function scopeFailed($query)
    {
        return $query->whereNotNull('geocoding_failed_at');
    }

    /**
     * Scope to get addresses that need geocoding.
     */
    public function scopeNeedsGeocoding($query)
    {
        return $query->where('geocoding_enabled', true)
            ->whereNull('coordinates')
            ->whereNull('geocoding_failed_at');
    }

    /**
     * Scope to get only geocoding-enabled addresses.
     */
    public function scopeGeocodingEnabled($query)
    {
        return $query->where('geocoding_enabled', true);
    }
}
