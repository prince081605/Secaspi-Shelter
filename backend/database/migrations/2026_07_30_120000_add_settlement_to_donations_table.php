<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            // How the money actually reached us. 'gateway' means the donor paid through the
            // AspinPay checkout and the donation settled itself; 'manual' means they transferred
            // on their own and a staff member has to eyeball the screenshot. Every row that
            // existed before the gateway was built is manual, which is what the default gives us.
            $table->string('settlement', 10)->default('manual')->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->dropColumn('settlement');
        });
    }
};
