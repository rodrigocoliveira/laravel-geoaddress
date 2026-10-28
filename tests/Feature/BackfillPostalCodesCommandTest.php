<?php

use Illuminate\Support\Facades\Queue;
use Multek\LaravelGeoaddress\Contracts\GeocoderInterface;
use Multek\LaravelGeoaddress\Models\Address;
use Multek\LaravelGeoaddress\Services\GeocoderFactory;
use Multek\LaravelGeoaddress\Tests\TestModel;

class FakePostalCodeGeocoder implements GeocoderInterface
{
    public static ?string $postalCode = '01310-200';

    public static array $asked = [];

    public static ?Throwable $throws = null;

    public function geocode(Address $address): ?array
    {
        static::$asked[] = $address->id;

        if (static::$throws) {
            throw static::$throws;
        }

        return array_filter([
            'lat' => -23.5,
            'lng' => -46.6,
            'postal_code' => static::$postalCode,
            'accuracy' => static::$postalCode ? 'ROOFTOP' : 'APPROXIMATE',
            'postal_code_candidate' => '01310200',
        ]);
    }
}

beforeEach(function () {
    Queue::fake();
    FakePostalCodeGeocoder::$postalCode = '01310-200';
    FakePostalCodeGeocoder::$asked = [];
    FakePostalCodeGeocoder::$throws = null;
    app(GeocoderFactory::class)->extend('fake', FakePostalCodeGeocoder::class);
});

function makeBackfillAddress(array $attributes = [], bool $geocoded = true)
{
    return TestModel::create(['name' => 'Test'])->addAddress(array_merge([
        'street' => 'Avenida Paulista',
        'city' => 'Sao Paulo',
        'state' => 'SP',
    ], $geocoded ? ['latitude' => -23.5, 'longitude' => -46.6] : [], $attributes));
}

test('backfill fills the postal code of geocoded addresses without one', function () {
    $missing = makeBackfillAddress();
    $filled = makeBackfillAddress(['postal_code' => '01311-000']);
    $notGeocoded = makeBackfillAddress(geocoded: false);

    $this->artisan('geoaddress:backfill-postal-codes', ['--provider' => 'fake'])->assertSuccessful();

    expect($missing->fresh()->postal_code)->toBe('01310-200');
    expect($missing->fresh()->metadata['geocoding'])->toMatchArray([
        'provider' => 'fake',
        'accuracy' => 'ROOFTOP',
        'postal_code_candidate' => '01310200',
        'postal_code_applied' => true,
    ]);
    expect($filled->fresh()->postal_code)->toBe('01311-000');
    expect($notGeocoded->fresh()->postal_code)->toBeNull();
    expect(FakePostalCodeGeocoder::$asked)->toBe([$missing->id]);
});

test('backfill keeps the postal code empty but records why when the match is imprecise', function () {
    FakePostalCodeGeocoder::$postalCode = null;

    $address = makeBackfillAddress();

    $this->artisan('geoaddress:backfill-postal-codes', ['--provider' => 'fake'])->assertSuccessful();

    expect($address->fresh()->postal_code)->toBeNull();
    expect($address->fresh()->metadata['geocoding'])->toMatchArray([
        'accuracy' => 'APPROXIMATE',
        'postal_code_candidate' => '01310200',
        'postal_code_applied' => false,
        'error' => null,
    ]);
});

test('backfill records the provider error and moves on to the next address', function () {
    FakePostalCodeGeocoder::$throws = new RuntimeException('ZERO_RESULTS for "Avenida Paulista"');

    $first = makeBackfillAddress();
    $second = makeBackfillAddress();

    $this->artisan('geoaddress:backfill-postal-codes', ['--provider' => 'fake'])
        ->expectsOutputToContain("#{$first->id} ZERO_RESULTS")
        ->assertSuccessful();

    expect(FakePostalCodeGeocoder::$asked)->toBe([$first->id, $second->id]);
    expect($first->fresh()->postal_code)->toBeNull();
    expect($first->fresh()->metadata['geocoding']['error'])->toBe('ZERO_RESULTS for "Avenida Paulista"');
});
