<?php

use Illuminate\Support\Facades\Queue;
use Multek\LaravelGeoaddress\Exceptions\GeocoderNotAvailableException;
use Multek\LaravelGeoaddress\Jobs\GeocodeAddress;
use Multek\LaravelGeoaddress\Services\GeocoderFactory;
use Multek\LaravelGeoaddress\Tests\TestModel;

test('job exception includes and chains the underlying provider error', function () {
    Queue::fake();

    config(['geoaddress.provider' => 'google', 'geoaddress.fallback_provider' => null]);

    $address = TestModel::create(['name' => 'Test'])->addAddress([
        'street' => 'Avenida Paulista',
        'number' => '1578',
        'city' => 'Sao Paulo',
        'state' => 'SP',
        'country_code' => 'BR',
    ]);

    $factory = Mockery::mock(GeocoderFactory::class);
    $factory->shouldReceive('make')
        ->with('google')
        ->andThrow(GeocoderNotAvailableException::missingPackage('google', 'spatie/geocoder'));

    try {
        (new GeocodeAddress($address->id))->handle($factory);
        $this->fail('Expected exception was not thrown');
    } catch (Exception $e) {
        expect($e->getMessage())->toContain("Geocoding failed for address {$address->id}: [google]");
        expect($e->getMessage())->toContain('spatie/geocoder');
        expect($e->getPrevious())->toBeInstanceOf(GeocoderNotAvailableException::class);
    }

    expect($address->fresh()->geocoding_error)->toContain('[google]');
    expect($address->fresh()->geocoding_failed_at)->not->toBeNull();
});
