<?php

namespace Multek\LaravelGeoaddress\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use MatanYadaev\EloquentSpatial\Objects\Point;
use Multek\LaravelGeoaddress\Models\Address;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Multek\LaravelGeoaddress\Models\Address>
 */
class AddressFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Address::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => fake()->randomElement(['home', 'work', 'billing', 'shipping', 'delivery', null]),
            'nickname' => fake()->randomElement(['Home', 'Work', 'Office', 'Warehouse', null]),
            'is_primary' => false,
            'geocoding_enabled' => true,
            'street' => fake()->streetName(),
            'number' => fake()->buildingNumber(),
            'complement' => fake()->optional()->secondaryAddress(),
            'neighbourhood' => fake()->optional()->citySuffix(),
            'city' => fake()->city(),
            'state' => fake()->stateAbbr(),
            'postal_code' => fake()->postcode(),
            'country_code' => fake()->countryCode(),
            'reference_point' => fake()->optional()->sentence(),
            'customer_name' => fake()->optional()->name(),
            'customer_phone' => fake()->optional()->phoneNumber(),
            'customer_country_code_phone' => fake()->optional()->randomElement(['+55', '+1', '+44', '+49']),
            'customer_document' => fake()->optional()->numerify('###.###.###-##'),
            'notes' => fake()->optional()->sentence(),
            'metadata' => null,
        ];
    }

    /**
     * Indicate that the address is primary.
     */
    public function primary(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_primary' => true,
        ]);
    }

    /**
     * Indicate that this is a billing address (no geocoding).
     */
    public function billing(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'billing',
            'geocoding_enabled' => false,
        ]);
    }

    /**
     * Indicate that geocoding is disabled for this address.
     */
    public function withoutGeocoding(): static
    {
        return $this->state(fn (array $attributes) => [
            'geocoding_enabled' => false,
        ]);
    }

    /**
     * Indicate that the address has been geocoded.
     */
    public function geocoded(): static
    {
        $isPostgres = DB::connection()->getDriverName() === 'pgsql';

        return $this->state(fn (array $attributes) => [
            'geocoding_enabled' => true,
            'coordinates' => $isPostgres
                ? new Point(
                    fake()->latitude(),
                    fake()->longitude(),
                    4326
                )
                : null, // SQLite doesn't support PostGIS
            'geocoded_at' => now(),
        ]);
    }

    /**
     * Indicate that geocoding failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'geocoding_enabled' => true,
            'geocoding_failed_at' => now(),
            'geocoding_error' => 'Unable to geocode address',
        ]);
    }

    /**
     * Indicate that the address has customer contact information.
     */
    public function withCustomer(): static
    {
        return $this->state(fn (array $attributes) => [
            'customer_name' => fake()->name(),
            'customer_phone' => fake()->phoneNumber(),
            'customer_country_code_phone' => '+55',
            'customer_document' => fake()->numerify('###.###.###-##'),
            'notes' => fake()->sentence(),
        ]);
    }

    /**
     * Indicate that the address has metadata.
     */
    public function withMetadata(array $metadata = []): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata' => $metadata ?: [
                'delivery_window' => [
                    'from' => '09:00',
                    'to' => '17:00',
                ],
                'special_instructions' => fake()->sentence(),
            ],
        ]);
    }
}
