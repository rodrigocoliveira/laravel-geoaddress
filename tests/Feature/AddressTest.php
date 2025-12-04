<?php

use Illuminate\Support\Facades\Queue;
use Multek\LaravelGeoaddress\Jobs\GeocodeAddress;
use Multek\LaravelGeoaddress\Models\Address;
use Multek\LaravelGeoaddress\Tests\TestModel;

beforeEach(function () {
    Queue::fake();
});

test('can create an address for a model', function () {
    $model = TestModel::create(['name' => 'Test']);

    $address = $model->addAddress([
        'street' => 'Avenida Paulista',
        'number' => '1578',
        'city' => 'Sao Paulo',
        'state' => 'SP',
        'country_code' => 'BR',
    ]);

    expect($address)->toBeInstanceOf(Address::class);
    expect($address->street)->toBe('Avenida Paulista');
    expect($address->city)->toBe('Sao Paulo');
    expect($address->geocoding_enabled)->toBeTrue();
});

test('can create multiple addresses for a model', function () {
    $model = TestModel::create(['name' => 'Test']);

    $model->addAddress([
        'type' => 'home',
        'street' => 'Rua 1',
        'city' => 'Sao Paulo',
        'state' => 'SP',
        'country_code' => 'BR',
    ]);

    $model->addAddress([
        'type' => 'work',
        'street' => 'Rua 2',
        'city' => 'Sao Paulo',
        'state' => 'SP',
        'country_code' => 'BR',
    ]);

    expect($model->addresses)->toHaveCount(2);
});

test('can set primary address', function () {
    $model = TestModel::create(['name' => 'Test']);

    $address1 = $model->addAddress([
        'is_primary' => true,
        'street' => 'Rua 1',
        'city' => 'Sao Paulo',
        'state' => 'SP',
        'country_code' => 'BR',
    ]);

    $address2 = $model->addAddress([
        'street' => 'Rua 2',
        'city' => 'Sao Paulo',
        'state' => 'SP',
        'country_code' => 'BR',
    ]);

    expect($model->primaryAddress()->id)->toBe($address1->id);

    $model->setPrimaryAddress($address2->id);

    $address1->refresh();
    $address2->refresh();

    expect($address1->is_primary)->toBeFalse();
    expect($address2->is_primary)->toBeTrue();
});

test('geocoding job is dispatched for new address', function () {
    $model = TestModel::create(['name' => 'Test']);

    $model->addAddress([
        'street' => 'Avenida Paulista',
        'number' => '1578',
        'city' => 'Sao Paulo',
        'state' => 'SP',
        'country_code' => 'BR',
    ]);

    Queue::assertPushed(GeocodeAddress::class);
});

test('geocoding job is not dispatched when geocoding is disabled', function () {
    $model = TestModel::create(['name' => 'Test']);

    $model->addAddress([
        'geocoding_enabled' => false,
        'type' => 'billing',
        'street' => 'Rua Fiscal',
        'city' => 'Sao Paulo',
        'state' => 'SP',
        'country_code' => 'BR',
    ]);

    Queue::assertNotPushed(GeocodeAddress::class);
});

test('geocoding job is not dispatched when coordinates are provided', function () {
    $model = TestModel::create(['name' => 'Test']);

    $model->addAddress([
        'street' => 'Avenida Paulista',
        'number' => '1578',
        'city' => 'Sao Paulo',
        'state' => 'SP',
        'country_code' => 'BR',
        'latitude' => -23.561414,
        'longitude' => -46.656689,
    ]);

    Queue::assertNotPushed(GeocodeAddress::class);
});

test('formatted address is generated correctly', function () {
    $model = TestModel::create(['name' => 'Test']);

    $address = $model->addAddress([
        'street' => 'Avenida Paulista',
        'number' => '1578',
        'complement' => 'Apto 101',
        'neighbourhood' => 'Bela Vista',
        'city' => 'Sao Paulo',
        'state' => 'SP',
        'postal_code' => '01310-200',
        'country_code' => 'BR',
    ]);

    expect($address->formatted_address)->toContain('Avenida Paulista, 1578');
    expect($address->formatted_address)->toContain('Apto 101');
    expect($address->formatted_address)->toContain('Bela Vista');
    expect($address->formatted_address)->toContain('Sao Paulo - SP');
    expect($address->formatted_address)->toContain('CEP 01310-200');
});

