<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the shelter actually spent. Until now the only money leaving the system that was
     * recorded anywhere was `medical_records.cost`; the Transparency board's per-category
     * figures were an allocation *model* (see App\Support\DonationCategories::allocate) — where
     * donations were notionally destined, not where pesos went. This table is the other half:
     * one row per real outlay, categorised against the same keys donors give to, so donated and
     * spent can finally be compared side by side.
     */
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            // One of App\Support\DonationCategories::keys(). Deliberately the same vocabulary as
            // donations.category — a shared key is what makes "donated vs spent" a single join
            // rather than a mapping table nobody remembers to maintain.
            $table->string('category', 40);
            $table->decimal('amount', 10, 2);
            $table->string('description', 255);
            // date, not dateTime: an outlay is recorded to the day, and the Transparency board
            // buckets by month. Storing a time we never set would only invite timezone bugs.
            $table->date('spent_at');
            // Who entered it. nullOnDelete so removing a staff account never destroys the
            // financial record — the row outlives the person who typed it.
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            // Optional receipt scan. Lives on the same public disk as donation proofs: the
            // private-disk attempt was reverted in 56a68f3 because Render Free's filesystem is
            // ephemeral and the files vanished on sleep.
            $table->string('receipt_path')->nullable();
            $table->timestamps();

            // No animal_id here on purpose. Per-animal spend is already recorded as
            // `medical_records.cost` and merged into the expenses report at read time; a second
            // per-animal column would be a duplicate route to the same fact. Ledger rows are the
            // shelter-wide outlays the categories describe — food, rent, salaries, utilities.

            // The hot path is "this month, grouped by category" — the Transparency board runs it
            // on every public page load.
            $table->index(['spent_at', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
