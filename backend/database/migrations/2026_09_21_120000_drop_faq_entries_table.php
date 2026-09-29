<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the FAQ knowledge-base table. It backed the shelter chat assistant's FAQ matcher, a
 * feature that has been removed; nothing else references `faq_entries` (no foreign keys point
 * at it). Dropping it here clears the table from existing deployments — fresh setups create it
 * (from the earlier migration) and then drop it here, ending with no table either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('faq_entries');
    }

    public function down(): void
    {
        // One-way: the chat assistant was removed, so there is nothing to restore. The table's
        // original schema remains in git history (the create_faq_entries_table migration) if it
        // is ever needed again.
    }
};
