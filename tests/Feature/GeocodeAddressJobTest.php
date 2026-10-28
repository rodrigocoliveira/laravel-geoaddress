<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Multek\LaravelGeoaddress\Exceptions\GeocoderNotAvailableException;
use Multek\LaravelGeoaddress\Jobs\GeocodeAddress;
use Multek\LaravelGeoaddress\Services\GeocoderFactory;
use Multek\LaravelGeoaddress\Tests\TestModel;

class GeocodeAddressWithExposedAttributes extends GeocodeAddress
{
    public function attributesFor($address, array $result): array
    {
        return $this->geocodedAttributes($address, $result, 'google');
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

function factoryThatThrows(): GeocoderFactory
{
    $factory = Mockery::mock(GeocoderFactory::class);
    $factory->shouldReceive('make')
        ->with('google')
        ->andThrow(GeocoderNotAvailableException::missingPackage('google', 'spatie/geocoder'));

    return $factory;
}

beforeEach(function () {
    config(['geoaddress.provider' => 'google', 'geoaddress.fallback_provider' => null]);
});

test('job stores what the provider answered in metadata on success, keeping existing keys', function () {
    config(['geoaddress.fill_missing_postal_code' => true]);

    $address = makeJobTestAddress(['metadata' => ['source_address_id' => 42]]);

    // SQLite has no ST_GeomFromText, so the write itself is not exercised here.
    $attributes = (new GeocodeAddressWithExposedAttributes($address->id))->attributesFor($address, [
        'lat' => -23.5613,
        'lng' => -46.6565,
        'postal_code' => '01310-200',
        'provider' => 'google',
        'query' => $address->formatted_address,
        'accuracy' => 'ROOFTOP',
        'partial_match' => false,
        'place_id' => 'abc',
        'formatted_address' => 'Av. Paulista, 1578',
        'postal_code_candidate' => '01310200',
    ]);

    expect($attributes['postal_code'])->toBe('01310-200');
    expect($attributes['metadata']['source_address_id'])->toBe(42);
    expect($attributes['metadata']['geocoding'])->toMatchArray([
        'provider' => 'google',
        'query' => $address->formatted_address,
        'accuracy' => 'ROOFTOP',
        'partial_match' => false,
        'place_id' => 'abc',
        'formatted_address' => 'Av. Paulista, 1578',
        'postal_code_candidate' => '01310200',
        'postal_code_applied' => true,
        'error' => null,
    ]);
    expect($attributes['metadata']['geocoding']['at'])->not->toBeEmpty();
});

test('job rethrows on a non-final attempt without marking the address failed', function () {
    $address = makeJobTestAddress();

    $job = new GeocodeAddress($address->id);

    expect($job->tries)->toBe(3);
    expect($job->attempts())->toBe(1);

    expect(fn () => $job->handle(factoryThatThrows()))
        ->toThrow(Exception::class, "Geocoding failed for address {$address->id}: [google]");

    $address->refresh();

    expect($address->geocoding_failed_at)->toBeNull();
    expect($address->geocoding_error)->toBeNull();
    expect($address->metadata)->toBeNull();
});

test('job marks the address failed with the real provider error on the last attempt', function () {
    $address = makeJobTestAddress();

    $job = new GeocodeAddress($address->id);
    $job->tries = 1;

    try {
        $job->handle(factoryThatThrows());
        $this->fail('Expected exception was not thrown');
    } catch (Exception $e) {
        expect($e->getMessage())->toContain("Geocoding failed for address {$address->id}: [google]");
        expect($e->getMessage())->toContain('spatie/geocoder');
        expect($e->getPrevious())->toBeInstanceOf(GeocoderNotAvailableException::class);
    }

    $address->refresh();

    expect($address->geocoding_failed_at)->not->toBeNull();
    expect($address->geocoding_error)->toContain('[google]')->toContain('spatie/geocoder');
    expect($address->metadata['geocoding'])->toMatchArray([
        'provider' => 'google',
        'query' => $address->formatted_address,
        'accuracy' => null,
        'postal_code_applied' => false,
    ]);
    expect($address->metadata['geocoding']['error'])->toContain('spatie/geocoder');
});

test('job reaches failed() when the last attempt throws', function () {
    Log::spy();

    // Created without events so the observer does not run the job (sync queue) during setup.
    $address = Model::withoutEvents(fn () => TestModel::create(['name' => 'Test'])->addAddress([
        'street' => 'Avenida Paulista',
        'number' => '1578',
        'city' => 'Sao Paulo',
        'state' => 'SP',
    ]));

    app()->instance(GeocoderFactory::class, factoryThatThrows());

    $job = new GeocodeAddress($address->id);
    $job->tries = 1;

    expect(fn () => dispatch_sync($job))->toThrow(Exception::class, 'spatie/geocoder');

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message) => str_contains($message, "failed permanently for address {$address->id}"))
        ->once();

    expect($address->fresh()->geocoding_failed_at)->not->toBeNull();
});

test('job fills a missing postal code from the geocode result when enabled', function () {
    config(['geoaddress.fill_missing_postal_code' => true]);

    $address = makeJobTestAddress();

    $attributes = (new GeocodeAddressWithExposedAttributes($address->id))
        ->attributesFor($address, ['lat' => -23.5, 'lng' => -46.6, 'postal_code' => '01310-200']);

    expect($attributes['postal_code'])->toBe('01310-200');
    expect($attributes['metadata']['geocoding']['postal_code_applied'])->toBeTrue();
});

test('job never overwrites an existing postal code', function () {
    config(['geoaddress.fill_missing_postal_code' => true]);

    $address = makeJobTestAddress(['postal_code' => '01311-000']);

    $attributes = (new GeocodeAddressWithExposedAttributes($address->id))
        ->attributesFor($address, ['lat' => -23.5, 'lng' => -46.6, 'postal_code' => '01310-200']);

    expect($attributes)->not->toHaveKey('postal_code');
    expect($attributes['metadata']['geocoding']['postal_code_applied'])->toBeFalse();
});

test('job leaves the postal code alone when filling is disabled', function () {
    config(['geoaddress.fill_missing_postal_code' => false]);

    $address = makeJobTestAddress();

    $attributes = (new GeocodeAddressWithExposedAttributes($address->id))
        ->attributesFor($address, ['lat' => -23.5, 'lng' => -46.6, 'postal_code' => '01310-200']);

    expect($attributes)->not->toHaveKey('postal_code');
});
