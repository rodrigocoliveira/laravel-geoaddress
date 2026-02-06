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
- **PostgreSQL with PostGIS extension** (required)

> **Note:** This package is designed exclusively for PostgreSQL with PostGIS. It uses PostGIS geography types for storing coordinates and spatial indexing, enabling powerful geographic queries like radius searches. MySQL and SQLite are not supported.

## Installation

```bash
composer require multek/laravel-geoaddress
```

### Quick Install

The easiest way to install is using the install command:

```bash
php artisan geoaddress:install
```

This will:
1. Check and enable PostGIS extension if needed
2. Publish the configuration file
3. Publish and run migrations

### Manual Installation

If you prefer to install manually:

```bash
# Enable PostGIS (if not already enabled)
psql -d your_database -c "CREATE EXTENSION IF NOT EXISTS postgis;"

# Publish config and migrations
php artisan vendor:publish --tag=geoaddress-config
php artisan vendor:publish --tag=geoaddress-migrations
php artisan migrate
```

### Environment Variables

```env
# Geocoding Provider: google, nominatim, mapbox
GEOADDRESS_PROVIDER=google

# Fallback Provider (optional) - used if primary fails
GEOADDRESS_FALLBACK_PROVIDER=nominatim

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

> **Tip:** For production, we recommend using Google Maps as primary provider and Nominatim as fallback. Google is more accurate, especially for Brazilian addresses, while Nominatim provides free fallback if Google is unavailable.

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

The geocoding system uses a two-layer approach to decide when and how coordinates are set.

### Layer 1: `geocoding_enabled` (Persistent — stored in DB)

Controls whether an address **should ever have coordinates**.

| Value | Meaning | Example |
|-------|---------|---------|
| `true` (default) | Physical address — should have coordinates | delivery, home, work |
| `false` | Non-physical address — coordinates are **always null** | billing, PO Box, virtual |

When `geocoding_enabled` is `false`, the system **actively clears** coordinates, `geocoded_at`, and any geocoding error data on every save. This is enforced both in the model's `saving` callback and in the observer's `updating` event.

### Layer 2: `trustProvidedCoordinates` (Transient — per request only)

Controls whether the system **should call a geocoding API** or trust what was provided. This flag is set automatically when you pass `latitude` and `longitude` in the address data — you never set it manually.

| Scenario | Flag | What happens |
|----------|------|--------------|
| `addAddress()` without lat/lng | `false` | Geocoding job is dispatched |
| `addAddress()` with lat/lng | `true` | Coordinates are used directly, **no API call** |
| `update()` changes address fields, no lat/lng | `false` | Old coordinates are cleared, new geocoding job dispatched |
| `update()` changes address fields, with lat/lng | `true` | New coordinates are used directly, **no API call** |
| `update()` changes non-address fields (notes, metadata) | `false` | Nothing happens — existing coordinates are preserved |

### Lifecycle: Converting Address Types

When you change `geocoding_enabled` on an existing address:

**Billing to Delivery** (`false` → `true`):
```php
$address->update(['geocoding_enabled' => true, 'type' => 'delivery']);
// → Geocoding job is dispatched (address now needs coordinates)

// Or, if you already have coordinates:
$address->update([
    'geocoding_enabled' => true,
    'type' => 'delivery',
    'latitude' => -23.561414,
    'longitude' => -46.656689,
]);
// → Coordinates are stored directly, no API call
```

**Delivery to Billing** (`true` → `false`):
```php
$address->update(['geocoding_enabled' => false, 'type' => 'billing']);
// → Coordinates, geocoded_at, and geocoding errors are all cleared
```

### Internal Flow Summary

```
User calls addAddress() or update()
  │
  ├─ geocoding_enabled = false?
  │    └─ Clear all coordinate data → DONE
  │
  ├─ latitude + longitude provided?
  │    ├─ Set trustProvidedCoordinates = true
  │    ├─ Store Point coordinates (PostgreSQL)
  │    ├─ Set geocoded_at timestamp
  │    └─ Fire AddressGeocoded event → DONE (no API call)
  │
  └─ No coordinates provided?
       ├─ Address fields changed? → Clear old coords, dispatch GeocodeAddress job
       ├─ geocoding_enabled just turned on? → Dispatch GeocodeAddress job
       └─ Nothing relevant changed? → DONE (keep existing coords)
```

## Geocoding Providers

### Available Providers

| Provider | Pros | Cons |
|----------|------|------|
| **Google Maps** | Most accurate, great for Brazil | Requires API key, costs money |
| **Nominatim** | Free, no API key needed | Less accurate, rate limited (1 req/sec) |
| **Mapbox** | Good accuracy, generous free tier | Requires access token |

### Fallback Provider

If the primary provider fails (API down, rate limited, etc.), the system can automatically try a fallback provider:

```env
GEOADDRESS_PROVIDER=google
GEOADDRESS_FALLBACK_PROVIDER=nominatim
```

### Custom Providers

```php
use Multek\LaravelGeoaddress\Services\GeocoderFactory;

$factory = app(GeocoderFactory::class);

// Use a specific provider
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
