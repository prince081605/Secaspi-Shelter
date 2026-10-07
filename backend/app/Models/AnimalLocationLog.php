<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One move of an animal between shelter areas: from, to, who, when, and how it was recorded. */
class AnimalLocationLog extends Model
{
    public const UPDATED_AT = null;

    public const SOURCES = ['qr_page', 'admin'];

    protected $fillable = ['animal_id', 'from_location_id', 'to_location_id', 'source', 'note'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * Put an animal in an area (or back to unassigned, with null) and log the move. The one way
     * current_location_id changes — the Animals panel, the QR page and the Excel import all come
     * through here, so no move goes unrecorded. Callers wrap it in a transaction.
     */
    public static function record(Animal $animal, ?int $toLocationId, ?User $by, string $source, ?string $note = null): self
    {
        $log = new self([
            'animal_id' => $animal->id,
            'from_location_id' => $animal->current_location_id,
            'to_location_id' => $toLocationId,
            'source' => $source,
            'note' => $note,
        ]);
        $log->forceFill(['moved_by' => $by?->id])->save();

        $animal->forceFill(['current_location_id' => $toLocationId])->save();

        return $log;
    }

    public function animal()
    {
        return $this->belongsTo(Animal::class, 'animal_id');
    }

    public function fromLocation()
    {
        return $this->belongsTo(ShelterLocation::class, 'from_location_id');
    }

    public function toLocation()
    {
        return $this->belongsTo(ShelterLocation::class, 'to_location_id');
    }

    public function mover()
    {
        return $this->belongsTo(User::class, 'moved_by');
    }
}
