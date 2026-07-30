<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per attempt to pay a donation through the checkout. This is the shape a real
     * gateway hands back (a session id, an amount snapshot, a status you poll), so when a live
     * provider replaces App\Services\SimulatedGateway the table keeps working as-is.
     */
    public function up(): void
    {
        Schema::create('payment_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donation_id')->constrained('donations')->cascadeOnDelete();
            // The secret in the /pay/{token} URL. Long and random: it is the only thing
            // standing between a guessed URL and someone else's checkout.
            $table->string('token', 64)->unique();
            // Which rail the donor picked, and therefore which checkout skin to render.
            $table->string('rail', 20);
            // Snapshotted at creation so the amount on the checkout page cannot drift if the
            // donation row is touched mid-payment. A real gateway does the same.
            $table->decimal('amount', 10, 2);
            // open | awaiting_otp | succeeded | failed | cancelled | expired
            $table->string('status', 20)->default('open');
            // insufficient_funds | declined | invalid_account | invalid_otp | expired
            $table->string('failure_code', 40)->nullable();
            // OTP tries burned so far; capped by config('payments.max_otp_attempts').
            $table->unsignedTinyInteger('attempts')->default(0);
            // dateTime, not timestamp: MySQL silently gives the first NOT NULL TIMESTAMP
            // column in a table `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`,
            // which would push the expiry forward on every state change and mean a
            // checkout link could never actually expire.
            $table->dateTime('expires_at');
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            // Finding the live session for a donation is the hot path (resume, retry, expiry).
            $table->index(['donation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_sessions');
    }
};