test('updating address fields triggers re-geocoding', function () {
    $model = TestModel::create(['name' => 'Test']);

    $address = $model->addAddress([
        'street' => 'Avenida Paulista',
        'city' => 'Sao Paulo',
        'state' => 'SP',
        'country_code' => 'BR',
    ]);

    Queue::assertPushed(GeocodeAddress::class, 1);

    $address->update(['street' => 'Rua Augusta']);

    Queue::assertPushed(GeocodeAddress::class, 2);
});

test('updating non-address fields does not trigger re-geocoding', function () {
    $model = TestModel::create(['name' => 'Test']);

    $address = $model->addAddress([
        'street' => 'Avenida Paulista',
        'city' => 'Sao Paulo',
        'state' => 'SP',
        'country_code' => 'BR',
    ]);

    Queue::assertPushed(GeocodeAddress::class, 1);

    $address->update(['customer_name' => 'John Doe']);

    // Should still be only 1 job
    Queue::assertPushed(GeocodeAddress::class, 1);
});

test('billing address never stores coordinates', function () {
    $model = TestModel::create(['name' => 'Test']);

    $address = $model->addAddress([
        'geocoding_enabled' => false,
        'type' => 'billing',
        'street' => 'Rua Fiscal',
        'city' => 'Sao Paulo',
        'state' => 'SP',
        'country_code' => 'BR',
        'latitude' => -23.561414,
        'longitude' => -46.656689,
    ]);

    expect($address->coordinates)->toBeNull();
    expect($address->geocoded_at)->toBeNull();
});

test('address factory works', function () {
    $model = TestModel::create(['name' => 'Test']);

    $address = Address::factory()->create([
        'addressable_type' => TestModel::class,
        'addressable_id' => $model->id,
    ]);

    expect($address)->toBeInstanceOf(Address::class);
    expect($address->street)->not->toBeEmpty();
});

test('address factory billing state works', function () {
    $model = TestModel::create(['name' => 'Test']);

    $address = Address::factory()->billing()->create([
        'addressable_type' => TestModel::class,
        'addressable_id' => $model->id,
    ]);

    expect($address->type)->toBe('billing');
    expect($address->geocoding_enabled)->toBeFalse();
});

test('address scopes work correctly', function () {
    $model = TestModel::create(['name' => 'Test']);

    Address::factory()->create([
        'addressable_type' => TestModel::class,
        'addressable_id' => $model->id,
        'is_primary' => true,
    ]);

    Address::factory()->billing()->create([
        'addressable_type' => TestModel::class,
        'addressable_id' => $model->id,
    ]);

    expect(Address::primary()->count())->toBe(1);
    expect(Address::geocodingEnabled()->count())->toBe(1);
});

test('full address attribute returns primary address formatted', function () {
    $model = TestModel::create(['name' => 'Test']);

    $address = $model->addAddress([
        'is_primary' => true,
        'street' => 'Avenida Paulista',
        'number' => '1578',
        'city' => 'Sao Paulo',
        'state' => 'SP',
        'country_code' => 'BR',
    ]);

    expect($model->full_address)->toBe($address->formatted_address);
});

test('geocodable addresses returns only geocoding enabled addresses', function () {
    $model = TestModel::create(['name' => 'Test']);

    $model->addAddress([
        'type' => 'delivery',
        'street' => 'Rua 1',
        'city' => 'Sao Paulo',
        'state' => 'SP',
        'country_code' => 'BR',
    ]);

    $model->addAddress([
        'type' => 'billing',
        'geocoding_enabled' => false,
        'street' => 'Rua 2',
        'city' => 'Sao Paulo',
        'state' => 'SP',
        'country_code' => 'BR',
    ]);

    expect($model->geocodableAddresses()->count())->toBe(1);
    expect($model->addresses()->count())->toBe(2);
});
