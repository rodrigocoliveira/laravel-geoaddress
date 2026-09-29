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

    public function geocode(Address $address): ?array
    {
        static::$asked[] = $address->id;

        return array_filter(['lat' => -23.5, 'lng' => -46.6, 'postal_code' => static::$postalCode]);
    }
}

beforeEach(function () {
    Queue::fake();
    FakePostalCodeGeocoder::$postalCode = '01310-200';
    FakePostalCodeGeocoder::$asked = [];
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
    expect($filled->fresh()->postal_code)->toBe('01311-000');
    expect($notGeocoded->fresh()->postal_code)->toBeNull();
    expect(FakePostalCodeGeocoder::$asked)->toBe([$missing->id]);
});

test('backfill leaves the address untouched when the provider has no precise postal code', function () {
    FakePostalCodeGeocoder::$postalCode = null;

    $address = makeBackfillAddress();

    $this->artisan('geoaddress:backfill-postal-codes', ['--provider' => 'fake'])->assertSuccessful();

    expect($address->fresh()->postal_code)->toBeNull();
});
