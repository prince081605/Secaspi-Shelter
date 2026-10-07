<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * News an adopter sends about their adopted animal. Optionally offered as a public Happy Tails
 * story: `share_publicly` is the adopter's consent, `story_status` staff's review — it is only
 * shown publicly when both say yes.
 */
class AdoptionUpdate extends Model
{
    public const UPDATED_AT = null;

    /** How the animal is doing, in the adopter's own words. */
    public const WELLBEING = ['doing_well', 'some_concerns', 'need_help'];

    public const MAX_PHOTOS = 4;

    protected $fillable = [
        'adoption_application_id', 'animal_id', 'user_id', 'follow_up_id', 'wellbeing', 'message',
        'photo_paths', 'share_publicly', 'story_status', 'story_reviewed_by', 'story_reviewed_at', 'read_at',
    ];

    protected $casts = [
        'photo_paths' => 'array',
        'share_publicly' => 'boolean',
        'story_reviewed_at' => 'datetime',
        'read_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function application()
    {
        return $this->belongsTo(AdoptionApplication::class, 'adoption_application_id');
    }

    public function animal()
    {
        return $this->belongsTo(Animal::class, 'animal_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Public URLs of the update's photos. */
    public function photoUrls(): array
    {
        return array_map(fn ($path) => Storage::url($path), $this->photo_paths ?? []);
    }

    public function isPublished(): bool
    {
        return $this->share_publicly && $this->story_status === 'approved';
    }
}
