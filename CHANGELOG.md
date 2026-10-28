# Changelog

## 1.3.0 - 2026-10-28

Geocoding failures are no longer invisible ([#5](https://github.com/rodrigocoliveira/laravel-geoaddress/issues/5)).

### Added
- Every geocode, successful or not, writes a `geocoding` block into `addresses.metadata` (`provider`, `query`, `accuracy`, `partial_match`, `place_id`, `formatted_address`, `postal_code_candidate`, `postal_code_applied`, `error`, `at`). Existing metadata keys are kept. `geoaddress:backfill-postal-codes` writes the same block.
- `GeocodingFailedException`, thrown by the Google provider when Google finds no result, carrying the status and the query.
- `Address::metadataWithGeocoding()`.
- `GeocoderInterface::geocode()` may return the diagnostic keys above; `lat`, `lng` and `postal_code` are unchanged.

### Changed
- `GoogleMapsGeocoder` no longer swallows provider errors: spatie's `CouldNotGeocode` propagates, so `geocoding_error` carries the real cause (`REQUEST_DENIED`, `OVER_QUERY_LIMIT`, timeout, ...) instead of "Unable to geocode address with any provider".
- `GeocodeAddress` only writes `geocoding_failed_at` / `geocoding_error` on its last attempt. Earlier attempts rethrow without touching the address, so the queue actually retries with `$backoff` and the final failure lands in `failed_jobs` and reaches `failed()`. Previously the first attempt marked the address failed and the retries returned early on the 24h guard.
- `geoaddress:backfill-postal-codes` continues past an address whose provider call throws, recording the error on it.

### Fixed
- A Google `ZERO_RESULTS` answer was treated as a successful geocode at coordinates `0, 0` (spatie returns integer zeros, which the `!== 0.0` check did not catch). It now fails with `GeocodingFailedException`.
