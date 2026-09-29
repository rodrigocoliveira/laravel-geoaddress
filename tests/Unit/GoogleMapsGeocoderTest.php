<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Queue;
use Multek\LaravelGeoaddress\Exceptions\GeocoderNotAvailableException;
use Multek\LaravelGeoaddress\Services\GeocoderFactory;
use Multek\LaravelGeoaddress\Services\GoogleMapsGeocoder;
use Multek\LaravelGeoaddress\Tests\TestModel;

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

    expect($result)->toBe(['lat' => -23.5613, 'lng' => -46.6565]);

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

    (new GoogleMapsGeocoderWithMockClient)->geocode(makeGoogleTestAddress());

    parse_str(GoogleMapsGeocoderWithMockClient::$history[0]['request']->getUri()->getQuery(), $query);

    expect($query['key'])->toBe('spatie-key');
});

test('google geocoder returns null without calling the api when no key is configured', function () {
    config(['geoaddress.google.key' => '', 'geocoder.key' => '']);

    $result = (new GoogleMapsGeocoderWithMockClient)->geocode(makeGoogleTestAddress());

    expect($result)->toBeNull();
    expect(GoogleMapsGeocoderWithMockClient::$history)->toBeEmpty();
});

test('google geocoder returns null when the api returns an error', function () {
    config(['geoaddress.google.key' => 'bad-key']);

    GoogleMapsGeocoderWithMockClient::$responses = [googleResponse([
        'status' => 'REQUEST_DENIED',
        'error_message' => 'The provided API key is invalid.',
        'results' => [],
    ])];

    expect((new GoogleMapsGeocoderWithMockClient)->geocode(makeGoogleTestAddress()))->toBeNull();
});
