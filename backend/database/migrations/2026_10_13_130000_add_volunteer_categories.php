<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Volunteer categories: what each person helps with (rescue, feeding, transport…). A managed
 * list (CategoryOption 'volunteer_category', keyed, so renaming only changes the label), seeded
 * here. Personnel store the keys they're assigned in `volunteers.categories`; applicants say what
 * they'd like to help with in `volunteer_applications.preferred_categories`, which is copied over
 * when they're approved.
 */
return new class extends Migration
{
    private const DEFAULTS = [
        'rescue' => 'Rescue',
        'feeding' => 'Feeding & daily care',
        'cleaning' => 'Cleaning',
        'transport' => 'Transport / driver',
        'medical' => 'Medical assistance',
        'adoption_support' => 'Adoption support',
        'events' => 'Events & fundraising',
        'media' => 'Photos & social media',
    ];

    public function up(): void
    {
        Schema::table('volunteers', function (Blueprint $table) {
            $table->json('categories')->nullable();
        });

        Schema::table('volunteer_applications', function (Blueprint $table) {
            $table->json('preferred_categories')->nullable();
        });

        $now = now();
        $order = 0;
        $rows = [];
        foreach (self::DEFAULTS as $key => $label) {
            $rows[] = [
                'type' => 'volunteer_category',
                'value' => $key,
                'label' => $label,
                'sort_order' => ++$order,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('category_options')->insert($rows);
    }

    public function down(): void
    {
        DB::table('category_options')->where('type', 'volunteer_category')->delete();

        Schema::table('volunteer_applications', function (Blueprint $table) {
            $table->dropColumn('preferred_categories');
        });

        Schema::table('volunteers', function (Blueprint $table) {
            $table->dropColumn('categories');
        });
    }
};
