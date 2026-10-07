<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Admin-managed lists ("categories"): dog and cat breeds, behavioral issues, medical record types
 * and valid ID types. Until now each was hard-coded in the app; they're seeded here with exactly
 * those values, so nothing changes on deploy until an admin edits a list.
 *
 * `value` is what records store and `label` is what people see. For breeds and behavioral issues
 * they're the same text (animals store the words themselves); medical record types and ID types
 * keep a fixed key and a renameable label. See App\Models\CategoryOption.
 */
return new class extends Migration
{
    private const DEFAULTS = [
        'dog_breed' => [
            'Aspin', 'Aspin mix', 'Akita', 'Alaskan Malamute', 'American Bully', 'Beagle', 'Belgian Malinois',
            'Bichon Frise', 'Border Collie', 'Boxer', 'Chihuahua', 'Chow Chow', 'Cocker Spaniel', 'Corgi',
            'Dachshund', 'Dalmatian', 'Doberman Pinscher', 'English Bulldog', 'French Bulldog', 'German Shepherd',
            'Golden Retriever', 'Great Dane', 'Jack Russell Terrier', 'Japanese Spitz', 'Labrador Retriever',
            'Lhasa Apso', 'Maltese', 'Miniature Pinscher', 'Miniature Schnauzer', 'Pekingese', 'Pit Bull Terrier',
            'Pomeranian', 'Poodle', 'Pug', 'Rottweiler', 'Saint Bernard', 'Samoyed', 'Shiba Inu', 'Shih Tzu',
            'Shih Tzu mix', 'Siberian Husky', 'Yorkshire Terrier', 'Mixed breed',
        ],
        'cat_breed' => [
            'Puspin', 'Puspin mix', 'Abyssinian', 'American Shorthair', 'Bengal', 'British Shorthair', 'Burmese',
            'Domestic Longhair', 'Domestic Shorthair', 'Exotic Shorthair', 'Himalayan', 'Maine Coon', 'Munchkin',
            'Norwegian Forest Cat', 'Persian', 'Ragdoll', 'Russian Blue', 'Scottish Fold', 'Siamese', 'Sphynx',
            'Turkish Angora', 'Mixed breed',
        ],
        'behavioral_issue' => [
            'separation anxiety', 'aggression & resource guarding', 'dog-to-dog aggression', 'territorial aggression',
            'fear aggression', 'destructive chewing & digging', 'inappropriate elimination', 'excessive barking',
            'excessive vocalization', 'jumping/mouthing', 'pulling on leash', 'excessive energy', 'extreme shyness',
            'fear of strangers', 'fear of loud noises', 'post-trauma/trust issues', 'pain-related aggression',
            'cognitive issues (senior)',
        ],
        'medical_record_type' => [
            'vaccination' => 'Vaccination', 'deworming' => 'Deworming', 'treatment' => 'Treatment',
            'surgery' => 'Surgery', 'checkup' => 'Checkup', 'emergency' => 'Emergency',
        ],
        'valid_id_type' => [
            'school_id' => 'School ID', 'national_id' => 'National ID (PhilSys)', 'umid' => 'UMID',
            'drivers_license' => "Driver's License", 'postal_id' => 'Postal ID', 'philhealth' => 'PhilHealth',
            'passport' => 'Passport', 'other' => 'Other',
        ],
    ];

    public function up(): void
    {
        Schema::create('category_options', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            $table->string('value', 100);
            $table->string('label', 100);
            $table->unsignedInteger('sort_order')->default(0);
            // Hidden options leave the dropdowns but stay valid on the records that already use them.
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['type', 'value']);
            $table->index(['type', 'is_active', 'sort_order']);
        });

        $now = now();
        $rows = [];
        foreach (self::DEFAULTS as $type => $options) {
            $order = 0;
            foreach ($options as $key => $label) {
                $rows[] = [
                    'type' => $type,
                    'value' => is_int($key) ? $label : $key, // plain lists store the text itself
                    'label' => $label,
                    'sort_order' => ++$order,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        DB::table('category_options')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('category_options');
    }
};
