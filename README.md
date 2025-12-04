# Laravel Geoaddress

Production-ready polymorphic address system with automatic geocoding, PostGIS geographic queries, and multi-provider support.

## Features

- **Polymorphic Relationships** - Any model can have multiple addresses
- **Automatic Geocoding** - Background job processing with Google Maps API (or other providers)
- **PostGIS Integration** - Geographic queries and spatial indexing
- **Brazilian Address Format** - Street, number, complement, neighbourhood, city, state, postal code
- **Smart Geocoding Control** - `geocoding_enabled` flag for address types that don't need coordinates
- **Trust-Based Coordinate Input** - Pass lat/lng to skip geocoding API calls
- **Multiple Address Types** - Home, work, billing, shipping, delivery with custom nicknames
- **Customer Contact Information** - Store recipient/contact details with each address
- **Flexible Metadata** - JSON storage for edge cases and custom data
- **Multi-Provider Geocoding** - Support for Google Maps, Nominatim (free), Mapbox
- **Smart Re-geocoding** - Automatically re-geocodes when address fields change (unless coordinates provided)

## Requirements

- PHP 8.2+
- Laravel 11.0+
- PostgreSQL with PostGIS extension (for geographic queries)

## Installation

```bash
composer require multek/laravel-geoaddress
```

### Publish Configuration & Migrations

```bash
php artisan vendor:publish --tag=geoaddress-config
php artisan vendor:publish --tag=geoaddress-migrations
php artisan migrate
```

### Environment Variables

```env
# Geocoding Provider: google, nominatim, mapbox
GEOADDRESS_PROVIDER=google

# Google Maps (if using google provider)
GOOGLE_MAPS_API_KEY=your-api-key-here

# Nominatim (if using nominatim provider - optional custom URL)
NOMINATIM_URL=https://nominatim.openstreetmap.org
NOMINATIM_USER_AGENT=YourAppName

# Mapbox (if using mapbox provider)
MAPBOX_ACCESS_TOKEN=your-access-token

# Queue settings (optional)
GEOADDRESS_QUEUE_CONNECTION=redis
GEOADDRESS_QUEUE_NAME=geocoding
```

### Optional: PostGIS Setup (PostgreSQL)

```sql
CREATE EXTENSION IF NOT EXISTS postgis;
```

## Usage

### Make a Model Addressable

```php
use Multek\LaravelGeoaddress\Traits\Addressable;

class Customer extends Model
{
    use Addressable;
}
```

### Creating Addresses

```php
// Delivery address - Will be geocoded automatically
$customer->addAddress([
    'type' => 'delivery',
    'is_primary' => true,
    'street' => 'Avenida Paulista',
    'number' => '1578',
    'neighbourhood' => 'Bela Vista',
    'city' => 'Sao Paulo',
    'state' => 'SP',
    'postal_code' => '01310-200',
    'country_code' => 'BR',
]);

// Billing address - No geocoding
$customer->addAddress([
    'type' => 'billing',
    'geocoding_enabled' => false,
    'street' => 'Rua Fiscal',
    'number' => '100',
    'city' => 'Sao Paulo',
    'state' => 'SP',
    'country_code' => 'BR',
]);

// Delivery from map picker - Skip geocoding API ("trust me")
$customer->addAddress([
    'type' => 'delivery',
    'street' => 'Avenida Paulista',
    'number' => '1578',
    'city' => 'Sao Paulo',
    'state' => 'SP',
    'country_code' => 'BR',
    'latitude' => -23.561414,
    'longitude' => -46.656689,
]);
```

### Working with Addresses

```php
// Get all addresses
$addresses = $customer->addresses;

// Get primary address
$primary = $customer->primaryAddress();

// Get formatted address string
$formatted = $customer->full_address;

// Set primary address
$customer->setPrimaryAddress($addressId);

// Get only geocoding-enabled addresses
$physical = $customer->geocodableAddresses;
```

### Query Scopes

```php
use Multek\LaravelGeoaddress\Models\Address;

$geocoded = Address::geocoded()->get();
$failed = Address::failed()->get();
$primary = Address::primary()->get();
$needsGeocoding = Address::needsGeocoding()->get();
$geocodingEnabled = Address::geocodingEnabled()->get();
```

### Geographic Queries (PostGIS)

```php
use MatanYadaev\EloquentSpatial\Objects\Point;

// Find addresses within 5km radius
$nearby = Address::geocodingEnabled()
    ->whereRaw(
        'ST_DWithin(coordinates::geography, ST_MakePoint(?, ?)::geography, ?)',
        [$longitude, $latitude, 5000]
    )
    ->get();
```

### Listening to Events

```php
use Multek\LaravelGeoaddress\Events\AddressGeocoded;

// In a listener or EventServiceProvider
Event::listen(AddressGeocoded::class, function ($event) {
    // $event->address contains the geocoded address
});
```

## Geocoding Design Philosophy

The geocoding system uses a two-layer approach:

### Layer 1: Address Type Capability (Persistent Field)

- `geocoding_enabled = true` (default) - Physical addresses that SHOULD have coordinates
- `geocoding_enabled = false` - Non-physical addresses (billing, PO Box) - coordinates are ALWAYS null

### Layer 2: Smart Detection (Per Request)

When `geocoding_enabled = true`:

- Coordinates provided? YES = "Trust me" - use them, skip API call
- Coordinates provided? NO = "Figure it out" - dispatch geocoding job

## Switching Geocoding Providers

```php
use Multek\LaravelGeoaddress\Services\GeocoderFactory;

$factory = app(GeocoderFactory::class);
$nominatim = $factory->make('nominatim');
$google = $factory->make('google');
$mapbox = $factory->make('mapbox');

// Extend with custom provider
$factory->extend('custom', CustomGeocoder::class);
```

## Testing

```bash
composer test
```

## License

MIT License
