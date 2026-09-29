<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which gateway opened a session, and — for a hosted provider like PayMongo — the
     * provider's own id for it and the page the donor is sent to. Existing rows were all
     * opened by the simulated gateway, which the default records.
     */
    public function up(): void
    {
        Schema::table('payment_sessions', function (Blueprint $table) {
            $table->string('provider', 20)->default('simulated')->after('rail');
            // PayMongo's checkout session id (cs_…). The webhook names the session by this.
            $table->string('provider_ref', 100)->nullable()->unique()->after('provider');
            $table->string('checkout_url', 512)->nullable()->after('provider_ref');
        });
    }

    public function down(): void
    {
        Schema::table('payment_sessions', function (Blueprint $table) {
            $table->dropUnique(['provider_ref']);
            $table->dropColumn(['provider', 'provider_ref', 'checkout_url']);
        });
    }
};
