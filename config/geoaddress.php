<?php

/*
|--------------------------------------------------------------------------
| Geoaddress Package Configuration
|--------------------------------------------------------------------------
|
| This configuration file controls the address package features including
| geocoding provider selection, queue settings, and retry logic.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Geocoding Provider
    |--------------------------------------------------------------------------
    |
    | The geocoding provider to use for converting addresses to coordinates.
    | Supported: "google", "nominatim", "mapbox"
    |
    */
    'provider' => env('GEOADDRESS_PROVIDER', 'google'),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | The timeout in seconds for geocoding API requests.
    |
    */
    'timeout' => env('GEOADDRESS_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for retrying failed geocoding requests.
    |
    */
    'retry' => [
        'times' => env('GEOADDRESS_RETRY_TIMES', 3),
        'sleep' => env('GEOADDRESS_RETRY_SLEEP', 1000), // milliseconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the geocoding queue jobs.
    |
    */
    'queue' => [
        'connection' => env('GEOADDRESS_QUEUE_CONNECTION', null), // null uses default
        'name' => env('GEOADDRESS_QUEUE_NAME', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Maps Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Google Maps Geocoding API.
    | Get your API key from: https://console.cloud.google.com/
    |
    */
    'google' => [
        'key' => env('GOOGLE_MAPS_API_KEY', ''),
        'language' => env('GOOGLE_MAPS_LANGUAGE', ''),
        'region' => env('GOOGLE_MAPS_REGION', ''),
        'country' => env('GOOGLE_MAPS_COUNTRY', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Nominatim Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for OpenStreetMap's Nominatim geocoding API.
    | Free but rate-limited. Consider hosting your own instance for production.
    |
    | @see https://nominatim.org/release-docs/latest/api/Search/
    |
    */
    'nominatim' => [
        'url' => env('NOMINATIM_URL', 'https://nominatim.openstreetmap.org'),
        'user_agent' => env('NOMINATIM_USER_AGENT', config('app.name', 'Laravel')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mapbox Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Mapbox Geocoding API.
    |
    | @see https://docs.mapbox.com/api/search/geocoding/
    |
    */
    'mapbox' => [
        'access_token' => env('MAPBOX_ACCESS_TOKEN', ''),
    ],

];
