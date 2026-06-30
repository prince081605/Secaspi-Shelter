<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Shared "mark this record read the first time an admin opens it" write. The read_at update was
 * duplicated verbatim across the adoption / rescue / visitation / volunteer-application admin
 * controllers (audit §0.2); only the write is centralized here — each controller still owns its
 * own response shape.
 */
trait MarksAdminRead
{
    protected function markReadOnce(Model $record): void
    {
        if (is_null($record->read_at)) {
            $record->update(['read_at' => now()]);
        }
    }
}
