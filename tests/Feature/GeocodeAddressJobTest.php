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

class GeocodeAddressWithExposedAttributes extends GeocodeAddress
{
    public function attributesFor($address, array $result): array
    {
        return $this->geocodedAttributes($address, $result);
    }
}

function makeJobTestAddress(array $attributes = [])
{
    Queue::fake();

    return TestModel::create(['name' => 'Test'])->addAddress(array_merge([
        'street' => 'Avenida Paulista',
        'number' => '1578',
        'city' => 'Sao Paulo',
        'state' => 'SP',
        'country_code' => 'BR',
    ], $attributes));
}

test('job fills a missing postal code from the geocode result when enabled', function () {
    config(['geoaddress.fill_missing_postal_code' => true]);

    $address = makeJobTestAddress();

    $attributes = (new GeocodeAddressWithExposedAttributes($address->id))
        ->attributesFor($address, ['lat' => -23.5, 'lng' => -46.6, 'postal_code' => '01310-200']);

    expect($attributes['postal_code'])->toBe('01310-200');
});

test('job never overwrites an existing postal code', function () {
    config(['geoaddress.fill_missing_postal_code' => true]);

    $address = makeJobTestAddress(['postal_code' => '01311-000']);

    $attributes = (new GeocodeAddressWithExposedAttributes($address->id))
        ->attributesFor($address, ['lat' => -23.5, 'lng' => -46.6, 'postal_code' => '01310-200']);

    expect($attributes)->not->toHaveKey('postal_code');
});

test('job leaves the postal code alone when filling is disabled', function () {
    config(['geoaddress.fill_missing_postal_code' => false]);

    $address = makeJobTestAddress();

    $attributes = (new GeocodeAddressWithExposedAttributes($address->id))
        ->attributesFor($address, ['lat' => -23.5, 'lng' => -46.6, 'postal_code' => '01310-200']);

    expect($attributes)->not->toHaveKey('postal_code');
});
