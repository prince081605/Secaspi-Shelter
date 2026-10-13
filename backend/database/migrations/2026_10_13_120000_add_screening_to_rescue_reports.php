<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Handling spam and fake rescue reports (panel note: "what if a user sends many or fake
 * complaints"). Reports stay open to anyone, so each one now carries who sent it, as far as can
 * be told without an account:
 *
 * - `contact_hash` / `ip_hash`: keyed hashes of the contact number and the connection, never the
 *   raw IP. They let staff see a reporter's history and let the daily limit and blocking work.
 * - `user_id`: set when the reporter happened to be logged in.
 * - `possible_duplicate_of_id`: an open report close by in the last day, found when it was sent.
 * - `invalid_reason` / `invalid_note` / `duplicate_of_id`: staff closing a report as fake, a
 *   duplicate or "animal not found" (status 'invalid'), kept apart from real resolved rescues.
 *
 * `blocked_reporters` holds contact numbers (as hashes) and accounts whose reports are refused.
 * `label` is a masked number or the account name, so the list means something to an admin.
 * References are plain indexed ids, like the other recent tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rescue_reports', function (Blueprint $table) {
            $table->string('contact_hash', 64)->nullable()->index();
            $table->string('ip_hash', 64)->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('possible_duplicate_of_id')->nullable();
            $table->string('invalid_reason', 20)->nullable();
            $table->text('invalid_note')->nullable();
            $table->unsignedBigInteger('duplicate_of_id')->nullable();
        });

        Schema::create('blocked_reporters', function (Blueprint $table) {
            $table->id();
            $table->string('contact_hash', 64)->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('label', 150);
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('report_id')->nullable(); // the report it was blocked from
            $table->unsignedBigInteger('blocked_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_reporters');

        Schema::table('rescue_reports', function (Blueprint $table) {
            $table->dropIndex(['contact_hash']);
            $table->dropIndex(['ip_hash']);
            $table->dropIndex(['user_id']);
            $table->dropColumn([
                'contact_hash', 'ip_hash', 'user_id', 'possible_duplicate_of_id',
                'invalid_reason', 'invalid_note', 'duplicate_of_id',
            ]);
        });
    }
};
