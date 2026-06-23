<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foster_applications', function (Blueprint $table) {
            // Mirror the applicant detail fields collected for adoptions, so fostering
            // captures the same context about the prospective carer.
            $table->string('full_name', 150)->nullable()->after('status');
            $table->text('address')->nullable()->after('full_name');
            $table->string('occupation', 100)->nullable()->after('address');
            $table->string('housing_type', 100)->nullable()->after('occupation');
            $table->text('pet_experience')->nullable()->after('housing_type');
            $table->text('reason')->nullable()->after('pet_experience');
        });
    }

    public function down(): void
    {
        Schema::table('foster_applications', function (Blueprint $table) {
            $table->dropColumn(['full_name', 'address', 'occupation', 'housing_type', 'pet_experience', 'reason']);
        });
    }
};
