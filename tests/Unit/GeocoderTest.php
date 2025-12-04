<?php

use Multek\LaravelGeoaddress\Services\GeocoderFactory;

test('geocoder factory returns configured provider', function () {
    config(['geoaddress.provider' => 'nominatim']);

    $factory = new GeocoderFactory;
    $geocoder = $factory->make();

    expect($geocoder)->toBeInstanceOf(\Multek\LaravelGeoaddress\Services\NominatimGeocoder::class);
});

test('geocoder factory returns google provider by default', function () {
    config(['geoaddress.provider' => 'google']);

    $factory = new GeocoderFactory;
    $geocoder = $factory->make();

    expect($geocoder)->toBeInstanceOf(\Multek\LaravelGeoaddress\Services\GoogleMapsGeocoder::class);
});

test('geocoder factory can return mapbox provider', function () {
    $factory = new GeocoderFactory;
    $geocoder = $factory->make('mapbox');

    expect($geocoder)->toBeInstanceOf(\Multek\LaravelGeoaddress\Services\MapboxGeocoder::class);
});

test('geocoder factory throws exception for unknown provider', function () {
    $factory = new GeocoderFactory;

    expect(fn () => $factory->make('unknown'))->toThrow(InvalidArgumentException::class);
});

test('geocoder factory can list available providers', function () {
    $factory = new GeocoderFactory;
    $providers = $factory->getProviders();

    expect($providers)->toContain('google');
    expect($providers)->toContain('nominatim');
    expect($providers)->toContain('mapbox');
});

test('geocoder factory can extend with custom provider', function () {
    $factory = new GeocoderFactory;
    $factory->extend('custom', \Multek\LaravelGeoaddress\Services\NominatimGeocoder::class);

    $providers = $factory->getProviders();

    expect($providers)->toContain('custom');
});
