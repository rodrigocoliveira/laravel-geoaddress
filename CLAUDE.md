# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Package Overview

`multek/laravel-geoaddress` — A polymorphic address system with automatic geocoding for Laravel. Any Eloquent model can have multiple typed addresses (home, work, billing, shipping, delivery) with async geocoding via Google Maps, Nominatim, or Mapbox. Requires PHP 8.2+, Laravel 11/12, and PostgreSQL with PostGIS.

## Commands

```bash
# Run all tests (Pest v3)
vendor/bin/pest

# Run a single test file
vendor/bin/pest tests/Feature/AddressTest.php

# Run a specific test by name
vendor/bin/pest --filter="can create address"

# Run only unit or feature tests
vendor/bin/pest --testsuite=Unit
vendor/bin/pest --testsuite=Feature
```

No linter or static analysis is configured. CI runs the Pest test suite across PHP 8.2/8.3 and Laravel 11/12.

## Architecture

### Core Flow

1. **Addressable trait** (`src/Traits/Addressable.php`) — Add `use Addressable` to any model to get `addresses()` morphMany, `addAddress()`, `setPrimaryAddress()`, and related helpers.
2. **Address model** (`src/Models/Address.php`) — Polymorphic model with PostGIS `Point` coordinates via `laravel-eloquent-spatial`. Scopes: `primary()`, `geocoded()`, `failed()`, `needsGeocoding()`, `geocodingEnabled()`.
3. **AddressObserver** (`src/Observers/AddressObserver.php`) — On `created`/`updated`, dispatches the geocoding job if needed. On `saved`, enforces single primary address per addressable. On `updating`, clears coordinates when address fields change.
4. **GeocodeAddress job** (`src/Jobs/GeocodeAddress.php`) — Queued job that calls the configured geocoder, falls back to `fallback_provider` on failure, fires `AddressGeocoded` event on success.
5. **GeocoderFactory** (`src/Services/GeocoderFactory.php`) — Resolves geocoder instances by name (google, nominatim, mapbox). Extensible via `extend()` for custom providers.

### Two-Layer Geocoding Design

- **Layer 1 (persistent)**: `geocoding_enabled` boolean on each address. When `false`, coordinates are always null (for billing/PO Box addresses).
- **Layer 2 (per-request)**: If coordinates are provided in `addAddress()`, they're used directly (no API call). Otherwise, a queued geocoding job is dispatched.

### Key Dependencies

- `matanyadaev/laravel-eloquent-spatial` (v4) — Spatial column support and PostGIS queries
- `spatie/geocoder` (v3, dev-only/suggested) — Used by GoogleMapsGeocoder
- `orchestra/testbench` (v9-10) — Laravel package testing harness

### Testing Setup

Tests use Orchestra Testbench with SQLite. The test base class (`tests/TestCase.php`) sets up the package service provider, runs the migration, and configures a testing DB connection. `tests/TestModel.php` is a minimal model using the Addressable trait. Queue is faked in feature tests.

### Configuration

All settings are in `config/geoaddress.php`. Key env vars: `GEOADDRESS_PROVIDER`, `GEOADDRESS_FALLBACK_PROVIDER`, `GOOGLE_MAPS_API_KEY`, `NOMINATIM_USER_AGENT`, `MAPBOX_ACCESS_TOKEN`.

### Database

Single `addresses` table with polymorphic `addressable_type`/`addressable_id`, Brazilian address fields (street, number, complement, neighbourhood, city, state, postal_code, country_code), customer contact fields, geocoding status tracking, and a PostGIS `GEOGRAPHY(POINT, 4326)` coordinates column with GIST spatial index. Falls back to text storage on SQLite/MySQL.
