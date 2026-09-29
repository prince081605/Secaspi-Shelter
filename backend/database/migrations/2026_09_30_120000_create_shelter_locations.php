<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * QR-based in-shelter location tracking. The shelter has no map to plot on, and doesn't need
 * one: a location is just a named area. Each animal has a current area, and every move is
 * logged with who made it, when, from where to where, and whether it was done from the page the
 * animal's QR code opens (staff scanning the kennel card) or from the admin panel.
 */
return new class extends Migration
{
    /** The shelter's actual areas, so tracking works on a fresh deploy without setup. */
    private const DEFAULT_AREAS = ['House 1', 'House 2', 'Main Area', 'Kennel 1', 'Kennel 2'];

    public function up(): void
    {
        Schema::create('shelter_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->timestamps();
        });

        $now = now();
        DB::table('shelter_locations')->insert(array_map(
            fn ($name) => ['name' => $name, 'created_at' => $now, 'updated_at' => $now],
            self::DEFAULT_AREAS,
        ));

        Schema::table('animals', function (Blueprint $table) {
            $table->foreignId('current_location_id')->nullable()
                ->constrained('shelter_locations')->nullOnDelete();
        });

        Schema::create('animal_location_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('animal_id')->constrained('animals')->cascadeOnDelete();
            // Null = "unassigned" (or an area since removed).
            $table->foreignId('from_location_id')->nullable()->constrained('shelter_locations')->nullOnDelete();
            $table->foreignId('to_location_id')->nullable()->constrained('shelter_locations')->nullOnDelete();
            $table->foreignId('moved_by')->nullable()->constrained('users')->nullOnDelete();
            // 'qr_page' = updated from the animal's page (what its QR opens); 'admin' = Animals panel.
            $table->string('source', 20)->default('admin');
            $table->string('note', 255)->nullable();
            // useCurrent(), not a bare NOT NULL timestamp: MariaDB would otherwise add
            // ON UPDATE CURRENT_TIMESTAMP to it.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['animal_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('animal_location_logs');

        Schema::table('animals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_location_id');
        });

        Schema::dropIfExists('shelter_locations');
    }
};
