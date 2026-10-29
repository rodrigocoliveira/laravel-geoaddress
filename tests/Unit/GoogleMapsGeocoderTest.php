<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Queue;
use Multek\LaravelGeoaddress\Exceptions\GeocoderNotAvailableException;
use Multek\LaravelGeoaddress\Exceptions\GeocodingFailedException;
use Multek\LaravelGeoaddress\Services\GeocoderFactory;
use Multek\LaravelGeoaddress\Services\GoogleMapsGeocoder;
use Multek\LaravelGeoaddress\Tests\TestModel;
use Spatie\Geocoder\Exceptions\CouldNotGeocode;

class GoogleMapsGeocoderWithoutSpatie extends GoogleMapsGeocoder
{
    protected function dependencyInstalled(): bool
    {
        return false;
    }
}

class GoogleMapsGeocoderWithMockClient extends GoogleMapsGeocoder
{
    public static array $history = [];

    public static array $responses = [];

    protected function httpClient(): Client
    {
        $stack = HandlerStack::create(new MockHandler(static::$responses));
        $stack->push(Middleware::history(static::$history));

        return new Client(['handler' => $stack]);
    }
}

function makeGoogleTestAddress()
{
    Queue::fake();

    return TestModel::create(['name' => 'Test'])->addAddress([
        'street' => 'Avenida Paulista',
        'number' => '1578',
        'city' => 'Sao Paulo',
        'state' => 'SP',
        'country_code' => 'BR',
    ]);
}

function googleResponse(array $body): Response
{
    return new Response(200, [], json_encode($body));
}

beforeEach(function () {
    GoogleMapsGeocoderWithMockClient::$history = [];
    GoogleMapsGeocoderWithMockClient::$responses = [];
});

test('factory gives a descriptive error when spatie/geocoder is not installed', function () {
    $factory = new GeocoderFactory;
    $factory->extend('google', GoogleMapsGeocoderWithoutSpatie::class);

    expect(fn () => $factory->make('google'))
        ->toThrow(GeocoderNotAvailableException::class, 'requires the spatie/geocoder package');
});

test('google geocoder uses geoaddress.google config', function () {
    config([
        'geoaddress.google.key' => 'geoaddress-key',
        'geoaddress.google.language' => 'pt-BR',
        'geoaddress.google.region' => 'br',
    ]);

    GoogleMapsGeocoderWithMockClient::$responses = [googleResponse([
        'status' => 'OK',
        'results' => [[
            'geometry' => [
                'location' => ['lat' => -23.5613, 'lng' => -46.6565],
                'location_type' => 'ROOFTOP',
                'viewport' => [],
            ],
            'formatted_address' => 'Av. Paulista, 1578',
            'address_components' => [],
            'place_id' => 'abc',
            'types' => [],
        ]],
    ])];

    $result = (new GoogleMapsGeocoderWithMockClient)->geocode(makeGoogleTestAddress());

    expect($result)->toMatchArray(['lat' => -23.5613, 'lng' => -46.6565])->not->toHaveKey('postal_code');

    parse_str(GoogleMapsGeocoderWithMockClient::$history[0]['request']->getUri()->getQuery(), $query);

    expect($query['key'])->toBe('geoaddress-key');
    expect($query['language'])->toBe('pt-BR');
    expect($query['region'])->toBe('br');
});

test('google geocoder falls back to spatie geocoder config key', function () {
    config([
        'geoaddress.google.key' => '',
        'geocoder.key' => 'spatie-key',
    ]);

    GoogleMapsGeocoderWithMockClient::$responses = [googleResponse(['status' => 'ZERO_RESULTS', 'results' => []])];

    rescue(fn () => (new GoogleMapsGeocoderWithMockClient)->geocode(makeGoogleTestAddress()), report: false);

    parse_str(GoogleMapsGeocoderWithMockClient::$history[0]['request']->getUri()->getQuery(), $query);

    expect($query['key'])->toBe('spatie-key');
});

test('google geocoder returns null without calling the api when no key is configured', function () {
    config(['geoaddress.google.key' => '', 'geocoder.key' => '']);

    $result = (new GoogleMapsGeocoderWithMockClient)->geocode(makeGoogleTestAddress());

    expect($result)->toBeNull();
    expect(GoogleMapsGeocoderWithMockClient::$history)->toBeEmpty();
});

test('google geocoder lets the api error through with its message', function () {
    config(['geoaddress.google.key' => 'bad-key']);

    GoogleMapsGeocoderWithMockClient::$responses = [googleResponse([
        'status' => 'REQUEST_DENIED',
        'error_message' => 'The provided API key is invalid.',
        'results' => [],
    ])];

    expect(fn () => (new GoogleMapsGeocoderWithMockClient)->geocode(makeGoogleTestAddress()))
        ->toThrow(CouldNotGeocode::class, 'The provided API key is invalid.');
});

test('google geocoder throws with the status and the query when there are no results', function () {
    config(['geoaddress.google.key' => 'key']);

    GoogleMapsGeocoderWithMockClient::$responses = [googleResponse(['status' => 'ZERO_RESULTS', 'results' => []])];

    $address = makeGoogleTestAddress();

    expect(fn () => (new GoogleMapsGeocoderWithMockClient)->geocode($address))
        ->toThrow(GeocodingFailedException::class, "[google] geocoding provider returned ZERO_RESULTS for \"{$address->formatted_address}\"");
});

function googleResultWith(array $overrides = [], array $components = []): Response
{
    return googleResponse([
        'status' => 'OK',
        'results' => [array_merge([
            'geometry' => [
                'location' => ['lat' => -23.5613, 'lng' => -46.6565],
                'location_type' => 'ROOFTOP',
                'viewport' => [],
            ],
            'formatted_address' => 'Av. Paulista, 1578',
            'address_components' => $components,
            'place_id' => 'abc',
            'types' => [],
        ], $overrides)],
    ]);
}

function postalCodeComponent(string $value, string $type = 'postal_code'): array
{
    return ['long_name' => $value, 'short_name' => $value, 'types' => [$type]];
}

test('google geocoder returns the postal code of a precise match, formatted as a CEP', function () {
    config(['geoaddress.google.key' => 'key']);

    GoogleMapsGeocoderWithMockClient::$responses = [googleResultWith([], [postalCodeComponent('01310200')])];

    $address = makeGoogleTestAddress();

    expect((new GoogleMapsGeocoderWithMockClient)->geocode($address))->toBe([
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
});

test('google geocoder omits the postal code of an imprecise match but keeps it as a candidate', function (array $overrides, array $components, string $candidate) {
    config(['geoaddress.google.key' => 'key']);

    GoogleMapsGeocoderWithMockClient::$responses = [googleResultWith($overrides, $components)];

    $result = (new GoogleMapsGeocoderWithMockClient)->geocode(makeGoogleTestAddress());

    expect($result)->toMatchArray(['lat' => -23.5613, 'lng' => -46.6565, 'postal_code_candidate' => $candidate])
        ->not->toHaveKey('postal_code');
})->with([
    'approximate (city centroid)' => [
        ['geometry' => ['location' => ['lat' => -23.5613, 'lng' => -46.6565], 'location_type' => 'APPROXIMATE', 'viewport' => []]],
        [postalCodeComponent('01000-000')],
        '01000-000',
    ],
    'partial match' => [['partial_match' => true], [postalCodeComponent('01310-200')], '01310-200'],
    'prefix only' => [[], [postalCodeComponent('01310', 'postal_code_prefix')], '01310'],
    'incomplete CEP' => [[], [postalCodeComponent('01310')], '01310'],
]);
