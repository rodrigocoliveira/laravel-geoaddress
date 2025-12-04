<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates polymorphic addresses table for reusable address storage.
     * Any model can have one or more addresses (home, work, billing, shipping, etc.).
     * Includes PostGIS support for geographic coordinates and spatial queries.
     *
     * Geocoding behavior controlled by `geocoding_enabled`:
     * - true (default): Address will have coordinates (from API or provided)
     * - false: Coordinates are ALWAYS null (billing, PO Box, virtual addresses)
     */
    public function up(): void
    {
        $isPostgres = DB::getDriverName() === 'pgsql';

        Schema::create('addresses', function (Blueprint $table) use ($isPostgres) {
            $table->id();

            // Polymorphic relationship
            $table->morphs('addressable');

            // Address type and identification
            $table->string('type')->nullable()
                ->comment('Address type: home, work, billing, shipping, delivery, etc.');
            $table->string('nickname')->nullable()
                ->comment('User-friendly name for the address');
            $table->boolean('is_primary')->default(false);

            // Geocoding control
            $table->boolean('geocoding_enabled')->default(true)
                ->comment('false = billing/virtual address (coords always null), true = physical address (will have coords)');

            // Brazilian-style address fields
            $table->string('street');
            $table->string('number')->nullable();
            $table->string('complement')->nullable();
            $table->string('neighbourhood')->nullable();
            $table->string('city');
            $table->string('state');
            $table->string('postal_code')->nullable();
            $table->string('country_code', 2)->default('BR'); // ISO 3166-1 alpha-2
            $table->text('reference_point')->nullable()
                ->comment('Free text description to help locate the address');

            // Customer/contact information
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 50)->nullable();
            $table->string('customer_country_code_phone', 10)->nullable();
            $table->string('customer_document', 100)->nullable()
                ->comment('CPF, CNPJ, tax IDs, etc.');
            $table->text('notes')->nullable()
                ->comment('Delivery instructions, billing notes, etc.');
            $table->json('metadata')->nullable()
                ->comment('Flexible storage for edge cases and custom data');

            // Geocoding status and coordinates
            if ($isPostgres) {
                // PostGIS Point for geocoded location (PostgreSQL only)
                $table->geography('coordinates', subtype: 'point', srid: 4326)->nullable();
            } else {
                // Fallback for testing (SQLite/MySQL) - stores as text
                $table->text('coordinates')->nullable();
            }

            $table->timestamp('geocoded_at')->nullable()
                ->comment('When coordinates were set (via API or manual)');
            $table->timestamp('geocoding_failed_at')->nullable();
            $table->text('geocoding_error')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['addressable_type', 'addressable_id', 'is_primary']);
            $table->index(['geocoding_enabled']);
        });

        // PostGIS spatial index for coordinates queries (PostgreSQL only)
        if ($isPostgres) {
            DB::statement('CREATE INDEX addresses_coordinates_gist_idx ON addresses USING GIST (coordinates)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
